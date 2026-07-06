<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\Contracts\IncidentDetector;
use App\Modules\Core\Models\NotificationIncident;

class IncidentSyncService
{
    /** @param IncidentDetector[] $detectors */
    public function __construct(
        private readonly array $detectors,
    ) {}

    /**
     * Run every registered detector, opening a new incident on a 0→active
     * transition, refreshing an already-active one's details (without
     * re-alerting), or clearing it once the condition no longer holds.
     */
    public function sync(): void
    {
        foreach ($this->detectors as $detector) {
            $this->syncOne($detector);
        }
    }

    private function syncOne(IncidentDetector $detector): void
    {
        $result = $detector->check();

        $active = NotificationIncident::query()
            ->ofType($detector->type())
            ->active()
            ->first();

        if ($result === null) {
            $active?->update(['cleared_at' => now()]);

            return;
        }

        if ($active === null) {
            NotificationIncident::create([
                'type' => $detector->type(),
                'title' => $result['title'],
                'message' => $result['message'],
                'metadata' => $result['metadata'] ?? null,
                'triggered_at' => now(),
            ]);

            return;
        }

        // Still active — refresh the details (counts may have moved) without
        // touching seen_at/triggered_at, so it doesn't re-alert.
        $active->update([
            'title' => $result['title'],
            'message' => $result['message'],
            'metadata' => $result['metadata'] ?? null,
        ]);
    }
}
