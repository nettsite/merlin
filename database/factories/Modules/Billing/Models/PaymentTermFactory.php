<?php

namespace Database\Factories\Modules\Billing\Models;

use App\Modules\Billing\Enums\PaymentTermRule;
use App\Modules\Billing\Models\PaymentTerm;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentTerm>
 */
class PaymentTermFactory extends Factory
{
    protected $model = PaymentTerm::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(3, true),
            'rule' => PaymentTermRule::DaysAfterInvoice,
            'days' => 30,
            'day_of_month' => null,
        ];
    }

    public function daysAfterInvoice(int $days = 30): static
    {
        return $this->state([
            'rule' => PaymentTermRule::DaysAfterInvoice,
            'days' => $days,
            'day_of_month' => null,
        ]);
    }

    public function nthOfFollowingMonth(int $day = 25): static
    {
        return $this->state([
            'rule' => PaymentTermRule::NthOfFollowingMonth,
            'days' => null,
            'day_of_month' => $day,
        ]);
    }

    public function firstBusinessDayOfFollowingMonth(): static
    {
        return $this->state([
            'rule' => PaymentTermRule::FirstBusinessDayOfFollowingMonth,
            'days' => null,
            'day_of_month' => null,
        ]);
    }

    public function sameAsInvoiceDate(): static
    {
        return $this->state([
            'rule' => PaymentTermRule::SameAsInvoiceDate,
            'days' => null,
            'day_of_month' => null,
        ]);
    }

    public function beginningOfNextBillingPeriod(): static
    {
        return $this->state([
            'rule' => PaymentTermRule::BeginningOfNextBillingPeriod,
            'days' => null,
            'day_of_month' => null,
        ]);
    }

    public function nWorkingDaysBeforeMonthEnd(int $days = 5): static
    {
        return $this->state([
            'rule' => PaymentTermRule::NWorkingDaysBeforeMonthEnd,
            'days' => $days,
            'day_of_month' => null,
        ]);
    }
}
