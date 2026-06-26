<?php

use App\Modules\Accounting\Services\FinancialYearService;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Core\Models\DocumentLine;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layout.app')] class extends Component
{
    public function with(): array
    {
        $fyService = app(FinancialYearService::class);
        $settings = app(CurrencySettings::class);

        [$fyStart] = $fyService->yearBounds($fyService->currentYearLabel());
        $monthStart = now()->startOfMonth();
        $today = now();

        $fetchLines = function (string $docType, array $statuses, bool $useWhereIn, string $typeCode, $from, $to): \Illuminate\Support\Collection {
            $q = DocumentLine::query()
                ->join('documents', 'documents.id', '=', 'document_lines.document_id')
                ->join('accounts', 'accounts.id', '=', 'document_lines.account_id')
                ->join('account_groups', 'account_groups.id', '=', 'accounts.account_group_id')
                ->join('account_types', 'account_types.id', '=', 'account_groups.account_type_id')
                ->selectRaw('
                    account_groups.name as group_name,
                    account_groups.sort_order as group_sort,
                    accounts.id as account_id,
                    accounts.code,
                    accounts.name as account_name,
                    accounts.sort_order as account_sort,
                    SUM(document_lines.line_total) as amount
                ')
                ->where('documents.document_type', $docType)
                ->whereNull('documents.deleted_at')
                ->whereNotNull('document_lines.account_id')
                ->where('account_types.code', $typeCode)
                ->whereDate('documents.issue_date', '>=', $from)
                ->whereDate('documents.issue_date', '<=', $to)
                ->groupBy('account_groups.name', 'account_groups.sort_order', 'accounts.id', 'accounts.code', 'accounts.name', 'accounts.sort_order');

            return $useWhereIn
                ? $q->whereIn('documents.status', $statuses)->get()->keyBy('account_id')
                : $q->whereNotIn('documents.status', $statuses)->get()->keyBy('account_id');
        };

        $revenueYtd = $fetchLines('sales_invoice', ['draft', 'cancelled'], false, '4', $fyStart, $today);
        $revenueMonth = $fetchLines('sales_invoice', ['draft', 'cancelled'], false, '4', $monthStart, $today);
        $expensesYtd = $fetchLines('purchase_invoice', ['posted', 'partially_paid', 'paid'], true, '5', $fyStart, $today);
        $expensesMonth = $fetchLines('purchase_invoice', ['posted', 'partially_paid', 'paid'], true, '5', $monthStart, $today);

        $mergeSection = function ($ytdMap, $monthMap): \Illuminate\Support\Collection {
            return $ytdMap->keys()->merge($monthMap->keys())->unique()
                ->map(function ($id) use ($ytdMap, $monthMap) {
                    $base = $ytdMap->get($id) ?? $monthMap->get($id);

                    return (object) [
                        'group_name' => $base->group_name,
                        'group_sort' => $base->group_sort,
                        'code' => $base->code,
                        'account_name' => $base->account_name,
                        'account_sort' => $base->account_sort,
                        'ytd' => (float) ($ytdMap->get($id)?->amount ?? 0),
                        'month' => (float) ($monthMap->get($id)?->amount ?? 0),
                    ];
                })
                ->sortBy([['group_sort', 'asc'], ['account_sort', 'asc'], ['code', 'asc']])
                ->groupBy('group_name');
        };

        return [
            'revenue' => $mergeSection($revenueYtd, $revenueMonth),
            'expenses' => $mergeSection($expensesYtd, $expensesMonth),
            'totalRevenueYtd' => $revenueYtd->sum('amount'),
            'totalRevenueMonth' => $revenueMonth->sum('amount'),
            'totalExpensesYtd' => $expensesYtd->sum('amount'),
            'totalExpensesMonth' => $expensesMonth->sum('amount'),
            'netIncomeYtd' => $revenueYtd->sum('amount') - $expensesYtd->sum('amount'),
            'netIncomeMonth' => $revenueMonth->sum('amount') - $expensesMonth->sum('amount'),
            'monthLabel' => now()->format('F Y'),
            'fyLabel' => $fyService->currentYearLabel(),
            'currency' => $settings->base_currency,
        ];
    }
}; ?>

<div>
@include('livewire.pages.reports._subnav')
<div class="flex items-start justify-between px-6 py-5 border-b border-line">
    <div>
        <h1 class="text-[17px] font-semibold tracking-tight text-ink">Income Statement</h1>
        <p class="mt-0.5 text-sm text-ink-muted">Financial year {{ $fyLabel }}</p>
    </div>
</div>

<div class="max-w-3xl mx-auto px-6 py-6 space-y-6">

    {{-- Column headers --}}
    <div class="flex text-xs font-semibold uppercase tracking-widest text-ink-muted mb-[-1rem]">
        <div class="flex-1"></div>
        <div class="w-36 text-right pr-4">{{ $monthLabel }}</div>
        <div class="w-36 text-right pr-4">Year to Date</div>
    </div>

    {{-- Revenue --}}
    <div>
        <h2 class="text-xs font-semibold uppercase tracking-widest text-ink-muted mb-1">Revenue</h2>
        <div class="border border-line rounded-lg overflow-hidden">
            @forelse($revenue as $groupName => $accounts)
                @if($revenue->count() > 1)
                    <div class="px-4 py-2 bg-surface-alt text-xs font-medium text-ink-muted border-b border-line">{{ $groupName }}</div>
                @endif
                @foreach($accounts as $row)
                    <div class="flex items-center px-4 py-2.5 border-b border-line last:border-b-0 hover:bg-surface-alt">
                        <span class="text-sm text-ink flex-1">
                            <span class="font-mono text-xs text-ink-muted mr-2">{{ $row->code }}</span>{{ $row->account_name }}
                        </span>
                        <span class="w-36 text-right text-sm tabular-nums text-ink-soft pr-4">{{ number_format($row->month, 2) }}</span>
                        <span class="w-36 text-right text-sm tabular-nums text-ink pr-4">{{ number_format($row->ytd, 2) }}</span>
                    </div>
                @endforeach
            @empty
                <div class="px-4 py-8 text-center text-sm text-ink-muted">No revenue for this period.</div>
            @endforelse
        </div>
        <div class="flex items-center py-2.5 font-semibold text-sm text-ink">
            <span class="flex-1 pl-4">Total Revenue</span>
            <span class="w-36 text-right tabular-nums pr-4">{{ number_format($totalRevenueMonth, 2) }}</span>
            <span class="w-36 text-right tabular-nums pr-4">{{ number_format($totalRevenueYtd, 2) }}</span>
        </div>
    </div>

    {{-- Expenses --}}
    <div>
        <h2 class="text-xs font-semibold uppercase tracking-widest text-ink-muted mb-1">Expenses</h2>
        <div class="border border-line rounded-lg overflow-hidden">
            @forelse($expenses as $groupName => $accounts)
                @if($expenses->count() > 1)
                    <div class="px-4 py-2 bg-surface-alt text-xs font-medium text-ink-muted border-b border-line">{{ $groupName }}</div>
                @endif
                @foreach($accounts as $row)
                    <div class="flex items-center px-4 py-2.5 border-b border-line last:border-b-0 hover:bg-surface-alt">
                        <span class="text-sm text-ink flex-1">
                            <span class="font-mono text-xs text-ink-muted mr-2">{{ $row->code }}</span>{{ $row->account_name }}
                        </span>
                        <span class="w-36 text-right text-sm tabular-nums text-ink-soft pr-4">{{ number_format($row->month, 2) }}</span>
                        <span class="w-36 text-right text-sm tabular-nums text-ink pr-4">{{ number_format($row->ytd, 2) }}</span>
                    </div>
                @endforeach
            @empty
                <div class="px-4 py-8 text-center text-sm text-ink-muted">No expenses for this period.</div>
            @endforelse
        </div>
        <div class="flex items-center py-2.5 font-semibold text-sm text-ink">
            <span class="flex-1 pl-4">Total Expenses</span>
            <span class="w-36 text-right tabular-nums pr-4">{{ number_format($totalExpensesMonth, 2) }}</span>
            <span class="w-36 text-right tabular-nums pr-4">{{ number_format($totalExpensesYtd, 2) }}</span>
        </div>
    </div>

    {{-- Net Income --}}
    <div class="border-t-2 border-line pt-4">
        <div class="flex items-center bg-surface-alt rounded-lg px-4 py-3">
            <span class="font-bold text-ink flex-1">Net Income</span>
            <span @class([
                'w-36 text-right font-semibold tabular-nums pr-4',
                'text-success' => $netIncomeMonth >= 0,
                'text-danger' => $netIncomeMonth < 0,
            ])>{{ number_format($netIncomeMonth, 2) }}</span>
            <span @class([
                'w-36 text-right font-bold text-lg tabular-nums pr-4',
                'text-success' => $netIncomeYtd >= 0,
                'text-danger' => $netIncomeYtd < 0,
            ])>{{ number_format($netIncomeYtd, 2) }}</span>
        </div>
    </div>

</div>
</div>
