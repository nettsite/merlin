<?php

namespace Database\Factories\Modules\Purchasing\Models;

use App\Modules\Purchasing\Models\PostingRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PostingRule>
 */
class PostingRuleFactory extends Factory
{
    protected $model = PostingRule::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'party_id' => null,
            'name' => $this->faker->words(3, true),
            'description' => $this->faker->optional()->sentence(),
            'conditions' => [
                'min_confidence' => 0.90,
            ],
            'actions' => [
                'auto_approve' => true,
                'auto_post' => false,
            ],
            'is_active' => true,
        ];
    }
}
