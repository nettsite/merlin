<?php

use App\Modules\Core\Settings\CurrencySettings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layout.app')] class extends Component
{
    #[Validate('required|string|min:3|max:3')]
    public string $baseCurrency = '';

    #[Validate('required|string|max:20')]
    public string $locale = '';

    public bool $saved = false;

    public function mount(): void
    {
        $this->authorize('access-panel');

        $settings = app(CurrencySettings::class);
        $this->baseCurrency = $settings->base_currency;
        $this->locale = $settings->locale;
    }

    public function save(): void
    {
        $this->validate();

        $settings = app(CurrencySettings::class);
        $settings->base_currency = strtoupper($this->baseCurrency);
        $settings->locale = $this->locale;
        $settings->save();

        $this->saved = true;
    }
}; ?>

<div>
<div class="max-w-2xl mx-auto px-6 py-8">
    <div class="mb-6">
        <h1 class="text-[17px] font-semibold tracking-tight text-ink">General Settings</h1>
        <p class="mt-0.5 text-sm text-ink-muted">Currency and localisation configuration</p>
    </div>

    <form wire:submit="save" class="space-y-5">
        <flux:field>
            <flux:label>Base Currency <span class="text-danger">*</span></flux:label>
            <flux:input wire:model="baseCurrency" placeholder="ZAR" class="uppercase max-w-xs" maxlength="3" />
            <flux:description>ISO 4217 three-letter currency code (e.g. ZAR, USD, EUR)</flux:description>
            <flux:error name="baseCurrency" />
        </flux:field>

        <flux:field>
            <flux:label>Locale <span class="text-danger">*</span></flux:label>
            <flux:input wire:model="locale" placeholder="en_ZA" class="max-w-xs" />
            <flux:description>Used for number and currency formatting (e.g. en_ZA, en_US)</flux:description>
            <flux:error name="locale" />
        </flux:field>

        <div class="flex items-center gap-4 pt-2">
            <flux:button type="submit" variant="primary">Save Changes</flux:button>
            @if($saved)
                <span wire:loading.remove class="text-sm text-success">Saved.</span>
            @endif
        </div>
    </form>
</div>
</div>
