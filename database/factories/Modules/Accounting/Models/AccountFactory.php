<?php

namespace Database\Factories\Modules\Accounting\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountGroup;
use App\Modules\Accounting\Models\AccountType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'account_group_id' => AccountGroup::factory(),
            'code' => fake()->unique()->numerify('####'),
            'name' => fake()->words(3, true),
            'is_active' => true,
            'allow_direct_posting' => true,
            'is_system' => false,
            'sort_order' => 0,
        ];
    }

    public function expense(): static
    {
        return $this->state(fn () => [
            'account_group_id' => AccountGroup::factory()->state([
                'account_type_id' => AccountType::factory()->expense(),
            ]),
        ]);
    }

    public function asset(): static
    {
        return $this->state(fn () => [
            'account_group_id' => AccountGroup::factory()->state([
                'account_type_id' => AccountType::factory()->asset(),
            ]),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function nonPostable(): static
    {
        return $this->state(['allow_direct_posting' => false]);
    }

    public function system(): static
    {
        return $this->state(['is_system' => true]);
    }
}
