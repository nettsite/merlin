<?php

use App\Mail\ModelHealthAlertMail;
use App\Modules\Purchasing\Services\ModelHealthService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config([
        'services.anthropic.model_fast' => 'claude-haiku-4-5',
        'services.anthropic.model' => 'claude-sonnet-4-6',
        'services.anthropic.model_backup' => 'claude-opus-4-8',
        'services.anthropic.alert_recipients' => 'ops@example.com, second@example.com',
        'services.anthropic.down_ttl' => 3600,
    ]);
    Cache::flush();
});

it('builds the fallback ladder fast -> model -> backup', function () {
    expect(app(ModelHealthService::class)->ladder())
        ->toBe(['claude-haiku-4-5', 'claude-sonnet-4-6', 'claude-opus-4-8']);
});

it('parses multiple comma-separated recipients', function () {
    expect(app(ModelHealthService::class)->recipients())
        ->toBe(['ops@example.com', 'second@example.com']);
});

it('escalates from a tier and skips models marked down', function () {
    $health = app(ModelHealthService::class);

    expect($health->escalationFrom('claude-sonnet-4-6'))
        ->toBe(['claude-sonnet-4-6', 'claude-opus-4-8']);

    Mail::fake();
    $health->recordUnavailable('claude-sonnet-4-6', 'retired');

    expect($health->isDown('claude-sonnet-4-6'))->toBeTrue()
        ->and($health->escalationFrom('claude-sonnet-4-6'))->toBe(['claude-opus-4-8'])
        ->and($health->escalationFrom('claude-haiku-4-5'))->toBe(['claude-haiku-4-5', 'claude-opus-4-8']);
});

it('alerts only once while a model stays down', function () {
    Mail::fake();
    $health = app(ModelHealthService::class);

    $health->recordUnavailable('claude-sonnet-4-6', 'retired');
    $health->recordUnavailable('claude-sonnet-4-6', 'retired again');

    Mail::assertSent(ModelHealthAlertMail::class, 1);
    Mail::assertSent(ModelHealthAlertMail::class, fn (ModelHealthAlertMail $m) => $m->hasTo('ops@example.com') && $m->hasTo('second@example.com'));
});

it('re-alerts after the down mark is cleared', function () {
    Mail::fake();
    $health = app(ModelHealthService::class);

    $health->recordUnavailable('claude-sonnet-4-6', 'retired');
    $health->clearDown('claude-sonnet-4-6');
    $health->recordUnavailable('claude-sonnet-4-6', 'retired');

    Mail::assertSent(ModelHealthAlertMail::class, 2);
});

it('does not send when there are no recipients', function () {
    config(['services.anthropic.alert_recipients' => '']);
    Mail::fake();

    app(ModelHealthService::class)->recordUnavailable('claude-sonnet-4-6', 'retired');

    Mail::assertNothingSent();
});
