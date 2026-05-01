<?php

namespace Database\Factories\Modules\Billing\Models;

use App\Modules\Billing\Enums\RecurringFrequency;
use App\Modules\Billing\Enums\RecurringInvoiceStatus;
use App\Modules\Billing\Models\RecurringInvoice;
use App\Modules\Core\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringInvoice>
 */
class RecurringInvoiceFactory extends Factory
{
    protected $model = RecurringInvoice::class;

    public function definition(): array
    {
        $startDate = now()->startOfMonth();

        return [
            'client_id' => Party::factory(),
            'frequency' => RecurringFrequency::Monthly,
            'billing_period_day' => 1,
            'start_date' => $startDate->toDateString(),
            'next_invoice_date' => $startDate->toDateString(),
            'status' => RecurringInvoiceStatus::Active,
            'currency' => 'ZAR',
        ];
    }

    public function monthly(): static
    {
        return $this->state(['frequency' => RecurringFrequency::Monthly]);
    }

    public function quarterly(): static
    {
        return $this->state(['frequency' => RecurringFrequency::Quarterly]);
    }

    public function annually(): static
    {
        return $this->state(['frequency' => RecurringFrequency::Annually]);
    }

    public function active(): static
    {
        return $this->state(['status' => RecurringInvoiceStatus::Active]);
    }

    public function paused(): static
    {
        return $this->state(['status' => RecurringInvoiceStatus::Paused]);
    }

    public function completed(): static
    {
        return $this->state(['status' => RecurringInvoiceStatus::Completed]);
    }
}
