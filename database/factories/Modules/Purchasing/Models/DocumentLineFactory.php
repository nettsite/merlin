<?php

namespace Database\Factories\Modules\Purchasing\Models;

use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Models\DocumentLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentLine>
 */
class DocumentLineFactory extends Factory
{
    protected $model = DocumentLine::class;

    public function definition(): array
    {
        return [
            'document_id' => Document::factory(),
            'line_number' => 1,
            'type' => 'service',
            'description' => fake()->sentence(4),
            'quantity' => 1,
            'unit_price' => fake()->randomFloat(2, 10, 1000),
            'discount_percent' => 0,
            'discount_amount' => 0,
            'tax_rate' => 15.00,
        ];
    }

    public function exempt(): static
    {
        return $this->state(['tax_rate' => null]);
    }
}
