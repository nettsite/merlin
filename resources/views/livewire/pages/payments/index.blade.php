<?php

use App\Modules\Billing\Services\BillingService;
use App\Modules\Core\Models\Document;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('components.layout.app')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $sortBy = 'issue_date';

    public string $sortDir = 'desc';

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

        return ['rows' => $rows];
    }
}; ?>

<div>
<x-crud.table title="Payments" description="Client payments received and their receipts">
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
</div>
