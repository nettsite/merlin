<?php

use App\Modules\Billing\Services\BillingService;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\Party;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('components.layout.app')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $sortBy = 'issue_date';

    public string $sortDir = 'desc';

    // New payment modal
    public bool $showNewPaymentModal = false;

    public string $newPaymentClientId = '';

    public string $newPaymentDate = '';

    public string $newPaymentReference = '';

    /** @var array<string, array{selected: bool, amount: string}> */
    public array $newPaymentAllocations = [];

    public string $newPaymentError = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Document::class);
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }

        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    // -------------------------------------------------------------------------
    // New payment (one or more invoices)
    // -------------------------------------------------------------------------

    public function openNewPaymentModal(): void
    {
        $this->authorize('can-record-payments');

        $this->reset(['newPaymentClientId', 'newPaymentReference', 'newPaymentAllocations', 'newPaymentError']);
        $this->newPaymentDate = now()->toDateString();
        $this->showNewPaymentModal = true;
    }

    public function updatedNewPaymentClientId(): void
    {
        $this->newPaymentAllocations = $this->clientOpenInvoices()
            ->mapWithKeys(fn (Document $invoice): array => [
                $invoice->id => [
                    'selected' => false,
                    'amount' => number_format((float) $invoice->balance_due, 2, '.', ''),
                ],
            ])
            ->all();
    }

    /** @return \Illuminate\Support\Collection<int, Document> */
    private function clientOpenInvoices(): \Illuminate\Support\Collection
    {
        if ($this->newPaymentClientId === '') {
            return collect();
        }

        return Document::salesInvoices()
            ->joinBalance()
            ->where('documents.party_id', $this->newPaymentClientId)
            ->whereIn('document_balances.status', ['sent', 'partially_paid'])
            ->where('document_balances.balance_due', '>', 0)
            ->orderBy('documents.due_date')
            ->get();
    }

    public function submitNewPayment(): void
    {
        $this->authorize('can-record-payments');

        $this->validate([
            'newPaymentClientId' => 'required|uuid|exists:parties,id',
            'newPaymentDate' => 'required|date',
            'newPaymentReference' => 'nullable|string|max:255',
        ]);

        $selected = collect($this->newPaymentAllocations)->filter(fn (array $a): bool => $a['selected']);

        if ($selected->isEmpty()) {
            $this->newPaymentError = 'Select at least one invoice.';

            return;
        }

        $invoices = Document::whereIn('id', $selected->keys())->get()->keyBy('id');

        $allocations = $selected->map(fn (array $a, string $invoiceId): array => [
            'invoice' => $invoices[$invoiceId],
            'amount' => (float) $a['amount'],
        ])->values()->all();

        if (collect($allocations)->contains(fn (array $a): bool => $a['amount'] <= 0)) {
            $this->newPaymentError = 'Each selected invoice needs an amount greater than zero.';

            return;
        }

        try {
            app(BillingService::class)->recordPaymentForInvoices($allocations, [
                'date' => $this->newPaymentDate,
                'reference' => $this->newPaymentReference ?: null,
            ]);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            $this->newPaymentError = $e->getMessage();

            return;
        }

        $this->showNewPaymentModal = false;
        session()->flash('success', 'Payment recorded.');
    }

    /**
     * Open the receipt PDF inline. Regenerated on demand so it always
     * reflects the current invoice balances, same as the invoice "PDF" button.
     */
    public function viewReceipt(string $id): mixed
    {
        $payment = Document::findOrFail($id);
        $this->authorize('view', $payment);

        app(BillingService::class)->generateReceiptPdf($payment);
        $payment->refresh();

        $media = $payment->getFirstMedia('payment_receipt');

        if ($media === null) {
            session()->flash('error', 'Could not generate the receipt PDF.');

            return null;
        }

        return response()->file($media->getPath(), [
            'Content-Type' => $media->mime_type,
            'Content-Disposition' => 'inline; filename="'.$media->file_name.'"',
        ]);
    }

    public function sendReceipt(string $id): void
    {
        $this->authorize('can-send-sales-invoices');

        $payment = Document::findOrFail($id);

        app(BillingService::class)->sendReceipt($payment);

        session()->flash('success', "Receipt for {$payment->document_number} sent.");
    }

    public function with(): array
    {
        $rows = Document::query()
            ->where('document_type', 'payment')
            ->where('direction', 'inbound')
            ->with('party.business')
            ->withExists(['activities as receipt_sent' => fn ($q) => $q->where('activity_type', 'receipt_sent')])
            ->when(
                $this->search,
                fn ($q) => $q->where(function ($q): void {
                    $q->where('documents.document_number', 'like', "%{$this->search}%")
                        ->orWhere('documents.reference', 'like', "%{$this->search}%")
                        ->orWhereHas('party.business', fn ($q) => $q->where('trading_name', 'like', "%{$this->search}%"));
                })
            )
            ->when(
                $this->sortBy === 'client',
                fn ($q) => $q
                    ->leftJoin('businesses', 'businesses.id', '=', 'documents.party_id')
                    ->orderBy('businesses.trading_name', $this->sortDir)
                    ->select('documents.*'),
                fn ($q) => $q->orderBy("documents.{$this->sortBy}", $this->sortDir)->orderBy('documents.created_at', 'desc')
            )
            ->paginate(25);

        return [
            'rows' => $rows,
            'clients' => $this->showNewPaymentModal ? Party::clients()->with('business')->get() : collect(),
            'clientInvoices' => $this->showNewPaymentModal ? $this->clientOpenInvoices() : collect(),
        ];
    }
}; ?>

