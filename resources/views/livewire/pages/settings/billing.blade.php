<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Models\PaymentTerm;
use App\Modules\Billing\Services\InvoiceEmailTemplateService;
use App\Modules\Billing\Settings\BillingSettings;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use NettSite\NettMail\Models\Template;
use Nettsite\NettMail\Core\Domain\Templates\TemplateType;

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

    #[Validate('nullable|uuid|exists:nettmail_templates,id')]
    public ?string $invoiceEmailTemplateId = null;

    #[Validate('nullable|string|max:200')]
    public string $reminderOffsetsInput = '';

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
        $this->invoiceEmailTemplateId = $settings->invoice_email_template_id;
        $this->reminderOffsetsInput = implode(', ', $settings->reminder_offsets);
    }

    public function save(): void
    {
        $this->validate();

        $offsets = array_values(array_filter(
            array_map('intval', array_map('trim', explode(',', $this->reminderOffsetsInput))),
            fn ($v) => $v !== 0 || str_contains($this->reminderOffsetsInput, '0'),
        ));

        $settings = app(BillingSettings::class);
        $settings->default_receivable_account_id = $this->defaultReceivableAccountId ?: null;
        $settings->default_bank_account_id = $this->defaultBankAccountId ?: null;
        $settings->default_payment_term_id = $this->defaultPaymentTermId ?: null;
        $settings->tax_liability_account_id = $this->taxLiabilityAccountId ?: null;
        $settings->billing_period_day = $this->billingPeriodDay;
        $settings->invoice_email_template_id = $this->invoiceEmailTemplateId ?: null;
        $settings->reminder_offsets = $offsets;
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
            'emailTemplates' => Template::where('type', TemplateType::Transactional)
                ->whereNull('archived_at')
                ->orderBy('name')
                ->get(['id', 'name']),
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

        <div class="space-y-5">
            <h2 class="text-sm font-semibold text-ink border-b border-line pb-2">Email</h2>

            <flux:field>
                <flux:label>Invoice Email Template</flux:label>
                <flux:select wire:model="invoiceEmailTemplateId" class="max-w-xs">
                    <flux:select.option value="">— None —</flux:select.option>
                    @foreach ($emailTemplates as $template)
                        <flux:select.option value="{{ $template->id }}">{{ $template->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:description>Template used for the sales invoice email sent to clients</flux:description>
                <flux:error name="invoiceEmailTemplateId" />
            </flux:field>

            <flux:field>
                <flux:label>Reminder Offsets</flux:label>
                <flux:input wire:model="reminderOffsetsInput" placeholder="-3, 1, 7, 14" class="max-w-xs" />
                <flux:description>
                    Comma-separated business-day offsets from due date. Negative = before due, positive = overdue.
                    e.g. <code>-3, 1, 7, 14</code>
                </flux:description>
                <flux:error name="reminderOffsetsInput" />
            </flux:field>

            <div class="mt-4 rounded-md border border-line bg-surface-alt p-4 text-sm">
                <p class="font-medium text-ink mb-2">Available shortcodes</p>
                <dl class="space-y-1">
                    @foreach (InvoiceEmailTemplateService::availableShortcodes() as $tag => $description)
                        <div class="flex gap-3">
                            <dt class="font-mono text-xs text-ink-soft shrink-0 pt-0.5 select-all">{{ $tag }}</dt>
                            <dd class="text-ink-muted">{{ $description }}</dd>
                        </div>
                    @endforeach
                </dl>
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
