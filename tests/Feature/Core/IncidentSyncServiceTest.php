<?php

use App\Modules\Core\Contracts\IncidentDetector;
use App\Modules\Core\Models\NotificationIncident;
use App\Modules\Core\Services\IncidentSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A detector stub whose check() result is controlled per-test — decouples
 * IncidentSyncService's create/refresh/clear logic from any real detector.
 */
function fakeDetector(?array $result): IncidentDetector
{
    return new class($result) implements IncidentDetector
    {
        public function __construct(private readonly ?array $result) {}

        public function type(): string
        {
            return 'test_incident';
        }

        public function check(): ?array
        {
            return $this->result;
        }
    };
}

it('creates a new active incident on a 0-to-active transition', function (): void {
    $service = new IncidentSyncService([
        fakeDetector(['title' => 'Test', 'message' => 'Something is wrong.']),
    ]);

    $service->sync();

    $incident = NotificationIncident::query()->ofType('test_incident')->active()->first();

    expect($incident)->not->toBeNull()
        ->and($incident->title)->toBe('Test')
        ->and($incident->seen_at)->toBeNull()
        ->and($incident->cleared_at)->toBeNull();
});

it('does not create a duplicate incident while one is already active', function (): void {
    $service = new IncidentSyncService([
        fakeDetector(['title' => 'Test', 'message' => 'First.']),
    ]);

    $service->sync();
    $service->sync();
    $service->sync();

    expect(NotificationIncident::query()->ofType('test_incident')->count())->toBe(1);
});

it('refreshes the message on an already-active incident without touching seen_at', function (): void {
    $service = new IncidentSyncService([fakeDetector(['title' => 'Test', 'message' => 'First.'])]);
    $service->sync();

    $incident = NotificationIncident::query()->ofType('test_incident')->first();
    $incident->update(['seen_at' => now()]);

    $service2 = new IncidentSyncService([fakeDetector(['title' => 'Test', 'message' => 'Updated.'])]);
    $service2->sync();

    $incident->refresh();

    expect($incident->message)->toBe('Updated.')
        ->and($incident->seen_at)->not->toBeNull();
});

it('clears an active incident once the condition no longer holds', function (): void {
    $service = new IncidentSyncService([fakeDetector(['title' => 'Test', 'message' => 'First.'])]);
    $service->sync();

    $clearingService = new IncidentSyncService([fakeDetector(null)]);
    $clearingService->sync();

    $incident = NotificationIncident::query()->ofType('test_incident')->first();

    expect($incident->cleared_at)->not->toBeNull()
        ->and(NotificationIncident::query()->ofType('test_incident')->active()->exists())->toBeFalse();
});

it('opens a fresh incident again after a previous one cleared', function (): void {
    $service = new IncidentSyncService([fakeDetector(['title' => 'Test', 'message' => 'First.'])]);
    $service->sync();
    (new IncidentSyncService([fakeDetector(null)]))->sync();

    (new IncidentSyncService([fakeDetector(['title' => 'Test', 'message' => 'Second.'])]))->sync();

    expect(NotificationIncident::query()->ofType('test_incident')->count())->toBe(2)
        ->and(NotificationIncident::query()->ofType('test_incident')->active()->first()->message)->toBe('Second.');
});
