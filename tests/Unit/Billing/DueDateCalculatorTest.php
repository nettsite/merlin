<?php

namespace Tests\Unit\Billing;

use App\Modules\Billing\Enums\PaymentTermRule;
use App\Modules\Billing\Models\PaymentTerm;
use App\Modules\Billing\Services\DueDateCalculator;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class DueDateCalculatorTest extends TestCase
{
    private DueDateCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new DueDateCalculator;
    }

    private function term(PaymentTermRule $rule, ?int $days = null, ?int $dayOfMonth = null): PaymentTerm
    {
        $term = new PaymentTerm;
        $term->rule = $rule;
        $term->days = $days;
        $term->day_of_month = $dayOfMonth;

        return $term;
    }

    public function test_days_after_invoice(): void
    {
        $invoiceDate = Carbon::parse('2025-01-15');
        $term = $this->term(PaymentTermRule::DaysAfterInvoice, days: 30);

        $due = $this->calculator->calculate($invoiceDate, $term, 1);

        $this->assertEquals('2025-02-14', $due->toDateString());
    }

    public function test_days_after_invoice_crosses_year(): void
    {
        $invoiceDate = Carbon::parse('2025-12-20');
        $term = $this->term(PaymentTermRule::DaysAfterInvoice, days: 30);

        $due = $this->calculator->calculate($invoiceDate, $term, 1);

        $this->assertEquals('2026-01-19', $due->toDateString());
    }

    public function test_nth_of_following_month(): void
    {
        $invoiceDate = Carbon::parse('2025-01-10');
        $term = $this->term(PaymentTermRule::NthOfFollowingMonth, dayOfMonth: 25);

        $due = $this->calculator->calculate($invoiceDate, $term, 1);

        $this->assertEquals('2025-02-25', $due->toDateString());
    }

    public function test_nth_of_following_month_caps_to_last_day_in_short_month(): void
    {
        // February has 28 days in non-leap year; day 31 should cap to 28
        $invoiceDate = Carbon::parse('2025-01-10');
        $term = $this->term(PaymentTermRule::NthOfFollowingMonth, dayOfMonth: 31);

        $due = $this->calculator->calculate($invoiceDate, $term, 1);

        $this->assertEquals('2025-02-28', $due->toDateString());
    }

    public function test_first_business_day_of_following_month_on_monday(): void
    {
        // Feb 2025 starts on Saturday → first business day is Monday Feb 3
        $invoiceDate = Carbon::parse('2025-01-31');
        $term = $this->term(PaymentTermRule::FirstBusinessDayOfFollowingMonth);

        $due = $this->calculator->calculate($invoiceDate, $term, 1);

        $this->assertEquals('2025-02-03', $due->toDateString());
    }

    public function test_first_business_day_of_following_month_on_monday_from_sunday(): void
    {
        // March 2025 starts on Saturday → first business day is Monday Mar 3
        $invoiceDate = Carbon::parse('2025-02-28');
        $term = $this->term(PaymentTermRule::FirstBusinessDayOfFollowingMonth);

        $due = $this->calculator->calculate($invoiceDate, $term, 1);

        $this->assertEquals('2025-03-03', $due->toDateString());
    }

    public function test_first_business_day_of_following_month_on_weekday(): void
    {
        // April 2025 starts on Tuesday → already a business day
        $invoiceDate = Carbon::parse('2025-03-15');
        $term = $this->term(PaymentTermRule::FirstBusinessDayOfFollowingMonth);

        $due = $this->calculator->calculate($invoiceDate, $term, 1);

        $this->assertEquals('2025-04-01', $due->toDateString());
    }

    public function test_same_as_invoice_date(): void
    {
        $invoiceDate = Carbon::parse('2025-06-15');
        $term = $this->term(PaymentTermRule::SameAsInvoiceDate);

        $due = $this->calculator->calculate($invoiceDate, $term, 1);

        $this->assertEquals('2025-06-15', $due->toDateString());
    }

    public function test_beginning_of_next_billing_period_mid_month(): void
    {
        // Invoice on Jan 15; billing period day = 1 → next period starts Feb 1
        $invoiceDate = Carbon::parse('2025-01-15');
        $term = $this->term(PaymentTermRule::BeginningOfNextBillingPeriod);

        $due = $this->calculator->calculate($invoiceDate, $term, billingPeriodDay: 1);

        $this->assertEquals('2025-02-01', $due->toDateString());
    }

    public function test_beginning_of_next_billing_period_on_period_day(): void
    {
        // Invoice on Jan 1 (same as billing period day) → next period is Feb 1
        $invoiceDate = Carbon::parse('2025-01-01');
        $term = $this->term(PaymentTermRule::BeginningOfNextBillingPeriod);

        $due = $this->calculator->calculate($invoiceDate, $term, billingPeriodDay: 1);

        $this->assertEquals('2025-02-01', $due->toDateString());
    }

    public function test_beginning_of_next_billing_period_before_period_day(): void
    {
        // Invoice on Jan 10; billing period day = 15 → next period starts Jan 15
        $invoiceDate = Carbon::parse('2025-01-10');
        $term = $this->term(PaymentTermRule::BeginningOfNextBillingPeriod);

        $due = $this->calculator->calculate($invoiceDate, $term, billingPeriodDay: 15);

        $this->assertEquals('2025-01-15', $due->toDateString());
    }

    public function test_n_working_days_before_month_end(): void
    {
        // Jan 2025: last day is Jan 31 (Friday). 5 working days before:
        // Jan 31 (Fri) subtract 1 = Jan 30 (Thu), 2 = Jan 29 (Wed), 3 = Jan 28 (Tue),
        // 4 = Jan 27 (Mon), 5 = Jan 24 (Fri)
        $invoiceDate = Carbon::parse('2025-01-01');
        $term = $this->term(PaymentTermRule::NWorkingDaysBeforeMonthEnd, days: 5);

        $due = $this->calculator->calculate($invoiceDate, $term, 1);

        $this->assertEquals('2025-01-24', $due->toDateString());
    }

    public function test_n_working_days_before_month_end_skips_weekend(): void
    {
        // March 2025: last day is Mar 31 (Monday).
        // 1 working day before: Mar 31 (Mon) subtract 1 = Mar 28 (Fri)
        $invoiceDate = Carbon::parse('2025-03-01');
        $term = $this->term(PaymentTermRule::NWorkingDaysBeforeMonthEnd, days: 1);

        $due = $this->calculator->calculate($invoiceDate, $term, 1);

        $this->assertEquals('2025-03-28', $due->toDateString());
    }
}
