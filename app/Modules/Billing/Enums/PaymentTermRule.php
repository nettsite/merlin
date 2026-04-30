<?php

namespace App\Modules\Billing\Enums;

enum PaymentTermRule: string
{
    case DaysAfterInvoice = 'days_after_invoice';
    case NthOfFollowingMonth = 'nth_of_following_month';
    case FirstBusinessDayOfFollowingMonth = 'first_business_day_of_following_month';
    case SameAsInvoiceDate = 'same_as_invoice_date';
    case BeginningOfNextBillingPeriod = 'beginning_of_next_billing_period';
    case NWorkingDaysBeforeMonthEnd = 'n_working_days_before_month_end';

    public function label(): string
    {
        return match ($this) {
            self::DaysAfterInvoice => 'N Days After Invoice',
            self::NthOfFollowingMonth => 'Nth of Following Month',
            self::FirstBusinessDayOfFollowingMonth => 'First Business Day of Following Month',
            self::SameAsInvoiceDate => 'Same as Invoice Date',
            self::BeginningOfNextBillingPeriod => 'Beginning of Next Billing Period',
            self::NWorkingDaysBeforeMonthEnd => 'N Working Days Before Month End',
        };
    }

    public function requiresDays(): bool
    {
        return in_array($this, [self::DaysAfterInvoice, self::NWorkingDaysBeforeMonthEnd]);
    }

    public function requiresDayOfMonth(): bool
    {
        return $this === self::NthOfFollowingMonth;
    }
}
