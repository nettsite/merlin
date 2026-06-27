<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Component;

class SystemAlerts extends Component
{
    public bool $dismissedCredit = false;

    public function dismissCredit(): void
    {
        $this->dismissedCredit = true;
    }

    public function render(): View
    {
        return view('livewire.system-alerts', [
            'creditAlert' => ! $this->dismissedCredit ? Cache::get('anthropic:credit_exhausted') : null,
        ]);
    }
}
