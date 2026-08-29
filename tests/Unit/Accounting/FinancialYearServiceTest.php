<?php

namespace Tests\Unit\Accounting;

use App\Modules\Accounting\Services\FinancialYearService;
use App\Modules\Accounting\Settings\AccountingSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FinancialYearServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_year_bounds_for_march_start_month_span_march_to_february(): void
    {
        app(AccountingSettings::class)->fill(['financial_year_start_month' => 3])->save();

        [$start, $end] = app(FinancialYearService::class)->yearBounds('2025/2026');

        $this->assertSame('2025-03-01', $start->toDateString());
        $this->assertSame('2026-02-28', $end->toDateString());
    }

    public function test_current_year_label_before_the_start_month_belongs_to_the_prior_fiscal_year(): void
    {
        app(AccountingSettings::class)->fill(['financial_year_start_month' => 3])->save();
        Carbon::setTestNow(Carbon::parse('2026-02-15'));

        $label = app(FinancialYearService::class)->currentYearLabel();

        $this->assertSame('2025/2026', $label);
    }

    public function test_current_year_label_on_the_start_month_belongs_to_the_new_fiscal_year(): void
    {
        app(AccountingSettings::class)->fill(['financial_year_start_month' => 3])->save();
        Carbon::setTestNow(Carbon::parse('2026-03-01'));

        $label = app(FinancialYearService::class)->currentYearLabel();

        $this->assertSame('2026/2027', $label);
    }

    public function test_year_bounds_for_a_non_march_start_month(): void
    {
        app(AccountingSettings::class)->fill(['financial_year_start_month' => 7])->save();

        [$start, $end] = app(FinancialYearService::class)->yearBounds('2025/2026');

        $this->assertSame('2025-07-01', $start->toDateString());
        $this->assertSame('2026-06-30', $end->toDateString());
    }

    public function test_month_bounds_wrap_calendar_year_for_a_fiscal_month_past_december(): void
    {
        // Start month = March: fiscal month 11 = January, which falls in the
        // *next* calendar year relative to the fiscal year's start year.
        app(AccountingSettings::class)->fill(['financial_year_start_month' => 3])->save();

        [$start, $end] = app(FinancialYearService::class)->monthBounds('2025/2026', 11);

        $this->assertSame('2026-01-01', $start->toDateString());
        $this->assertSame('2026-01-31', $end->toDateString());
    }

    public function test_month_options_start_from_the_fiscal_year_start_month(): void
    {
        app(AccountingSettings::class)->fill(['financial_year_start_month' => 3])->save();

        $options = app(FinancialYearService::class)->monthOptions();

        $this->assertSame('March', $options['1']);
        $this->assertSame('February', $options['12']);
    }
}
