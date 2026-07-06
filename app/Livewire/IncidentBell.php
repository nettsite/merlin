<?php

namespace App\Livewire;

use App\Modules\Core\Models\NotificationIncident;
use App\Modules\Core\Services\IncidentSyncService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class IncidentBell extends Component
{
    public function checkIncidents(): void
    {
        if (! $this->authorized()) {
            return;
        }

        app(IncidentSyncService::class)->sync();

        $unseen = NotificationIncident::query()->active()->unseen()->get();

        foreach ($unseen as $incident) {
            $this->dispatch('incident-toast', title: $incident->title, message: $incident->message);
            $incident->update(['seen_at' => now()]);
        }
    }

    public function render(): View
    {
        $incidents = $this->authorized()
            ? NotificationIncident::query()->active()->orderByDesc('triggered_at')->get()
            : collect();

        return view('livewire.incident-bell', [
            'incidents' => $incidents,
        ]);
    }

    private function authorized(): bool
    {
        return Auth::check() && Auth::user()->can('documents-view-any');
    }
}
