<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Models\PaymentTerm;
use App\Modules\Billing\Settings\BillingSettings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layout.app')] class extends Component
{
    #[Validate('nullable|uuid|exists:accounts,id')]
    public ?string $defaultReceivableAccountId = null;

    #[Validate('nullable|uuid|exists:accounts,id')]
    public ?string $defaultBankAccountId = null;

    #[Validate('nullable|uuid|exists:payment_terms,id')]
    public ?string $defaultPaymentTermId = null;

    #[Validate('nullable|uuid|exists:accounts,id')]
    public ?string $taxLiabilityAccountId = null;

    #[Validate('required|integer|min:1|max:28')]
    public int $billingPeriodDay = 1;

    public bool $saved = false;

    public function mount(): void
    {
        $this->authorize('access-panel');

        $settings = app(BillingSettings::class);
        $this->defaultReceivableAccountId = $settings->default_receivable_account_id;
        $this->defaultBankAccountId = $settings->default_bank_account_id;
        $this->defaultPaymentTermId = $settings->default_payment_term_id;
        $this->taxLiabilityAccountId = $settings->tax_liability_account_id;
        $this->billingPeriodDay = $settings->billing_period_day;
    }

    public function save(): void
    {
        $this->validate();

        $settings = app(BillingSettings::class);
        $settings->default_receivable_account_id = $this->defaultReceivableAccountId ?: null;
        $settings->default_bank_account_id = $this->defaultBankAccountId ?: null;
        $settings->default_payment_term_id = $this->defaultPaymentTermId ?: null;
        $settings->tax_liability_account_id = $this->taxLiabilityAccountId ?: null;
        $settings->billing_period_day = $this->billingPeriodDay;
        $settings->save();

        $this->saved = true;
    }

    public function with(): array
    {
        return [
            'assetAccounts' => Account::postable()->active()
                ->whereHas('group.type', fn ($q) => $q->where('code', '1'))
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'liabilityAccounts' => Account::postable()->active()
                ->whereHas('group.type', fn ($q) => $q->where('code', '2'))
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'paymentTerms' => PaymentTerm::orderBy('name')->get(['id', 'name']),
        ];
    }
}; ?>

<div>
<div class="max-w-2xl mx-auto px-6 py-8">
    <div class="mb-6">
        <h1 class="text-[17px] font-semibold tracking-tight text-ink">Billing Settings</h1>
        <p class="mt-0.5 text-sm text-ink-muted">Default values for sales invoices and payment recording</p>
    </div>

    <form wire:submit="save" class="space-y-6">
        <div class="space-y-5">
            <h2 class="text-sm font-semibold text-ink border-b border-line pb-2">Accounts</h2>

            <flux:field>
                <flux:label>Default Receivable Account</flux:label>
                <flux:select wire:model="defaultReceivableAccountId" class="max-w-xs">
                    <flux:select.option value="">— None —</flux:select.option>
                    @foreach ($assetAccounts as $account)
                        <flux:select.option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:description>AR control account used on all sales invoices</flux:description>
                <flux:error name="defaultReceivableAccountId" />
            </flux:field>

            <flux:field>
                <flux:label>Default Bank Account</flux:label>
                <flux:select wire:model="defaultBankAccountId" class="max-w-xs">
                    <flux:select.option value="">— None —</flux:select.option>
                    @foreach ($assetAccounts as $account)
                        <flux:select.option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:description>Bank account pre-selected when recording payments</flux:description>
                <flux:error name="defaultBankAccountId" />
            </flux:field>

            <flux:field>
                <flux:label>Tax Liability Account</flux:label>
                <flux:select wire:model="taxLiabilityAccountId" class="max-w-xs">
                    <flux:select.option value="">— None —</flux:select.option>
                    @foreach ($liabilityAccounts as $account)
                        <flux:select.option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:description>Liability account for output tax on sales invoices</flux:description>
                <flux:error name="taxLiabilityAccountId" />
            </flux:field>
        </div>

        <div class="space-y-5">
            <h2 class="text-sm font-semibold text-ink border-b border-line pb-2">Defaults</h2>

            <flux:field>
                <flux:label>Default Payment Terms</flux:label>
                <flux:select wire:model="defaultPaymentTermId" class="max-w-xs">
                    <flux:select.option value="">— None —</flux:select.option>
                    @foreach ($paymentTerms as $term)
                        <flux:select.option value="{{ $term->id }}">{{ $term->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:description>Applied when a client has no payment term configured</flux:description>
                <flux:error name="defaultPaymentTermId" />
            </flux:field>

            <flux:field>
                <flux:label>Billing Period Day <span class="text-danger">*</span></flux:label>
                <flux:input wire:model="billingPeriodDay" type="number" min="1" max="28" class="max-w-xs" />
                <flux:description>Day of month on which billing periods begin (1–28)</flux:description>
                <flux:error name="billingPeriodDay" />
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
