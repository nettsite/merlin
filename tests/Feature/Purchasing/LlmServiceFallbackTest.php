<?php

use App\Mail\ModelHealthAlertMail;
use App\Modules\Core\Services\LlmService;
use App\Modules\Core\Services\ModelHealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config([
        'services.anthropic.key' => 'test-key',
        'services.anthropic.model_fast' => 'claude-haiku-4-5',
        'services.anthropic.model' => 'claude-sonnet-4-6',
        'services.anthropic.model_backup' => 'claude-opus-4-8',
        'services.anthropic.alert_recipients' => 'ops@example.com',
    ]);
    Cache::flush();
    Mail::fake();
});

function fakeInvoiceJson(float $confidence): string
{
    return json_encode([
        'total' => 100.0,
        'currency' => 'ZAR',
        'confidence' => $confidence,
        'lines' => [],
    ]);
}

it('escalates the strong tier to the backup model on not_found and alerts once', function () {
    Http::fake(function ($request) {
        $model = $request->data()['model'];

        // Fast tier (Haiku): unreconciled low-confidence result, forcing the
        // strong tier to run.
        if (str_contains($model, 'haiku')) {
            return Http::response(['content' => [['text' => fakeInvoiceJson(0.1)]], 'usage' => []], 200);
        }

        // Strong tier (Sonnet): retired.
        if (str_contains($model, 'sonnet')) {
            return Http::response(['error' => ['type' => 'not_found_error', 'message' => 'sonnet retired']], 404);
        }

        // Backup (Opus): answers.
        return Http::response(['content' => [['text' => fakeInvoiceJson(0.9)]], 'usage' => []], 200);
    });

    $result = app(LlmService::class)->extractInvoice('raw invoice text');

    expect($result->confidence)->toBe(0.9)
        ->and(app(ModelHealthService::class)->isDown('claude-sonnet-4-6'))->toBeTrue();

    Mail::assertSent(ModelHealthAlertMail::class, 1);

    // The dead Sonnet rung was hit exactly once before the cache short-circuited it.
    Http::assertSent(fn ($request) => str_contains($request->data()['model'], 'opus'));
});

it('does not escalate on a non-retirement error', function () {
    Http::fake(function ($request) {
        $model = $request->data()['model'];
        if (str_contains($model, 'haiku')) {
            return Http::response(['content' => [['text' => fakeInvoiceJson(0.1)]], 'usage' => []], 200);
        }

        // Strong tier returns a server error — transient, must NOT burn the ladder.
        return Http::response(['error' => ['type' => 'overloaded_error', 'message' => 'overloaded']], 529);
    });

    // Strong tier throws; the unreconciled fast result is returned as the fallback.
    $result = app(LlmService::class)->extractInvoice('raw invoice text');

    expect($result->confidence)->toBe(0.1)
        ->and(app(ModelHealthService::class)->isDown('claude-sonnet-4-6'))->toBeFalse();

    Mail::assertNothingSent();
});
