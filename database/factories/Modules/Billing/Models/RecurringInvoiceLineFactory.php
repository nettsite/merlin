<?php

namespace Database\Factories\Modules\Billing\Models;

use App\Modules\Billing\Models\RecurringInvoice;
use App\Modules\Billing\Models\RecurringInvoiceLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecurringInvoiceLine>
 */
class RecurringInvoiceLineFactory extends Factory
{
    protected $model = RecurringInvoiceLine::class;

    public function definition(): array
    {
        return [
            'recurring_invoice_id' => RecurringInvoice::factory(),
            'line_number' => 1,
            'description' => $this->faker->sentence(4),
            'quantity' => 1.0,
            'unit_price' => $this->faker->randomFloat(2, 100, 5000),
            'discount_percent' => 0,
            'tax_rate' => 15.00,
        ];
    }
}
