<?php

use App\Modules\Purchasing\Settings\PurchasingSettings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layout.app')] class extends Component
{
    #[Validate('required|string|max:20')]
    public string $defaultPayableAccount = '';

    #[Validate('required|numeric|min:0|max:100')]
    public float|string $taxDefaultRate = 15.00;

    #[Validate('required|string|max:20')]
    public string $taxLabel = '';

    #[Validate('required|numeric|min:0|max:1')]
    public float|string $autopostConfidence = 0.90;

    #[Validate('required|numeric|min:0')]
    public float|string $amountTolerance = 10.0;

    #[Validate('required|numeric|min:0|max:100')]
    public float|string $descriptionSimilarity = 60.0;

    public bool $saved = false;

    public function mount(): void
    {
        $this->authorize('access-panel');

        $settings = app(PurchasingSettings::class);
        $this->defaultPayableAccount = $settings->default_payable_account;
        $this->taxDefaultRate = $settings->tax_default_rate;
        $this->taxLabel = $settings->tax_label;
        $this->autopostConfidence = $settings->autopost_confidence;
        $this->amountTolerance = $settings->amount_tolerance;
        $this->descriptionSimilarity = $settings->description_similarity;
    }

    public function save(): void
    {
        $this->validate();

        $settings = app(PurchasingSettings::class);
        $settings->default_payable_account = $this->defaultPayableAccount;
        $settings->tax_default_rate = (float) $this->taxDefaultRate;
        $settings->tax_label = $this->taxLabel;
        $settings->autopost_confidence = (float) $this->autopostConfidence;
        $settings->amount_tolerance = (float) $this->amountTolerance;
        $settings->description_similarity = (float) $this->descriptionSimilarity;
        $settings->save();

        $this->saved = true;
    }
}; ?>

<div>
<div class="max-w-2xl mx-auto px-6 py-8">
    <div class="mb-6">
        <h1 class="text-[17px] font-semibold tracking-tight text-ink">Purchasing Settings</h1>
        <p class="mt-0.5 text-sm text-ink-muted">Default values for purchase invoice processing</p>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div class="space-y-5">
            <h2 class="text-sm font-semibold text-ink border-b border-line pb-2">Defaults</h2>

            <flux:field>
                <flux:label>Default Payable Account <span class="text-danger">*</span></flux:label>
                <flux:input wire:model="defaultPayableAccount" placeholder="2000" class="max-w-xs" />
                <flux:description>Account code used as the default AP account on new invoices</flux:description>
                <flux:error name="defaultPayableAccount" />
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Tax Label <span class="text-danger">*</span></flux:label>
                    <flux:input wire:model="taxLabel" placeholder="VAT" />
                    <flux:error name="taxLabel" />
                </flux:field>

                <flux:field>
                    <flux:label>Default Tax Rate (%) <span class="text-danger">*</span></flux:label>
                    <flux:input wire:model="taxDefaultRate" type="number" step="0.01" min="0" max="100" />
                    <flux:error name="taxDefaultRate" />
                </flux:field>
            </div>
        </div>

        <div class="space-y-5">
            <h2 class="text-sm font-semibold text-ink border-b border-line pb-2">Auto-Posting Thresholds</h2>

            <flux:field>
                <flux:label>Min. LLM Confidence for Auto-Post <span class="text-danger">*</span></flux:label>
                <flux:input wire:model="autopostConfidence" type="number" step="0.01" min="0" max="1" class="max-w-xs" />
                <flux:description>0–1 scale. Invoices below this confidence score are not auto-posted.</flux:description>
                <flux:error name="autopostConfidence" />
            </flux:field>

            <flux:field>
                <flux:label>Amount Tolerance (%) <span class="text-danger">*</span></flux:label>
                <flux:input wire:model="amountTolerance" type="number" step="0.1" min="0" class="max-w-xs" />
                <flux:description>Maximum % difference between current and previous invoice line amounts for pattern-based auto-posting.</flux:description>
                <flux:error name="amountTolerance" />
            </flux:field>

            <flux:field>
                <flux:label>Min. Description Similarity (0–100) <span class="text-danger">*</span></flux:label>
                <flux:input wire:model="descriptionSimilarity" type="number" step="1" min="0" max="100" class="max-w-xs" />
                <flux:description>Minimum similarity score for line description matching in pattern-based auto-posting.</flux:description>
                <flux:error name="descriptionSimilarity" />
            </flux:field>
        </div>

        <div class="flex items-center gap-4 pt-2">
            <flux:button type="submit" variant="primary">Save Changes</flux:button>
            @if($saved)
                <span wire:loading.remove class="text-sm text-success">Saved.</span>
            @endif
        </div>
    </form>
</div>
</div>
