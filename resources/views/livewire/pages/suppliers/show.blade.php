<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\PaymentTerm;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\Document;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('components.layout.app')] class extends Component
{
    use WithPagination;

    public string $partyId = '';

    public string $clientPaymentTermId = '';

    public bool $showAddClientForm = false;

    public string $invoiceStatus = '';
    public string $invoiceSortBy = 'issue_date';
    public string $invoiceSortDir = 'desc';

    public function mount(string $id): void
    {
        $party = Party::with('relationships')->findOrFail($id);
        $this->authorize('view', $party);
        $this->partyId = $id;

        $clientRel = $party->relationships->firstWhere('relationship_type', 'client');
        $this->clientPaymentTermId = $clientRel?->payment_term_id ?? '';
    }

    public function updatedInvoiceStatus(): void
    {
        $this->resetPage('invoicePage');
    }

    // Reuse x-crud.th which calls sort()
    public function sort(string $column): void
    {
        if ($this->invoiceSortBy === $column) {
            $this->invoiceSortDir = $this->invoiceSortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->invoiceSortBy = $column;
            $this->invoiceSortDir = 'asc';
        }

        $this->resetPage('invoicePage');
    }

    public function openEditForm(): void
    {
        $this->dispatch('open-supplier-form', partyId: $this->partyId);
    }

    #[On('supplier-saved')]
    public function refreshAfterSupplierSaved(): void
    {
        // No-op: with() below re-queries on every render, this just forces one.
    }

    public function addClientRelationship(): void
    {
        $this->validate(['clientPaymentTermId' => 'nullable|uuid|exists:payment_terms,id']);
        $party = Party::findOrFail($this->partyId);
        $this->authorize('update', $party);

        $rel = $party->relationships()->firstOrCreate(
            ['relationship_type' => 'client'],
            ['is_active' => true],
        );
        $rel->mergeMetadata(['payment_term_id' => $this->clientPaymentTermId ?: null]);
        $this->showAddClientForm = false;
    }

    public function removeClientRelationship(): void
    {
        $party = Party::findOrFail($this->partyId);
        $this->authorize('update', $party);
        $party->relationships()->where('relationship_type', 'client')->first()?->delete();
        $this->clientPaymentTermId = '';
    }

    public function approveSupplier(): void
    {
        $party = Party::findOrFail($this->partyId);
        $this->authorize('update', $party);
        $party->update(['status' => 'active']);
    }

    public function deactivateSupplier(): void
    {
        $party = Party::findOrFail($this->partyId);
        $this->authorize('update', $party);
        $party->update(['status' => 'inactive']);
    }

    public function with(): array
    {
        $party = Party::with(['business', 'relationships'])->findOrFail($this->partyId);
        $supplierRel = $party->relationships->firstWhere('relationship_type', 'supplier');
        $clientRel = $party->relationships->firstWhere('relationship_type', 'client');

        $invoices = Document::purchaseInvoices()
            ->forParty($party)
            ->when($this->invoiceStatus, fn ($q) => $q->withStatus($this->invoiceStatus))
            ->orderBy($this->invoiceSortBy, $this->invoiceSortDir)
            ->paginate(15, pageName: 'invoicePage');

        $statusCounts = Document::purchaseInvoices()
            ->forParty($party)
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();

        return [
            'party' => $party,
            'supplierRel' => $supplierRel,
            'clientRel' => $clientRel,
            'invoices' => $invoices,
            'statusCounts' => $statusCounts,
            'liabilityAccounts' => Account::postable()->active()
                ->whereHas('group.type', fn ($q) => $q->where('code', '2'))
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'paymentTerms' => PaymentTerm::orderBy('name')->get(['id', 'name']),
        ];
    }
}; ?>

