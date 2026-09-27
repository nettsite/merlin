<?php

namespace Tests\Unit\Core;

use App\Modules\Core\Services\TypeSafeService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TypeSafeServiceTest extends TestCase
{
    public function test_returns_choice_result_on_success(): void
    {
        Http::fake([
            'api.typesafe.ai/*' => Http::response([
                'model' => 'jev-1.13.0',
                'answers' => [
                    'pick' => [
                        'type' => 'choice',
                        'choice' => 'billing',
                        'probabilities' => ['billing' => 0.88, 'technical' => 0.12],
                        'confidence' => 0.81,
                    ],
                ],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $result = app(TypeSafeService::class)->choice(
            state: 'Help! My payouts have been failing.',
            instructions: 'Which team should handle this?',
            criteria: ['billing' => 'Payments', 'technical' => 'Bugs'],
        );

        $this->assertNotNull($result);
        $this->assertSame('billing', $result->choice);
        $this->assertSame(0.81, $result->confidence);
    }

    public function test_returns_null_on_http_error(): void
    {
        Http::fake([
            'api.typesafe.ai/*' => Http::response(['error' => ['message' => 'invalid']], 422),
        ]);

        $result = app(TypeSafeService::class)->choice(
            state: 'irrelevant',
            instructions: 'irrelevant',
            criteria: ['a' => null],
        );

        $this->assertNull($result);
    }

    public function test_returns_null_when_key_not_configured(): void
    {
        config(['services.typesafe.key' => null]);

        Http::fake();

        $result = app(TypeSafeService::class)->choice(
            state: 'irrelevant',
            instructions: 'irrelevant',
            criteria: ['a' => null],
        );

        $this->assertNull($result);
        Http::assertNothingSent();
    }
}
