<?php

namespace Database\Factories\Modules\Core\Models;

use App\Modules\Core\Models\Party;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Party>
 */
class PartyFactory extends Factory
{
    protected $model = Party::class;

    public function definition(): array
    {
        return [
            'party_type' => 'business',
            'status' => 'active',
            'primary_email' => fake()->safeEmail(),
            'primary_phone' => fake()->phoneNumber(),
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => 'pending']);
    }

    public function inactive(): static
    {
        return $this->state(['status' => 'inactive']);
    }
}