<div>
@if(session('success'))
    <div class="mx-6 mt-4 px-4 py-3 rounded bg-green-50 border border-green-200 text-sm text-success">
        {{ session('success') }}
    </div>
@endif
@if(session('error'))
    <div class="mx-6 mt-4 px-4 py-3 rounded bg-red-50 border border-red-200 text-sm text-danger">
        {{ session('error') }}
    </div>
@endif

<x-crud.table title="Payments" description="Client payments received and their receipts">
    <x-slot name="actions">
        @can('can-record-payments')
            <flux:button wire:click="openNewPaymentModal" icon="plus" size="sm" variant="primary">
                New Payment
            </flux:button>
        @endcan
    </x-slot>

    <table class="w-full text-sm">
        <thead>
            <tr>
                <x-crud.th column="document_number" :sort-by="$sortBy" :sort-dir="$sortDir">Number</x-crud.th>
                <x-crud.th column="client" :sort-by="$sortBy" :sort-dir="$sortDir">Client</x-crud.th>
                <x-crud.th column="issue_date" :sort-by="$sortBy" :sort-dir="$sortDir">Date</x-crud.th>
                <x-crud.th column="total" :sort-by="$sortBy" :sort-dir="$sortDir">Amount</x-crud.th>
                <x-crud.th></x-crud.th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $payment)
                <tr class="border-t border-line hover:bg-surface-alt group">
                    <td class="px-4 py-3 font-medium text-ink">{{ $payment->document_number }}</td>
                    <td class="px-4 py-3 text-ink-soft">{{ $payment->party?->displayName ?? '—' }}</td>
                    <td class="px-4 py-3 text-ink-soft">{{ $payment->issue_date?->format('d M Y') }}</td>
                    <td class="px-4 py-3 text-right tabular-nums font-medium text-ink">
                        {{ $payment->currency }} {{ number_format((float) $payment->total, 2) }}
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            <flux:button wire:click="viewReceipt('{{ $payment->id }}')" size="sm" variant="ghost" icon="eye">View</flux:button>
                            @can('can-send-sales-invoices')
                                <flux:button wire:click="sendReceipt('{{ $payment->id }}')" size="sm" variant="ghost" icon="paper-airplane">
                                    {{ $payment->receipt_sent ? 'Resend' : 'Send' }}
                                </flux:button>
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-12 text-center">
                        <p class="font-medium text-ink">No payments yet.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <x-slot name="pagination">
        {{ $rows->links() }}
    </x-slot>
</x-crud.table>

{{-- ===== New Payment Modal ===== --}}
<flux:modal name="new-payment" wire:model.self="showNewPaymentModal" class="w-[640px]">
    <div class="space-y-5">
        <div>
            <flux:heading size="lg">New Payment</flux:heading>
            <flux:subheading>Record a payment and apply it across one or more of the client's open invoices.</flux:subheading>
        </div>

        @if($newPaymentError)
            <div class="px-4 py-3 rounded bg-red-50 border border-red-200 text-sm text-danger">
                {{ $newPaymentError }}
            </div>
        @endif

        <flux:field>
            <flux:label>Client</flux:label>
            <flux:select wire:model.live="newPaymentClientId">
                <flux:select.option value="">Select client…</flux:select.option>
                @foreach($clients as $client)
                    <flux:select.option value="{{ $client->id }}">{{ $client->displayName }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:error name="newPaymentClientId" />
        </flux:field>

        @if($newPaymentClientId)
            <div>
                <p class="text-xs font-medium text-ink-muted uppercase tracking-wide mb-2">Open invoices</p>
                @if($clientInvoices->isEmpty())
                    <p class="text-sm text-ink-muted">No open invoices for this client.</p>
                @else
                    <div class="border border-line rounded-md divide-y divide-line">
                        @foreach($clientInvoices as $invoice)
                            <div class="flex items-center gap-3 px-3 py-2">
                                <div class="shrink-0">
                                    <flux:checkbox wire:model="newPaymentAllocations.{{ $invoice->id }}.selected" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-ink">{{ $invoice->document_number }}</p>
                                    <p class="text-xs text-ink-muted">
                                        Due {{ $invoice->due_date?->format('d M Y') }} · Balance {{ $invoice->currency }} {{ number_format((float) $invoice->balance_due, 2) }}
                                    </p>
                                </div>
                                <div class="w-28 shrink-0">
                                    <flux:input
                                        type="number"
                                        step="0.01"
                                        min="0.01"
                                        wire:model="newPaymentAllocations.{{ $invoice->id }}.amount"
                                    />
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Date</flux:label>
                    <flux:input type="date" wire:model="newPaymentDate" />
                    <flux:error name="newPaymentDate" />
                </flux:field>

                <flux:field>
                    <flux:label>Reference</flux:label>
                    <flux:input wire:model="newPaymentReference" placeholder="Bank reference, cheque number, etc." />
                    <flux:error name="newPaymentReference" />
                </flux:field>
            </div>
        @endif

        <div class="flex justify-end gap-3">
            <flux:button variant="ghost" wire:click="$set('showNewPaymentModal', false)">Cancel</flux:button>
            <flux:button variant="primary" wire:click="submitNewPayment">Record Payment</flux:button>
        </div>
    </div>
</flux:modal>
</div>
