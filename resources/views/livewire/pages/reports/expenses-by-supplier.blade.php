<?php

use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\Models\Document;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layout.app')] class extends Component
{
    public string $dateFrom = '';

    public string $dateTo = '';

    public function mount(): void
    {
        $this->dateFrom = now()->startOfYear()->format('Y-m-d');
        $this->dateTo = now()->format('Y-m-d');
    }

    public function with(): array
    {
        $settings = app(CurrencySettings::class);

        $rows = Document::query()
            ->join('parties', 'parties.id', '=', 'documents.party_id')
            ->join('businesses', 'businesses.id', '=', 'parties.id')
            ->selectRaw('
                documents.party_id,
                businesses.trading_name,
                businesses.legal_name,
                COUNT(*) as invoice_count,
                SUM(documents.subtotal) as total_excl,
                SUM(documents.tax_total) as total_vat,
                SUM(documents.total) as total_incl,
                SUM(documents.balance_due) as outstanding
            ')
            ->where('documents.document_type', 'purchase_invoice')
            ->where('documents.status', 'posted')
            ->when($this->dateFrom, fn ($q) => $q->where('documents.issue_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where('documents.issue_date', '<=', $this->dateTo))
            ->groupBy('documents.party_id', 'businesses.trading_name', 'businesses.legal_name')
            ->orderByDesc('total_excl')
            ->get();

        return [
            'rows' => $rows,
            'currency' => $settings->base_currency,
            'grandTotalExcl' => $rows->sum('total_excl'),
            'grandTotalVat' => $rows->sum('total_vat'),
            'grandTotalIncl' => $rows->sum('total_incl'),
            'grandOutstanding' => $rows->sum('outstanding'),
        ];
    }
}; ?>

<div>
@include('livewire.pages.reports._subnav')
<div class="flex items-start justify-between px-6 py-5 border-b border-line">
    <div>
        <h1 class="text-[17px] font-semibold tracking-tight text-ink">Expenses by Supplier</h1>
        <p class="mt-0.5 text-sm text-ink-muted">Posted purchase invoices grouped by supplier</p>
    </div>
    <div class="flex items-center gap-3">
        <flux:input wire:model.live="dateFrom" type="date" size="sm" label="From" />
        <flux:input wire:model.live="dateTo" type="date" size="sm" label="To" />
    </div>
</div>

<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-surface-alt border-b border-line">
                <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Supplier</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Invoices</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Excl. VAT</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">VAT</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Incl. VAT</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Outstanding</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr class="border-t border-line hover:bg-surface-alt">
                    <td class="px-4 py-3 font-medium text-ink">
                        {{ $row->trading_name ?: $row->legal_name }}
                    </td>
                    <td class="px-4 py-3 text-right text-ink-soft tabular-nums">{{ number_format($row->invoice_count) }}</td>
                    <td class="px-4 py-3 text-right text-ink tabular-nums">{{ number_format($row->total_excl, 2) }}</td>
                    <td class="px-4 py-3 text-right text-ink-soft tabular-nums">{{ number_format($row->total_vat, 2) }}</td>
                    <td class="px-4 py-3 text-right font-medium text-ink tabular-nums">{{ number_format($row->total_incl, 2) }}</td>
                    <td class="px-4 py-3 text-right tabular-nums">
                        <span @class([
                            'font-medium',
                            'text-danger' => $row->outstanding > 0,
                            'text-ink-soft' => $row->outstanding <= 0,
                        ])>
                            {{ number_format($row->outstanding, 2) }}
                        </span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-12 text-center">
                        <p class="font-medium text-ink">No data for this period.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if($rows->isNotEmpty())
            <tfoot>
                <tr class="border-t-2 border-line bg-surface-alt font-semibold">
                    <td class="px-4 py-3 text-ink">Total</td>
                    <td></td>
                    <td class="px-4 py-3 text-right text-ink tabular-nums">{{ number_format($grandTotalExcl, 2) }}</td>
                    <td class="px-4 py-3 text-right text-ink tabular-nums">{{ number_format($grandTotalVat, 2) }}</td>
                    <td class="px-4 py-3 text-right text-ink tabular-nums">{{ number_format($grandTotalIncl, 2) }}</td>
                    <td class="px-4 py-3 text-right text-ink tabular-nums">{{ number_format($grandOutstanding, 2) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>
</div>
</div>
