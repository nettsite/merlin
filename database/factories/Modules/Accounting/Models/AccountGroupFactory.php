<?php

namespace Database\Factories\Modules\Accounting\Models;

use App\Modules\Accounting\Models\AccountGroup;
use App\Modules\Accounting\Models\AccountType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountGroup>
 */
class AccountGroupFactory extends Factory
{
    protected $model = AccountGroup::class;

    public function definition(): array
    {
        return [
            'account_type_id' => AccountType::factory(),
            'code' => fake()->unique()->numerify('##'),
            'name' => fake()->words(2, true),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(10, 100),
        ];
    }
}
