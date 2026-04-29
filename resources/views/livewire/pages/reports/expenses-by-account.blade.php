<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\Models\DocumentLine;
use Illuminate\Support\Carbon;
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

        $rows = DocumentLine::query()
            ->join('documents', 'document_lines.document_id', '=', 'documents.id')
            ->join('accounts', 'accounts.id', '=', 'document_lines.account_id')
            ->selectRaw('
                document_lines.account_id,
                accounts.code as account_code,
                accounts.name as account_name,
                COUNT(DISTINCT document_lines.document_id) as invoice_count,
                SUM(document_lines.line_total) as total_excl,
                SUM(document_lines.tax_amount) as total_vat,
                SUM(document_lines.line_total + COALESCE(document_lines.tax_amount, 0)) as total_incl
            ')
            ->where('documents.document_type', 'purchase_invoice')
            ->where('documents.status', 'posted')
            ->whereNotNull('document_lines.account_id')
            ->when($this->dateFrom, fn ($q) => $q->where('documents.issue_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->where('documents.issue_date', '<=', $this->dateTo))
            ->groupBy('document_lines.account_id', 'accounts.code', 'accounts.name')
            ->orderByDesc('total_excl')
            ->get();

        return [
            'rows' => $rows,
            'currency' => $settings->base_currency,
            'locale' => $settings->locale,
            'grandTotalExcl' => $rows->sum('total_excl'),
            'grandTotalVat' => $rows->sum('total_vat'),
            'grandTotalIncl' => $rows->sum('total_incl'),
        ];
    }
}; ?>

<div>
@include('livewire.pages.reports._subnav')
<div class="flex items-start justify-between px-6 py-5 border-b border-line">
    <div>
        <h1 class="text-[17px] font-semibold tracking-tight text-ink">Expenses by Account</h1>
        <p class="mt-0.5 text-sm text-ink-muted">Posted purchase invoices grouped by GL account</p>
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
                <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Account</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Invoices</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Excl. VAT</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">VAT</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Incl. VAT</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr class="border-t border-line hover:bg-surface-alt">
                    <td class="px-4 py-3 text-ink">
                        <span class="font-mono text-xs text-ink-muted mr-2">{{ $row->account_code }}</span>
                        {{ $row->account_name }}
                    </td>
                    <td class="px-4 py-3 text-right text-ink-soft tabular-nums">{{ number_format($row->invoice_count) }}</td>
                    <td class="px-4 py-3 text-right text-ink tabular-nums">
                        {{ number_format($row->total_excl, 2) }}
                    </td>
                    <td class="px-4 py-3 text-right text-ink-soft tabular-nums">
                        {{ number_format($row->total_vat, 2) }}
                    </td>
                    <td class="px-4 py-3 text-right font-medium text-ink tabular-nums">
                        {{ number_format($row->total_incl, 2) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="px-4 py-12 text-center">
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
                </tr>
            </tfoot>
        @endif
    </table>
</div>
</div>
