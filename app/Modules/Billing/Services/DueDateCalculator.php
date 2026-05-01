<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\PaymentTermRule;
use App\Modules\Billing\Models\PaymentTerm;
use Carbon\Carbon;

class DueDateCalculator
{
    public function calculate(Carbon $invoiceDate, PaymentTerm $term, int $billingPeriodDay): Carbon
    {
        return match ($term->rule) {
            PaymentTermRule::DaysAfterInvoice => $this->daysAfterInvoice($invoiceDate, $term->days),
            PaymentTermRule::NthOfFollowingMonth => $this->nthOfFollowingMonth($invoiceDate, $term->day_of_month),
            PaymentTermRule::FirstBusinessDayOfFollowingMonth => $this->firstBusinessDayOfFollowingMonth($invoiceDate),
            PaymentTermRule::SameAsInvoiceDate => $invoiceDate->copy(),
            PaymentTermRule::BeginningOfNextBillingPeriod => $this->beginningOfNextBillingPeriod($invoiceDate, $billingPeriodDay),
            PaymentTermRule::NWorkingDaysBeforeMonthEnd => $this->nWorkingDaysBeforeMonthEnd($invoiceDate, $term->days),
        };
    }

    private function daysAfterInvoice(Carbon $invoiceDate, int $days): Carbon
    {
        return $invoiceDate->copy()->addDays($days);
    }

    private function nthOfFollowingMonth(Carbon $invoiceDate, int $dayOfMonth): Carbon
    {
        $date = $invoiceDate->copy()->addMonthNoOverflow()->startOfMonth();

        // Cap to last day of month in case day_of_month > days in month
        $maxDay = $date->daysInMonth;
        $date->day = min($dayOfMonth, $maxDay);

        return $date;
    }

    private function firstBusinessDayOfFollowingMonth(Carbon $invoiceDate): Carbon
    {
        $date = $invoiceDate->copy()->addMonthNoOverflow()->startOfMonth();

        while ($date->isWeekend()) {
            $date->addDay();
        }

        return $date;
    }

    private function beginningOfNextBillingPeriod(Carbon $invoiceDate, int $billingPeriodDay): Carbon
    {
        // Cap billing period day to valid range
        $day = max(1, min(28, $billingPeriodDay));

        // Next occurrence of billing_period_day strictly after invoiceDate
        $candidate = $invoiceDate->copy()->startOfMonth()->day($day);

        if ($candidate->lte($invoiceDate)) {
            $candidate->addMonthNoOverflow();
        }

        return $candidate;
    }

    private function nWorkingDaysBeforeMonthEnd(Carbon $invoiceDate, int $days): Carbon
    {
        $date = $invoiceDate->copy()->endOfMonth()->startOfDay();

        $skipped = 0;

        while ($skipped < $days) {
            $date->subDay();
            if (! $date->isWeekend()) {
                $skipped++;
            }
        }

        return $date;
    }
}
