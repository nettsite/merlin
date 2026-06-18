<?php

namespace Tests\Unit\Billing;

use App\Modules\Billing\Services\WorkingDayCalculator;
use Carbon\Carbon;
use Tests\TestCase;

class WorkingDayCalculatorTest extends TestCase
{
    private WorkingDayCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new WorkingDayCalculator;
    }

    // latestWorkingDayOnOrBefore

    public function test_saturday_rolls_back_to_friday(): void
    {
        // 2025-01-25 is a Saturday
        $result = $this->calculator->latestWorkingDayOnOrBefore(Carbon::parse('2025-01-25'));

        $this->assertEquals('2025-01-24', $result->toDateString());
    }

    public function test_monday_public_holiday_rolls_back_past_weekend_to_friday(): void
    {
        // 2026-08-10 is Monday (National Women's Day Observed)
        // 2026-08-09 is Sunday (National Women's Day)
        // 2026-08-08 is Saturday
        // Expected: 2026-08-07 (Friday)
        $result = $this->calculator->latestWorkingDayOnOrBefore(Carbon::parse('2026-08-10'));

        $this->assertEquals('2026-08-07', $result->toDateString());
    }

    public function test_christmas_and_goodwill_back_to_back_rolls_back_correctly(): void
    {
        // 2025-12-25 Thursday (Christmas), 2025-12-26 Friday (Day of Goodwill)
        // Both are holidays so latestWorkingDayOnOrBefore(Dec 26) → Dec 24 (Wednesday)
        $result = $this->calculator->latestWorkingDayOnOrBefore(Carbon::parse('2025-12-26'));

        $this->assertEquals('2025-12-24', $result->toDateString());
    }

    public function test_working_day_is_returned_as_is(): void
    {
        // 2025-01-15 is a Wednesday, not a holiday
        $result = $this->calculator->latestWorkingDayOnOrBefore(Carbon::parse('2025-01-15'));

        $this->assertEquals('2025-01-15', $result->toDateString());
    }

    // addBusinessDays

    public function test_add_one_business_day_from_friday_gives_monday(): void
    {
        // 2025-01-24 is Friday; 2025-01-27 is Monday (not a holiday)
        $result = $this->calculator->addBusinessDays(Carbon::parse('2025-01-24'), 1);

        $this->assertEquals('2025-01-27', $result->toDateString());
    }

    public function test_add_one_business_day_from_friday_skips_monday_holiday(): void
    {
        // 2026-04-03 (Friday = Good Friday) ... let me use a different case:
        // 2026-08-07 is Friday; 2026-08-10 is Monday but it's a holiday (Observed)
        // So addBusinessDays(Aug 7, 1) → Aug 11 (Tuesday)
        $result = $this->calculator->addBusinessDays(Carbon::parse('2026-08-07'), 1);

        $this->assertEquals('2026-08-11', $result->toDateString());
    }

    public function test_subtract_business_days(): void
    {
        // 2025-01-29 Wednesday; minus 3 business days → 2025-01-24 Friday
        // (skip Jan 25 Sat, Jan 26 Sun)
        $result = $this->calculator->addBusinessDays(Carbon::parse('2025-01-29'), -3);

        $this->assertEquals('2025-01-24', $result->toDateString());
    }

    // isWorkingDay

    public function test_weekend_is_not_working_day(): void
    {
        $this->assertFalse($this->calculator->isWorkingDay(Carbon::parse('2025-01-25'))); // Saturday
        $this->assertFalse($this->calculator->isWorkingDay(Carbon::parse('2025-01-26'))); // Sunday
    }

    public function test_public_holiday_is_not_working_day(): void
    {
        $this->assertFalse($this->calculator->isWorkingDay(Carbon::parse('2025-12-25'))); // Christmas
        $this->assertFalse($this->calculator->isWorkingDay(Carbon::parse('2025-04-18'))); // Good Friday
    }

    public function test_ordinary_weekday_is_working_day(): void
    {
        $this->assertTrue($this->calculator->isWorkingDay(Carbon::parse('2025-01-15'))); // Wednesday
    }

    // earliestWorkingDayOnOrAfter

    public function test_earliest_working_day_on_or_after_skips_holiday_and_weekend(): void
    {
        // 2026-04-03 Good Friday, 2026-04-04 Saturday, 2026-04-05 Sunday, 2026-04-06 Monday (Family Day)
        // First working day on or after Apr 3 → Apr 7 (Tuesday)
        $result = $this->calculator->earliestWorkingDayOnOrAfter(Carbon::parse('2026-04-03'));

        $this->assertEquals('2026-04-07', $result->toDateString());
    }
}
