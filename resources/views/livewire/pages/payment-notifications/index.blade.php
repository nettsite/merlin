<?php

use App\Modules\Core\Models\Document;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\Services\ExchangeRateService;
use App\Modules\Purchasing\Services\PaymentNotificationMatcher;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('components.layout.app')] class extends Component
{
    use WithPagination;

    public string $search = '';

    // Manual linking
    public bool $showLinkModal = false;

    public ?string $linkingPaymentNotificationId = null;

    public string $linkInvoiceId = '';

    public string $linkInvoiceSearch = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Document::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function openLinkModal(string $paymentNotificationId): void
    {
        $this->authorize('create', Document::class);
        $this->linkingPaymentNotificationId = $paymentNotificationId;
        $this->linkInvoiceId = '';
        $this->linkInvoiceSearch = '';
        $this->showLinkModal = true;
    }

    public function selectLinkInvoice(string $invoiceId): void
    {
        $invoice = Document::purchaseInvoices()->with('party.business')->findOrFail($invoiceId);
        $this->linkInvoiceId = $invoiceId;
        $this->linkInvoiceSearch = $invoice->document_number.' — '.($invoice->party?->business?->display_name ?? 'No supplier');
    }

    public function confirmLink(): void
    {
        $this->validate(['linkInvoiceId' => 'required|exists:documents,id']);

        $paymentNotification = Document::where('document_type', 'payment_notification')->findOrFail($this->linkingPaymentNotificationId);
        $invoice = Document::purchaseInvoices()->findOrFail($this->linkInvoiceId);
        $this->authorize('update', $invoice);

        app(PaymentNotificationMatcher::class)->merge($invoice, $paymentNotification, 1.0, 'Manually linked by user.');

        $this->showLinkModal = false;
        $this->linkingPaymentNotificationId = null;
        session()->flash('success', 'Payment notification linked to invoice.');
    }

    public function cancelLink(): void
    {
        $this->showLinkModal = false;
        $this->linkingPaymentNotificationId = null;
    }

    public function with(): array
    {
        $notifications = Document::where('document_type', 'payment_notification')
            ->where('status', 'received')
            ->when($this->search, fn ($q) => $q->where(function ($q): void {
                $q->where('reference', 'like', "%{$this->search}%")
                    ->orWhere('metadata->payee_name', 'like', "%{$this->search}%")
                    ->orWhere('metadata->method', 'like', "%{$this->search}%");
            }))
            ->with('media')
            ->latest('issue_date')
            ->paginate(25);

        // Only computed while the link modal is open — searched/scoped rather
        // than loading the full invoice list, since a backlog can run into
        // the hundreds. Ordered by supplier name, the primary way suppliers
        // are found here.
        $linkableInvoices = collect();

        if ($this->showLinkModal) {
            $term = $this->linkInvoiceSearch;

            $linkableInvoices = Document::purchaseInvoices()
                ->leftJoin('parties', 'parties.id', '=', 'documents.party_id')
                ->leftJoin('businesses', 'businesses.id', '=', 'parties.id')
                ->when($term, fn ($q) => $q->where(function ($q) use ($term): void {
                    $q->where('businesses.trading_name', 'like', "%{$term}%")
                        ->orWhere('businesses.legal_name', 'like', "%{$term}%")
                        ->orWhere('documents.document_number', 'like', "%{$term}%")
                        ->orWhere('documents.reference', 'like', "%{$term}%");
                }))
                ->select('documents.*')
                ->with('party.business')
                ->orderBy('businesses.trading_name')
                ->limit(20)
                ->get();
        }

        return [
            'notifications' => $notifications,
            'linkableInvoices' => $linkableInvoices,
            'baseCurrencySymbol' => ExchangeRateService::currencySymbol(app(CurrencySettings::class)->base_currency),
        ];
    }
}; ?>

