<?php

namespace App\Livewire;

use App\Modules\Core\Models\NotificationIncident;
use App\Modules\Core\Services\IncidentSyncService;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

class IncidentDashboardPanel extends Component
{
    public function render(): View
    {
        $incidents = collect();

        if (Auth::check() && Auth::user()->can('documents-view-any')) {
            app(IncidentSyncService::class)->sync();

            $incidents = NotificationIncident::query()->active()->orderByDesc('triggered_at')->get();
        }

        return view('livewire.incident-dashboard-panel', [
            'incidents' => $incidents,
        ]);
    }
}
