<?php

namespace Tests\Unit\Billing;

use App\Modules\Billing\Services\ProRataCalculator;
use Carbon\Carbon;
use Tests\TestCase;

class ProRataCalculatorTest extends TestCase
{
    private ProRataCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new ProRataCalculator;
    }

    public function test_full_period_when_start_is_billing_day(): void
    {
        $result = $this->calculator->calculate(Carbon::parse('2026-05-01'), 1);

        $this->assertEquals(1.0, $result['factor']);
        $this->assertEquals(31, $result['days_in_period']);
        $this->assertEquals(31, $result['days_active']);
    }

    public function test_full_period_when_start_matches_billing_day_mid_month(): void
    {
        $result = $this->calculator->calculate(Carbon::parse('2026-05-15'), 15);

        $this->assertEquals(1.0, $result['factor']);
    }

    public function test_pro_rata_mid_month_start(): void
    {
        // Billing period day = 1, start May 15
        // Period: May 1–31 (31 days), active: May 15–31 = 17 days
        $result = $this->calculator->calculate(Carbon::parse('2026-05-15'), 1);

        $this->assertEquals('2026-05-01', $result['period_start']->toDateString());
        $this->assertEquals('2026-05-31', $result['period_end']->toDateString());
        $this->assertEquals(31, $result['days_in_period']);
        $this->assertEquals(17, $result['days_active']);
        $this->assertEqualsWithDelta(17 / 31, $result['factor'], 0.000001);
    }

    public function test_pro_rata_billing_day_mid_month(): void
    {
        // Billing period day = 15, start May 20
        // Period: May 15–June 14 (31 days), active: May 20–June 14 = 26 days
        $result = $this->calculator->calculate(Carbon::parse('2026-05-20'), 15);

        $this->assertEquals('2026-05-15', $result['period_start']->toDateString());
        $this->assertEquals('2026-06-14', $result['period_end']->toDateString());
        $this->assertEquals(31, $result['days_in_period']);
        $this->assertEquals(26, $result['days_active']);
        $this->assertEqualsWithDelta(26 / 31, $result['factor'], 0.000001);
    }

    public function test_start_before_billing_day_this_month(): void
    {
        // Billing period day = 15, start May 5
        // Period: April 15–May 14 (30 days), active: May 5–May 14 = 10 days
        $result = $this->calculator->calculate(Carbon::parse('2026-05-05'), 15);

        $this->assertEquals('2026-04-15', $result['period_start']->toDateString());
        $this->assertEquals('2026-05-14', $result['period_end']->toDateString());
        $this->assertEquals(10, $result['days_active']);
    }

    public function test_last_day_of_february_non_leap(): void
    {
        // Billing period day = 1, start Feb 28 2026
        // Period: Feb 1–28 (28 days), active: 1 day
        $result = $this->calculator->calculate(Carbon::parse('2026-02-28'), 1);

        $this->assertEquals(28, $result['days_in_period']);
        $this->assertEquals(1, $result['days_active']);
        $this->assertEqualsWithDelta(1 / 28, $result['factor'], 0.000001);
    }

    public function test_is_full_period_returns_true_when_matching(): void
    {
        $this->assertTrue($this->calculator->isFullPeriod(Carbon::parse('2026-05-01'), 1));
        $this->assertTrue($this->calculator->isFullPeriod(Carbon::parse('2026-05-15'), 15));
    }

    public function test_is_full_period_returns_false_when_not_matching(): void
    {
        $this->assertFalse($this->calculator->isFullPeriod(Carbon::parse('2026-05-15'), 1));
        $this->assertFalse($this->calculator->isFullPeriod(Carbon::parse('2026-05-01'), 15));
    }
}