<div>
<x-crud.table title="Unmatched Payments" description="Payment notifications (bank advices, PayPal/Payfast receipts) awaiting a matching invoice">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-t border-line">
                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-ink-muted">Payee</th>
                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-ink-muted">Reference</th>
                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-ink-muted">Method</th>
                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-ink-muted">Date</th>
                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-ink-muted">Amount</th>
                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-ink-muted">Suggested match</th>
                <th class="px-4 py-3 w-24"></th>
            </tr>
        </thead>
        <tbody>
            @forelse($notifications as $notification)
                <tr class="border-t border-line hover:bg-surface-alt">
                    <td class="px-4 py-3 font-medium text-ink">{{ $notification->metadata['payee_name'] ?? 'Unknown payee' }}</td>
                    <td class="px-4 py-3 text-ink-soft">{{ $notification->reference ?? '—' }}</td>
                    <td class="px-4 py-3 text-ink-soft">{{ $notification->metadata['method'] ?? '—' }}</td>
                    <td class="px-4 py-3 text-ink-soft">{{ $notification->issue_date?->format('d M Y') ?? '—' }}</td>
                    <td class="px-4 py-3 text-right tabular-nums font-medium text-ink">
                        {{ $notification->currency }} {{ number_format((float) $notification->total, 2) }}
                    </td>
                    <td class="px-4 py-3 text-ink-soft">
                        @if($notification->metadata['suggested_invoice_id'] ?? null)
                            <span class="text-xs">
                                {{ number_format(((float) ($notification->metadata['match_confidence'] ?? 0)) * 100, 0) }}% match
                            </span>
                        @else
                            <span class="text-ink-muted">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-1.5">
                            @php $sourceMedia = $notification->getFirstMedia('source_document'); @endphp
                            @if($sourceMedia)
                                <a
                                    href="{{ route('documents.media', $sourceMedia) }}"
                                    target="_blank"
                                    rel="noopener"
                                    title="View original document"
                                    class="inline-flex items-center justify-center rounded p-1 text-ink-muted hover:text-ink hover:bg-surface-alt transition-colors"
                                >
                                    <flux:icon.document-text class="size-4" />
                                </a>
                            @endif
                            <flux:button wire:click="openLinkModal('{{ $notification->id }}')" size="xs" variant="ghost">
                                Link
                            </flux:button>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-12 text-center">
                        <p class="font-medium text-ink">No unmatched payments.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <x-slot name="pagination">
        {{ $notifications->links() }}
    </x-slot>
</x-crud.table>

{{-- Link to invoice modal --}}
<flux:modal name="link-payment-notification-modal" wire:model.self="showLinkModal" class="w-[640px]">
    <form wire:submit="confirmLink" class="p-6 space-y-4">
        <flux:heading>Link Payment to Invoice</flux:heading>
        <p class="text-sm text-ink-soft">Choose the purchase invoice this payment settles.</p>

        <flux:field>
            <flux:label>Invoice <span class="text-danger">*</span></flux:label>
            <div x-data="{ open: false }" class="relative">
                <flux:input
                    wire:model.live.debounce.300ms="linkInvoiceSearch"
                    placeholder="Search by supplier, invoice number or reference…"
                    x-on:focus="open = true"
                    x-on:input="open = true"
                    autocomplete="off"
                />
                <div
                    x-show="open"
                    x-on:click.outside="open = false"
                    x-cloak
                    class="absolute z-10 mt-1 w-full max-h-72 overflow-y-auto rounded-lg border border-line bg-white dark:bg-zinc-800 shadow-lg"
                >
                    @forelse($linkableInvoices as $invoice)
                        <button
                            type="button"
                            wire:click="selectLinkInvoice('{{ $invoice->id }}')"
                            x-on:click="open = false"
                            class="block w-full text-left px-3 py-2 text-sm hover:bg-surface-alt border-b border-line last:border-b-0"
                        >
                            <div class="flex items-center justify-between gap-3">
                                <span class="font-medium text-ink">{{ $invoice->document_number }}</span>
                                <span class="text-xs text-ink-muted">{{ $invoice->issue_date?->format('d M Y') ?? '—' }}</span>
                            </div>
                            <div class="flex items-center justify-between gap-3 mt-0.5">
                                <span class="text-ink-soft">{{ $invoice->party?->business?->display_name ?? 'No supplier' }}</span>
                                <span class="text-ink-muted tabular-nums">
                                    {{ $baseCurrencySymbol }}{{ number_format((float) $invoice->total, 2) }}
                                    @if($invoice->is_foreign_currency && $invoice->foreign_total !== null)
                                        <span class="ml-1">({{ ExchangeRateService::currencySymbol($invoice->currency) }}{{ number_format((float) $invoice->foreign_total, 2) }})</span>
                                    @endif
                                </span>
                            </div>
                        </button>
                    @empty
                        <p class="px-3 py-2 text-sm text-ink-muted">No matching invoices.</p>
                    @endforelse
                </div>
            </div>
            <flux:error name="linkInvoiceId" />
        </flux:field>

        <div class="flex justify-end gap-3">
            <flux:button type="button" variant="ghost" wire:click="cancelLink">Cancel</flux:button>
            <flux:button type="submit" variant="primary">Link</flux:button>
        </div>
    </form>
</flux:modal>
</div>
