<?php

namespace App\Modules\Core\Contracts;

interface IncidentDetector
{
    /**
     * Stable identifier for this incident type — stored on NotificationIncident.type.
     */
    public function type(): string;

    /**
     * Check whether the incident condition currently holds.
     *
     * @return array{title: string, message: string, metadata?: array<string, mixed>}|null
     *                                                                                     Details for an active incident, or null if the condition doesn't hold.
     */
    public function check(): ?array;
}