<div>
    {{-- Back link + header --}}
    <div class="border-b border-line px-6 py-5">
        <a href="{{ route('suppliers.index') }}" wire:navigate class="mb-3 flex items-center gap-1 text-sm text-ink-muted hover:text-ink">
            <flux:icon.arrow-left class="size-4" />
            Suppliers
        </a>
        <div class="flex items-start justify-between gap-4">
            <div class="flex items-center gap-3">
                <h1 class="text-[17px] font-semibold tracking-tight text-ink">
                    {{ $party->business?->display_name ?? '—' }}
                </h1>
                <span @class([
                    'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
                    'bg-green-50 text-success' => $party->status === 'active',
                    'bg-yellow-50 text-warning' => $party->status === 'pending',
                    'bg-surface-alt text-ink-muted' => $party->status === 'inactive',
                ])>
                    {{ ucfirst($party->status) }}
                </span>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                @if(in_array($party->status, ['pending', 'inactive']))
                    @can('update', $party)
                        <flux:button wire:click="approveSupplier" size="sm" variant="ghost" icon="check">Activate</flux:button>
                    @endcan
                @endif
                @if(in_array($party->status, ['active', 'pending']))
                    @can('update', $party)
                        <flux:button wire:click="deactivateSupplier" size="sm" variant="ghost" icon="x-mark">Deactivate</flux:button>
                    @endcan
                @endif
                @can('update', $party)
                    <flux:button wire:click="openEditForm" size="sm" icon="pencil">Edit</flux:button>
                @endcan
            </div>
        </div>
    </div>

    {{-- Supplier info --}}
    <div class="grid grid-cols-2 gap-x-8 gap-y-4 border-b border-line px-6 py-5 sm:grid-cols-3 lg:grid-cols-4">
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-ink-muted">Legal Name</p>
            <p class="mt-1 text-sm text-ink">{{ $party->business?->legal_name ?? '—' }}</p>
        </div>
        @if($party->business?->trading_name && $party->business->trading_name !== $party->business->legal_name)
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-ink-muted">Trading Name</p>
                <p class="mt-1 text-sm text-ink">{{ $party->business->trading_name }}</p>
            </div>
        @endif
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-ink-muted">Email</p>
            <p class="mt-1 text-sm text-ink">{{ $party->primary_email ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-ink-muted">Phone</p>
            <p class="mt-1 text-sm text-ink">{{ $party->primary_phone ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-ink-muted">Payment Terms</p>
            <p class="mt-1 text-sm text-ink">
                {{ $paymentTerms->firstWhere('id', $supplierRel?->payment_term_id)?->name ?? '—' }}
            </p>
        </div>
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-ink-muted">Default Payable Account</p>
            <p class="mt-1 text-sm text-ink">
                @php $account = $liabilityAccounts->firstWhere('id', $supplierRel?->default_payable_account_id) @endphp
                {{ $account ? "{$account->code} — {$account->name}" : '—' }}
            </p>
        </div>
        @if($party->notes)
            <div class="col-span-2 sm:col-span-3 lg:col-span-4">
                <p class="text-xs font-medium uppercase tracking-wide text-ink-muted">Notes</p>
                <p class="mt-1 text-sm text-ink">{{ $party->notes }}</p>
            </div>
        @endif
    </div>

    {{-- Client relationship section --}}
    <div class="border-b border-line px-6 py-5">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-sm font-semibold text-ink">Also a Client</h2>
                <p class="mt-0.5 text-xs text-ink-muted">This party can also be invoiced as a client.</p>
            </div>
            @can('update', $party)
                @if($clientRel === null && ! $showAddClientForm)
                    <flux:button wire:click="$set('showAddClientForm', true)" size="sm" variant="ghost" icon="plus">
                        Add as Client
                    </flux:button>
                @endif
            @endcan
        </div>

        @if($clientRel !== null)
            <div class="mt-3 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium bg-blue-50 text-blue-600">Client</span>
                    <span class="text-sm text-ink-soft">
                        {{ $paymentTerms->firstWhere('id', $clientRel->payment_term_id)?->name ?? 'Default payment terms' }}
                    </span>
                </div>
                @can('update', $party)
                    <flux:button
                        wire:click="removeClientRelationship"
                        wire:confirm="Remove client relationship for this party?"
                        size="sm" variant="ghost" icon="trash"
                        class="text-danger hover:text-danger"
                    />
                @endcan
            </div>
        @elseif($showAddClientForm)
            <div class="mt-3 space-y-3 rounded-lg border border-line bg-surface-alt p-3">
                <flux:field>
                    <flux:label>Payment Terms</flux:label>
                    <flux:select wire:model="clientPaymentTermId">
                        <option value="">— Use system default —</option>
                        @foreach($paymentTerms as $term)
                            <option value="{{ $term->id }}">{{ $term->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="clientPaymentTermId" />
                </flux:field>
                <div class="flex items-center gap-2">
                    <flux:button wire:click="addClientRelationship" size="sm" variant="primary">Add as Client</flux:button>
                    <flux:button wire:click="$set('showAddClientForm', false)" size="sm" variant="ghost">Cancel</flux:button>
                </div>
            </div>
        @endif
    </div>

    {{-- Invoices section --}}
    <div>
        <div class="flex items-center gap-1 overflow-x-auto border-b border-line px-6 pt-4">
            @php
                $tabs = [
                    '' => 'All',
                    'received' => 'Received',
                    'reviewed' => 'Reviewed',
                    'approved' => 'Approved',
                    'posted' => 'Posted',
                    'disputed' => 'Disputed',
                    'rejected' => 'Rejected',
                    'failed' => 'Failed',
                ];
            @endphp
            @foreach($tabs as $status => $label)
                <button
                    wire:click="$set('invoiceStatus', '{{ $status }}')"
                    @class([
                        'px-3 py-2 text-sm font-medium border-b-2 whitespace-nowrap transition-colors',
                        'border-primary text-primary' => $invoiceStatus === $status,
                        'border-transparent text-ink-soft hover:text-ink' => $invoiceStatus !== $status,
                    ])
                >
                    {{ $label }}
                    @if($status !== '' && ($statusCounts[$status] ?? 0) > 0)
                        <span class="ml-1 text-xs text-ink-muted">({{ $statusCounts[$status] }})</span>
                    @endif
                </button>
            @endforeach
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <x-crud.th column="document_number" :sort-by="$invoiceSortBy" :sort-dir="$invoiceSortDir">Doc #</x-crud.th>
                        <x-crud.th column="issue_date" :sort-by="$invoiceSortBy" :sort-dir="$invoiceSortDir">Issued</x-crud.th>
                        <x-crud.th column="due_date" :sort-by="$invoiceSortBy" :sort-dir="$invoiceSortDir">Due</x-crud.th>
                        <x-crud.th column="total" :sort-by="$invoiceSortBy" :sort-dir="$invoiceSortDir" :right="true">Total</x-crud.th>
                        <x-crud.th column="balance_due" :sort-by="$invoiceSortBy" :sort-dir="$invoiceSortDir" :right="true">Balance Due</x-crud.th>
                        <x-crud.th>Status</x-crud.th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($invoices as $invoice)
                        <tr class="border-t border-line hover:bg-surface-alt">
                            <td class="px-4 py-3 font-medium text-ink">
                                <a href="{{ route('purchase-invoices.index') }}" wire:navigate class="hover:text-accent hover:underline">
                                    {{ $invoice->document_number ?? '—' }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-ink-soft tabular-nums">
                                {{ $invoice->issue_date?->format('d M Y') ?? '—' }}
                            </td>
                            <td class="px-4 py-3 tabular-nums @if($invoice->is_overdue) text-danger @else text-ink-soft @endif">
                                {{ $invoice->due_date?->format('d M Y') ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums text-ink-soft">
                                {{ number_format((float) $invoice->total, 2) }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums">
                                @if((float) $invoice->balance_due > 0)
                                    <span @class(['font-medium', 'text-danger' => $invoice->is_overdue, 'text-ink' => ! $invoice->is_overdue])>
                                        {{ number_format((float) $invoice->balance_due, 2) }}
                                    </span>
                                @else
                                    <span class="text-ink-muted">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @include('livewire.pages.purchase-invoices._status-badge', ['status' => $invoice->status])
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-10 text-center text-sm text-ink-muted">
                                No invoices found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($invoices->hasPages())
            <div class="border-t border-line px-6 py-4">
                {{ $invoices->links() }}
            </div>
        @endif
    </div>

    <livewire:suppliers.form-modal />
</div>
