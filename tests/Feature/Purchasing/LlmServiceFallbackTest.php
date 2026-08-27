<?php

use App\Ai\Agents\InvoiceExtractionAgent;
use App\Mail\ModelHealthAlertMail;
use App\Modules\Core\Services\LlmService;
use App\Modules\Core\Services\ModelHealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;

beforeEach(function () {
    config([
        'services.anthropic.key' => 'test-key',
        'ai.providers.anthropic.models.fast' => 'claude-haiku-4-5',
        'ai.providers.anthropic.models.strong' => 'claude-sonnet-4-6',
        'ai.providers.anthropic.models.backup' => 'claude-opus-4-8',
        'ai.alert_recipients' => 'ops@example.com',
    ]);
    Cache::flush();
    Mail::fake();
});

function fakeInvoiceStructuredResponse(float $confidence, string $model): StructuredTextResponse
{
    $data = ['total' => 100.0, 'currency' => 'ZAR', 'confidence' => $confidence, 'lines' => []];

    return new StructuredTextResponse($data, (string) json_encode($data), new Usage, new Meta('anthropic', $model));
}

it('escalates the strong tier to the backup model on not_found and alerts once', function () {
    InvoiceExtractionAgent::fake(function (string $prompt, $attachments, $provider, string $model) {
        // Fast tier (Haiku): unreconciled low-confidence result, forcing the
        // strong tier to run.
        if (str_contains($model, 'haiku')) {
            return fakeInvoiceStructuredResponse(0.1, $model);
        }

        // Strong tier (Sonnet): retired.
        if (str_contains($model, 'sonnet')) {
            throw fakeAnthropicRequestException(['error' => ['type' => 'not_found_error', 'message' => 'sonnet retired']], 404);
        }

        // Backup (Opus): answers.
        return fakeInvoiceStructuredResponse(0.9, $model);
    });

    $result = app(LlmService::class)->extractInvoice('raw invoice text');

    expect($result->confidence)->toBe(0.9)
        ->and(app(ModelHealthService::class)->isDown('claude-sonnet-4-6'))->toBeTrue();

    Mail::assertSent(ModelHealthAlertMail::class, 1);

    // The dead Sonnet rung was hit exactly once before the cache short-circuited it.
    InvoiceExtractionAgent::assertPrompted(fn ($prompt): bool => str_contains($prompt->model, 'opus'));
});

it('does not escalate on a non-retirement error', function () {
    InvoiceExtractionAgent::fake(function (string $prompt, $attachments, $provider, string $model) {
        if (str_contains($model, 'haiku')) {
            return fakeInvoiceStructuredResponse(0.1, $model);
        }

        // Strong tier returns a server error — transient, must NOT burn the ladder.
        throw ProviderOverloadedException::forProvider(
            'anthropic', 529, fakeAnthropicRequestException(['error' => ['type' => 'overloaded_error', 'message' => 'overloaded']], 529)
        );
    });

    // Strong tier throws; the unreconciled fast result is returned as the fallback.
    $result = app(LlmService::class)->extractInvoice('raw invoice text');

    expect($result->confidence)->toBe(0.1)
        ->and(app(ModelHealthService::class)->isDown('claude-sonnet-4-6'))->toBeFalse();

    Mail::assertNothingSent();
});
