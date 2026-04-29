<?php

namespace Database\Factories\Modules\Accounting\Models;

use App\Modules\Accounting\Models\AccountType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountType>
 */
class AccountTypeFactory extends Factory
{
    protected $model = AccountType::class;

    public function definition(): array
    {
        return [
            'code' => (string) fake()->unique()->numberBetween(1, 9),
            'name' => fake()->word(),
            'normal_balance' => fake()->randomElement(['debit', 'credit']),
            'sort_order' => fake()->numberBetween(10, 90),
        ];
    }

    public function expense(): static
    {
        return $this->state([
            'code' => '5',
            'name' => 'Expense',
            'normal_balance' => 'debit',
            'sort_order' => 50,
        ]);
    }

    public function asset(): static
    {
        return $this->state([
            'code' => '1',
            'name' => 'Asset',
            'normal_balance' => 'debit',
            'sort_order' => 10,
        ]);
    }
}
