<?php

namespace App\Modules\Billing\Enums;

enum RecurringFrequency: string
{
    case Weekly = 'weekly';
    case Fortnightly = 'fortnightly';
    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Annually = 'annually';

    public function label(): string
    {
        return match ($this) {
            self::Weekly => 'Weekly',
            self::Fortnightly => 'Fortnightly',
            self::Monthly => 'Monthly',
            self::Quarterly => 'Quarterly',
            self::Annually => 'Annually',
        };
    }

    /** Weekly/fortnightly use a fixed weekday cadence; billing_period_day is irrelevant. */
    public function isDayOfMonthBased(): bool
    {
        return match ($this) {
            self::Weekly, self::Fortnightly => false,
            default => true,
        };
    }
}
