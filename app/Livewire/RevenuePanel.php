<?php

namespace App\Livewire;

use App\Modules\Accounting\Services\FinancialYearService;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\Services\ExchangeRateService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Livewire\Component;

class RevenuePanel extends Component
{
    /**
     * Net revenue between two dates, defined exactly as the Income Statement does:
     * credits minus debits on income accounts, so credit notes net revenue back down.
     */
    private function revenueBetween(CarbonInterface $from, CarbonInterface $to): float
    {
        return (float) DB::table('postings')
            ->join('accounts', 'accounts.id', '=', 'postings.account_id')
            ->join('account_groups', 'account_groups.id', '=', 'accounts.account_group_id')
            ->join('account_types', 'account_types.id', '=', 'account_groups.account_type_id')
            ->where('account_types.code', '4')
            ->whereDate('postings.entry_date', '>=', $from)
            ->whereDate('postings.entry_date', '<=', $to)
            ->selectRaw('COALESCE(SUM(postings.credit) - SUM(postings.debit), 0) as amount')
            ->value('amount');
    }

    public function render(): View
    {
        $canView = Auth::check() && Auth::user()->can('documents-view-any');
        $today = now();
        $months = collect();
        $mtd = $ytd = 0.0;

        if ($canView) {
            [$fyStart] = app(FinancialYearService::class)->yearBounds(app(FinancialYearService::class)->currentYearLabel());

            $mtd = $this->revenueBetween($today->copy()->startOfMonth(), $today);
            $ytd = $this->revenueBetween($fyStart, $today);

            // The last 12 calendar months, ending with the current (part) month.
            $months = collect(range(11, 0))->map(function (int $ago) use ($today): array {
                $start = $today->copy()->startOfMonth()->subMonthsNoOverflow($ago);

                return [
                    'label' => $start->format('M'),
                    'title' => $start->format('F Y'),
                    'amount' => $this->revenueBetween($start, $start->copy()->endOfMonth()),
                ];
            });
        }

        $max = max(1.0, (float) $months->max('amount'));

        return view('livewire.revenue-panel', [
            'canView' => $canView,
            'mtd' => $mtd,
            'ytd' => $ytd,
            'months' => $months->map(fn (array $m): array => $m + ['pct' => max(0, $m['amount']) / $max * 100]),
            'monthLabel' => $today->format('F Y'),
            'fyLabel' => $canView ? app(FinancialYearService::class)->currentYearLabel() : '',
            'currencySymbol' => ExchangeRateService::currencySymbol(app(CurrencySettings::class)->base_currency),
        ]);
    }
}
