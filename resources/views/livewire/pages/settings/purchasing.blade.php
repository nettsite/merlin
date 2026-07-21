<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Purchasing\Settings\PurchasingSettings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('components.layout.app')] class extends Component
{
    #[Validate('required|string|max:20')]
    public string $defaultPayableAccount = '';

    #[Validate('nullable|uuid|exists:accounts,id')]
    public ?string $defaultPaymentContraAccountId = null;

    #[Validate('required|numeric|min:0|max:100')]
    public float|string $taxDefaultRate = 15.00;

    #[Validate('required|string|max:20')]
    public string $taxLabel = '';

    #[Validate('required|numeric|min:0|max:1')]
    public float|string $autopostConfidence = 0.90;

    #[Validate('required|numeric|min:0|max:1')]
    public float|string $fallbackConfidence = 0.80;

    #[Validate('required|numeric|min:0')]
    public float|string $amountTolerance = 10.0;

    #[Validate('required|numeric|min:0|max:100')]
    public float|string $descriptionSimilarity = 60.0;

    #[Validate('required|numeric|min:0|max:1')]
    public float|string $paymentMatchAutoConfidence = 0.80;

    public bool $saved = false;

    public function mount(): void
    {
        $this->authorize('access-panel');

        $settings = app(PurchasingSettings::class);
        $this->defaultPayableAccount = $settings->default_payable_account;
        $this->defaultPaymentContraAccountId = $settings->default_payment_contra_account_id;
        $this->taxDefaultRate = $settings->tax_default_rate;
        $this->taxLabel = $settings->tax_label;
        $this->autopostConfidence = $settings->autopost_confidence;
        $this->fallbackConfidence = $settings->fallback_confidence;
        $this->amountTolerance = $settings->amount_tolerance;
        $this->descriptionSimilarity = $settings->description_similarity;
        $this->paymentMatchAutoConfidence = $settings->payment_match_auto_confidence;
    }

    public function save(): void
    {
        $this->validate();

        $settings = app(PurchasingSettings::class);
        $settings->default_payable_account = $this->defaultPayableAccount;
        $settings->default_payment_contra_account_id = $this->defaultPaymentContraAccountId ?: null;
        $settings->tax_default_rate = (float) $this->taxDefaultRate;
        $settings->tax_label = $this->taxLabel;
        $settings->autopost_confidence = (float) $this->autopostConfidence;
        $settings->fallback_confidence = (float) $this->fallbackConfidence;
        $settings->amount_tolerance = (float) $this->amountTolerance;
        $settings->description_similarity = (float) $this->descriptionSimilarity;
        $settings->payment_match_auto_confidence = (float) $this->paymentMatchAutoConfidence;
        $settings->save();

        $this->saved = true;
    }

    /**
     * Unlike the contra account (posted to directly), this is the AP control
     * account new suppliers get their own sub-account created under — so it
     * is deliberately NOT restricted to postable() accounts, since a control
     * account with existing sub-accounts has allow_direct_posting=false (see
     * Account::booted()) and would otherwise be excluded from its own list.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getPayableAccountOptionsProperty(): array
    {
        return Account::active()->orderBy('code')->get(['code', 'name'])
            ->map(fn (Account $account) => ['value' => $account->code, 'label' => "{$account->code} — {$account->name}"])
            ->all();
    }

    /** @return array<int, array{value: string, label: string}> */
    public function getContraAccountOptionsProperty(): array
    {
        return Account::postable()->active()->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn (Account $account) => ['value' => $account->id, 'label' => "{$account->code} — {$account->name}"])
            ->all();
    }
}; ?>

<div>
<div class="max-w-5xl mx-auto px-6 py-8">
    <div class="mb-6">
        <h1 class="text-[17px] font-semibold tracking-tight text-ink">Settings</h1>
        <p class="mt-0.5 text-sm text-ink-muted">Default values for purchase invoice processing</p>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-x-8 gap-y-6 items-start">
        <div class="space-y-5">
            <h2 class="text-sm font-semibold text-ink border-b border-line pb-2">Defaults</h2>

            <flux:field>
                <flux:label>Default Payable Account <span class="text-danger">*</span></flux:label>
                <x-searchable-select model="defaultPayableAccount" :options="$this->payableAccountOptions" placeholder="Select an account…" :nullable="false" class="max-w-xs" />
                <flux:description>Account used as the default AP account on new invoices</flux:description>
                <flux:error name="defaultPayableAccount" />
            </flux:field>

            <flux:field>
                <flux:label>Default Payment Contra Account</flux:label>
                <x-searchable-select model="defaultPaymentContraAccountId" :options="$this->contraAccountOptions" placeholder="— None —" class="max-w-xs" />
                <flux:description>Account credited when a purchase invoice payment is recorded — usually the bank account, or Drawings/a loan account if payments are made from a personal card</flux:description>
                <flux:error name="defaultPaymentContraAccountId" />
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
                <flux:label>Min. Fast-Model Confidence (fallback) <span class="text-danger">*</span></flux:label>
                <flux:input wire:model="fallbackConfidence" type="number" step="0.01" min="0" max="1" class="max-w-xs" />
                <flux:description>0–1 scale. Fast-model extractions below this confidence are re-run on the stronger model.</flux:description>
                <flux:error name="fallbackConfidence" />
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

            <flux:field>
                <flux:label>Min. Confidence for Payment Notification Auto-Match <span class="text-danger">*</span></flux:label>
                <flux:input wire:model="paymentMatchAutoConfidence" type="number" step="0.01" min="0" max="1" class="max-w-xs" />
                <flux:description>0–1 scale. A payment notification (PayPal/FNB Connect receipt) matched to an invoice below this confidence is surfaced for manual confirmation instead of auto-merging.</flux:description>
                <flux:error name="paymentMatchAutoConfidence" />
            </flux:field>
        </div>
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
