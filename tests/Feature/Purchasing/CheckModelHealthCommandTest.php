<?php

use App\Mail\ModelHealthAlertMail;
use App\Modules\Purchasing\Services\ModelHealthService;
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
});

it('passes and emails nothing when every model is healthy', function () {
    Mail::fake();
    Http::fake(fn () => Http::response(['content' => [['text' => 'ok']]], 200));

    $this->artisan('models:health-check')->assertExitCode(0);

    Mail::assertNothingSent();
});

it('fails and emails once when a model is unavailable', function () {
    Mail::fake();
    Http::fake(function ($request) {
        $model = $request->data()['model'];
        if (str_contains($model, 'sonnet')) {
            return Http::response(['error' => ['type' => 'not_found_error', 'message' => 'model not found']], 404);
        }

        return Http::response(['content' => [['text' => 'ok']]], 200);
    });

    $this->artisan('models:health-check')->assertExitCode(1);

    Mail::assertSent(ModelHealthAlertMail::class, 1);
    expect(app(ModelHealthService::class)->isDown('claude-sonnet-4-6'))->toBeTrue();
});

it('clears a stale down mark when the model recovers', function () {
    Mail::fake();
    app(ModelHealthService::class)->recordUnavailable('claude-sonnet-4-6', 'was retired');
    expect(app(ModelHealthService::class)->isDown('claude-sonnet-4-6'))->toBeTrue();

    Http::fake(fn () => Http::response(['content' => [['text' => 'ok']]], 200));

    $this->artisan('models:health-check')->assertExitCode(0);

    expect(app(ModelHealthService::class)->isDown('claude-sonnet-4-6'))->toBeFalse();
});
