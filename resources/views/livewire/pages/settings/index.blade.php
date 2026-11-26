<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Settings\AccountingSettings;
use App\Modules\Billing\Models\BillingEmailTemplate;
use App\Modules\Core\Models\PaymentTerm;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Settings\CompanySettings;
use App\Modules\Core\Settings\CurrencySettings;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use NettSite\NettMail\Models\Template;
use Nettsite\NettMail\Core\Domain\Templates\TemplateType;

new #[Layout('components.layout.app')] class extends Component
{
    use WithFileUploads;

    #[Url]
    public string $tab = 'general';

    public bool $saved = false;

    // ── General / Company ──────────────────────────────────────────────────
    public string $companyName = '';

    public string $addressLine1 = '';

    public string $addressLine2 = '';

    public string $city = '';

    public string $stateProvince = '';

    public string $postalCode = '';

    public string $country = '';

    public string $phone = '';

    public string $companyEmail = '';

    public string $website = '';

    public string $taxNumber = '';

    public $logo = null;

    public string $emailLogoUrl = '';

    public ?string $existingLogoUrl = null;

    // ── General / Outgoing Email ───────────────────────────────────────────
    public string $emailFromName = '';

    public string $emailFromAddress = '';

    public string $emailReplyToAddress = '';

    // ── General / Banking Details ──────────────────────────────────────────
    public string $bankName = '';

    public string $bankAccountName = '';

    public string $bankAccountNumber = '';

    public string $bankBranchCode = '';

    // ── General / Currency ─────────────────────────────────────────────────
    public string $baseCurrency = '';

    public string $locale = '';

    // ── Accounting ─────────────────────────────────────────────────────────
    public int $financialYearStartMonth = 3;

    // ── Billing ────────────────────────────────────────────────────────────
    public ?string $defaultReceivableAccountId = null;

    public ?string $defaultContraAccountId = null;

    public ?string $defaultPaymentTermId = null;

    public ?string $taxLiabilityAccountId = null;

    public int $billingPeriodDay = 1;

    public ?string $invoiceEmailTemplateId = null;

    public ?string $paymentEmailTemplateId = null;

    public ?int $nextReceiptNumber = null;

    public ?int $nextInvoiceNumber = null;

    public string $defaultInvoiceTerms = '';

    public string $defaultInvoiceFooter = '';

    // ── Reminder emails (Billing tab) ────────────────────────────────────────
    // Keyed by BillingEmailTemplate id. Each reminder now always points at a
    // NettMail template for its content — no legacy subject/body fallback.
    /** @var array<string, array{name: string, nettmail_template_id: ?string, offset_days: int, enabled: bool}> */
    public array $reminders = [];

    public function mount(): void
    {
        $this->authorize('access-panel');

        $company = app(CompanySettings::class);
        $this->companyName = $company->name;
        $this->addressLine1 = $company->address_line_1;
        $this->addressLine2 = $company->address_line_2;
        $this->city = $company->city;
        $this->stateProvince = $company->state_province;
        $this->postalCode = $company->postal_code;
        $this->country = $company->country;
        $this->phone = $company->phone;
        $this->companyEmail = $company->email;
        $this->website = $company->website;
        $this->emailLogoUrl = $company->email_logo_url;
        $this->taxNumber = $company->tax_number;
        $this->emailFromName = $company->email_from_name;
        $this->emailFromAddress = $company->email_from_address;
        $this->emailReplyToAddress = $company->email_reply_to_address;
        $this->bankName = $company->bank_name;
        $this->bankAccountName = $company->bank_account_name;
        $this->bankAccountNumber = $company->bank_account_number;
        $this->bankBranchCode = $company->bank_branch_code;

        if ($company->logo_path && Storage::disk('public')->exists($company->logo_path)) {
            $this->existingLogoUrl = Storage::disk('public')->url($company->logo_path);
        }

        $currency = app(CurrencySettings::class);
        $this->baseCurrency = $currency->base_currency;
        $this->locale = $currency->locale;

        $accounting = app(AccountingSettings::class);
        $this->financialYearStartMonth = $accounting->financial_year_start_month;

        $billing = app(BillingSettings::class);
        $this->defaultReceivableAccountId = $billing->default_receivable_account_id;
        $this->defaultContraAccountId = $billing->default_contra_account_id;
        $this->defaultPaymentTermId = $billing->default_payment_term_id;
        $this->taxLiabilityAccountId = $billing->tax_liability_account_id;
        $this->billingPeriodDay = $billing->billing_period_day;
        $this->invoiceEmailTemplateId = $billing->invoice_email_template_id;
        $this->paymentEmailTemplateId = $billing->payment_email_template_id;
        $this->nextReceiptNumber = $billing->next_receipt_number;
        $this->nextInvoiceNumber = $billing->next_invoice_number;
        $this->defaultInvoiceTerms = $billing->default_invoice_terms;
        $this->defaultInvoiceFooter = $billing->default_invoice_footer;

        $this->reminders = BillingEmailTemplate::reminder()->orderBy('offset_days')->get()
            ->mapWithKeys(fn (BillingEmailTemplate $r) => [$r->id => [
                'name' => $r->name,
                'nettmail_template_id' => $r->nettmail_template_id,
                'offset_days' => $r->offset_days,
                'enabled' => $r->enabled,
            ]])
            ->all();
    }

    public function updatedTab(): void
    {
        $this->saved = false;
    }

    public function save(): void
    {
        $this->authorize('access-panel');

        $this->saved = false;

        match ($this->tab) {
            'general' => $this->saveGeneral(),
            'accounting' => $this->saveAccounting(),
            'billing' => $this->saveBilling(),
            default => null,
        };
    }

    private function saveGeneral(): void
    {
        $this->emailLogoUrl = trim($this->emailLogoUrl);

        $this->validate([
            'companyName' => 'required|string|max:255',
            'addressLine1' => 'nullable|string|max:255',
            'addressLine2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'stateProvince' => 'nullable|string|max:100',
            'postalCode' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
            'phone' => 'nullable|string|max:50',
            'companyEmail' => 'nullable|email|max:255',
            'website' => 'nullable|url|max:255',
            'taxNumber' => 'nullable|string|max:50',
            'emailFromName' => 'required|string|max:255',
            'emailFromAddress' => 'required|email|max:255',
            'emailReplyToAddress' => 'required|email|max:255',
            'bankName' => 'nullable|string|max:100',
            'bankAccountName' => 'nullable|string|max:100',
            'bankAccountNumber' => 'nullable|string|max:30',
            'bankBranchCode' => 'nullable|string|max:20',
            'logo' => 'nullable|image|max:2048',
            'emailLogoUrl' => 'nullable|url:https|max:500',
            'baseCurrency' => 'required|string|min:3|max:3',
            'locale' => 'required|string|max:20',
        ]);

        $company = app(CompanySettings::class);
        $company->name = $this->companyName;
        $company->address_line_1 = $this->addressLine1;
        $company->address_line_2 = $this->addressLine2;
        $company->city = $this->city;
        $company->state_province = $this->stateProvince;
        $company->postal_code = $this->postalCode;
        $company->country = $this->country;
        $company->phone = $this->phone;
        $company->email = $this->companyEmail;
        $company->website = $this->website;
        $company->email_logo_url = $this->emailLogoUrl;
        $company->tax_number = $this->taxNumber;
        $company->email_from_name = $this->emailFromName;
        $company->email_from_address = $this->emailFromAddress;
        $company->email_reply_to_address = $this->emailReplyToAddress;
        $company->bank_name = trim($this->bankName);
        $company->bank_account_name = trim($this->bankAccountName);
        $company->bank_account_number = trim($this->bankAccountNumber);
        $company->bank_branch_code = trim($this->bankBranchCode);

        if ($this->logo !== null) {
            if ($company->logo_path) {
                Storage::disk('public')->delete($company->logo_path);
            }

            $path = $this->logo->store('company', 'public');
            $company->logo_path = $path;
            $this->existingLogoUrl = Storage::disk('public')->url($path);
            $this->logo = null;
        }

        $company->save();

        $currency = app(CurrencySettings::class);
        $currency->base_currency = strtoupper($this->baseCurrency);
        $currency->locale = $this->locale;
        $currency->save();

        $this->saved = true;
    }

    public function removeLogo(): void
    {
        $this->authorize('access-panel');

        $company = app(CompanySettings::class);

        if ($company->logo_path) {
            Storage::disk('public')->delete($company->logo_path);
            $company->logo_path = null;
            $company->save();
        }

        $this->existingLogoUrl = null;
        $this->logo = null;
    }

    private function saveAccounting(): void
    {
        $this->validate([
            'financialYearStartMonth' => 'required|integer|min:1|max:12',
        ]);

        $settings = app(AccountingSettings::class);
        $settings->financial_year_start_month = $this->financialYearStartMonth;
        $settings->save();

        $this->saved = true;
    }

    private function saveBilling(): void
    {
        $this->validate([
            'defaultReceivableAccountId' => 'nullable|uuid|exists:accounts,id',
            'defaultContraAccountId' => 'nullable|uuid|exists:accounts,id',
            'defaultPaymentTermId' => 'nullable|uuid|exists:payment_terms,id',
            'taxLiabilityAccountId' => 'nullable|uuid|exists:accounts,id',
            'billingPeriodDay' => 'required|integer|min:1|max:28',
            'invoiceEmailTemplateId' => 'required|uuid|exists:nettmail_templates,id',
            'paymentEmailTemplateId' => 'required|uuid|exists:nettmail_templates,id',
            'nextReceiptNumber' => 'nullable|integer|min:1',
            'nextInvoiceNumber' => 'nullable|integer|min:1',
            'defaultInvoiceTerms' => 'nullable|string|max:2000',
            'defaultInvoiceFooter' => 'nullable|string|max:2000',
            'reminders.*.nettmail_template_id' => 'required|uuid|exists:nettmail_templates,id',
            'reminders.*.offset_days' => 'required|integer',
            'reminders.*.enabled' => 'boolean',
        ]);

        $settings = app(BillingSettings::class);
        $settings->default_receivable_account_id = $this->defaultReceivableAccountId ?: null;
        $settings->default_contra_account_id = $this->defaultContraAccountId ?: null;
        $settings->default_payment_term_id = $this->defaultPaymentTermId ?: null;
        $settings->tax_liability_account_id = $this->taxLiabilityAccountId ?: null;
        $settings->billing_period_day = $this->billingPeriodDay;
        $settings->invoice_email_template_id = $this->invoiceEmailTemplateId ?: null;
        $settings->payment_email_template_id = $this->paymentEmailTemplateId ?: null;
        $settings->next_receipt_number = $this->nextReceiptNumber ?: null;
        $settings->next_invoice_number = $this->nextInvoiceNumber ?: null;
        $settings->default_invoice_terms = $this->defaultInvoiceTerms;
        $settings->default_invoice_footer = $this->defaultInvoiceFooter;
        $settings->save();

        foreach ($this->reminders as $id => $reminder) {
            $template = BillingEmailTemplate::find($id);

            if ($template === null) {
                continue;
            }

            $template->nettmail_template_id = $reminder['nettmail_template_id'];
            $template->offset_days = $reminder['offset_days'];
            $template->enabled = $reminder['enabled'];
            $template->save();
        }

        $this->saved = true;
    }

    public function with(): array
    {
        $data = [];

        if ($this->tab === 'billing') {
            $data['assetAccounts'] = Account::postable()->active()
                ->whereHas('group.type', fn ($q) => $q->where('code', '1'))
                ->orderBy('code')
                ->get(['id', 'code', 'name']);
            $data['liabilityAccounts'] = Account::postable()->active()
                ->whereHas('group.type', fn ($q) => $q->where('code', '2'))
                ->orderBy('code')
                ->get(['id', 'code', 'name']);
            $data['paymentTerms'] = PaymentTerm::orderBy('name')->get(['id', 'name']);
            $data['emailTemplates'] = Template::where('type', TemplateType::Transactional)
                ->whereNull('archived_at')
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        return $data;
    }
}; ?>

<div>
    {{-- Sticky header --}}
    <div class="sticky top-0 z-10 bg-white border-b border-line">
        <div class="flex items-center justify-between px-6 pt-5 pb-0">
            <h1 class="text-[17px] font-semibold tracking-tight text-ink">Settings</h1>

            @if($tab !== 'roles')
                <div class="flex items-center gap-3 pb-4">
                    @if($saved)
                        <span wire:loading.remove wire:target="save" class="text-sm text-success">Saved.</span>
                    @endif
                    <flux:button wire:click="save" wire:loading.attr="disabled" variant="primary" size="sm">
                        Save Changes
                    </flux:button>
                </div>
            @endif
        </div>

        {{-- Tab bar --}}
        <div class="flex gap-0 px-6">
            @foreach(['general' => 'General', 'accounting' => 'Accounting', 'billing' => 'Billing', 'roles' => 'Roles'] as $key => $label)
                <button
                    type="button"
                    wire:click="$set('tab', '{{ $key }}')"
                    class="px-4 py-2.5 text-sm font-medium border-b-2 transition-colors
                        {{ $tab === $key
                            ? 'border-accent text-accent'
                            : 'border-transparent text-ink-muted hover:text-ink hover:border-line' }}"
                >{{ $label }}</button>
            @endforeach
        </div>
    </div>

    {{-- Tab content --}}
    @if($tab === 'roles')
        <livewire:pages.roles.index />

    @else
        <div class="max-w-5xl mx-auto px-6 py-2 divide-y divide-line">

            {{-- ── GENERAL TAB ─────────────────────────────────── --}}
            @if($tab === 'general')

                {{-- Company Details --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 py-8">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">Company Details</h3>
                        <p class="mt-1 text-sm text-ink-muted">Name, contact info, and tax registration.</p>
                    </div>
                    <div class="col-span-2 space-y-5">
                        <flux:field>
                            <flux:label>Company Name <span class="text-danger">*</span></flux:label>
                            <flux:input wire:model="companyName" placeholder="Acme (Pty) Ltd" />
                            <flux:error name="companyName" />
                        </flux:field>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>Phone</flux:label>
                                <flux:input wire:model="phone" placeholder="+27 11 000 0000" />
                                <flux:error name="phone" />
                            </flux:field>
                            <flux:field>
                                <flux:label>Email</flux:label>
                                <flux:input type="email" wire:model="companyEmail" placeholder="accounts@company.co.za" />
                                <flux:error name="companyEmail" />
                            </flux:field>
                        </div>

                        <flux:field>
                            <flux:label>Website</flux:label>
                            <flux:input type="url" wire:model="website" placeholder="https://company.co.za" class="max-w-sm" />
                            <flux:error name="website" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Tax / VAT Number</flux:label>
                            <flux:input wire:model="taxNumber" placeholder="4123456789" class="max-w-xs" />
                            <flux:error name="taxNumber" />
                        </flux:field>
                    </div>
                </div>

                {{-- Address --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 py-8">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">Address</h3>
                        <p class="mt-1 text-sm text-ink-muted">Printed on invoices and quotes.</p>
                    </div>
                    <div class="col-span-2 space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>Address Line 1</flux:label>
                                <flux:input wire:model="addressLine1" placeholder="123 Main Street" />
                                <flux:error name="addressLine1" />
                            </flux:field>
                            <flux:field>
                                <flux:label>Address Line 2</flux:label>
                                <flux:input wire:model="addressLine2" placeholder="Suite 4" />
                                <flux:error name="addressLine2" />
                            </flux:field>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <flux:field>
                                <flux:label>City</flux:label>
                                <flux:input wire:model="city" placeholder="Johannesburg" />
                                <flux:error name="city" />
                            </flux:field>
                            <flux:field>
                                <flux:label>Province / State</flux:label>
                                <flux:input wire:model="stateProvince" placeholder="Gauteng" />
                                <flux:error name="stateProvince" />
                            </flux:field>
                            <flux:field>
                                <flux:label>Postal Code</flux:label>
                                <flux:input wire:model="postalCode" placeholder="2000" />
                                <flux:error name="postalCode" />
                            </flux:field>
                        </div>

                        <flux:field>
                            <flux:label>Country</flux:label>
                            <flux:input wire:model="country" placeholder="South Africa" class="max-w-xs" />
                            <flux:error name="country" />
                        </flux:field>
                    </div>
                </div>

                {{-- Outgoing Email --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 py-8">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">Outgoing Email</h3>
                        <p class="mt-1 text-sm text-ink-muted">Sender on invoices and other emails to clients.</p>
                    </div>
                    <div class="col-span-2 space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>From Name <span class="text-danger">*</span></flux:label>
                                <flux:input wire:model="emailFromName" placeholder="NettSite" />
                                <flux:error name="emailFromName" />
                            </flux:field>
                            <flux:field>
                                <flux:label>From Address <span class="text-danger">*</span></flux:label>
                                <flux:input type="email" wire:model="emailFromAddress" placeholder="support@company.co.za" />
                                <flux:error name="emailFromAddress" />
                            </flux:field>
                        </div>

                        <flux:field>
                            <flux:label>Reply-To Address <span class="text-danger">*</span></flux:label>
                            <flux:input type="email" wire:model="emailReplyToAddress" placeholder="support@company.co.za" class="max-w-xs" />
                            <flux:error name="emailReplyToAddress" />
                        </flux:field>
                    </div>
                </div>

                {{-- Banking Details --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 py-8">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">Banking Details</h3>
                        <p class="mt-1 text-sm text-ink-muted">Printed on invoices so clients can pay by EFT.</p>
                    </div>
                    <div class="col-span-2 space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>Bank</flux:label>
                                <flux:input wire:model="bankName" placeholder="First National Bank" />
                                <flux:error name="bankName" />
                            </flux:field>
                            <flux:field>
                                <flux:label>Account Name</flux:label>
                                <flux:input wire:model="bankAccountName" placeholder="Acme (Pty) Ltd" />
                                <flux:error name="bankAccountName" />
                            </flux:field>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>Account Number</flux:label>
                                <flux:input wire:model="bankAccountNumber" placeholder="62000000000" />
                                <flux:error name="bankAccountNumber" />
                            </flux:field>
                            <flux:field>
                                <flux:label>Branch Code</flux:label>
                                <flux:input wire:model="bankBranchCode" placeholder="250655" />
                                <flux:error name="bankBranchCode" />
                            </flux:field>
                        </div>
                    </div>
                </div>

                {{-- Logo --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 py-8">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">Logo</h3>
                        <p class="mt-1 text-sm text-ink-muted">Shown on PDFs and the portal. PNG, JPG or SVG, max 2 MB.</p>
                    </div>
                    <div class="col-span-2 space-y-4">
                        @if($existingLogoUrl)
                            <div class="flex items-center gap-4">
                                <img src="{{ $existingLogoUrl }}" alt="Company logo" class="h-16 max-w-[200px] object-contain border border-line rounded-sm p-1 bg-white">
                                <flux:button type="button" wire:click="removeLogo" variant="ghost" size="sm" class="text-danger">Remove</flux:button>
                            </div>
                        @endif

                        @if($logo)
                            <img src="{{ $logo->temporaryUrl() }}" alt="Preview" class="h-16 max-w-[200px] object-contain border border-line rounded-sm p-1 bg-white">
                        @endif

                        <flux:field>
                            <flux:label>{{ $existingLogoUrl ? 'Replace Logo' : 'Upload Logo' }}</flux:label>
                            <input
                                type="file"
                                wire:model="logo"
                                accept="image/*"
                                class="block text-sm text-ink-muted file:mr-3 file:py-1.5 file:px-3 file:rounded-sm file:border file:border-line file:text-sm file:bg-surface-alt file:text-ink hover:file:bg-surface cursor-pointer"
                            />
                            <flux:error name="logo" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Email Logo URL</flux:label>
                            <flux:input type="url" wire:model="emailLogoUrl" placeholder="https://company.co.za/logo.png" />
                            <flux:description>Public web address of your logo for emails. Needed when Merlin is not reachable from the internet; leave blank to use the uploaded logo.</flux:description>
                            <flux:error name="emailLogoUrl" />
                        </flux:field>
                    </div>
                </div>

                {{-- Currency --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 py-8">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">Currency & Localisation</h3>
                        <p class="mt-1 text-sm text-ink-muted">Controls how amounts are formatted across the app.</p>
                    </div>
                    <div class="col-span-2">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>Base Currency <span class="text-danger">*</span></flux:label>
                                <flux:input wire:model="baseCurrency" placeholder="ZAR" class="uppercase" maxlength="3" />
                                <flux:description>ISO 4217 three-letter code (e.g. ZAR, USD, EUR)</flux:description>
                                <flux:error name="baseCurrency" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Locale <span class="text-danger">*</span></flux:label>
                                <flux:input wire:model="locale" placeholder="en_ZA" />
                                <flux:description>Number and currency formatting (e.g. en_ZA, en_US)</flux:description>
                                <flux:error name="locale" />
                            </flux:field>
                        </div>
                    </div>
                </div>

            {{-- ── ACCOUNTING TAB ──────────────────────────────── --}}
            @elseif($tab === 'accounting')

                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 py-8">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">Financial Year</h3>
                        <p class="mt-1 text-sm text-ink-muted">Sets the start month of the financial year. All reports default to the current financial year period.</p>
                    </div>
                    <div class="col-span-2">
                        <flux:field class="max-w-xs">
                            <flux:label>Financial Year Start Month <span class="text-danger">*</span></flux:label>
                            <flux:select wire:model="financialYearStartMonth">
                                @foreach(['1' => 'January', '2' => 'February', '3' => 'March', '4' => 'April', '5' => 'May', '6' => 'June', '7' => 'July', '8' => 'August', '9' => 'September', '10' => 'October', '11' => 'November', '12' => 'December'] as $num => $name)
                                    <flux:select.option value="{{ $num }}">{{ $name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:description>Default: March (FY runs March – end of February)</flux:description>
                            <flux:error name="financialYearStartMonth" />
                        </flux:field>
                    </div>
                </div>

            {{-- ── BILLING TAB ─────────────────────────────────── --}}
            @elseif($tab === 'billing')

                {{-- Accounts --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 py-8">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">Accounts</h3>
                        <p class="mt-1 text-sm text-ink-muted">Default GL accounts for sales invoices and payment recording.</p>
                    </div>
                    <div class="col-span-2 space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>Default Receivable Account</flux:label>
                                <flux:select wire:model="defaultReceivableAccountId">
                                    <flux:select.option value="">— None —</flux:select.option>
                                    @foreach ($assetAccounts as $account)
                                        <flux:select.option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:description>AR control account on sales invoices</flux:description>
                                <flux:error name="defaultReceivableAccountId" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Default Bank Account</flux:label>
                                <flux:select wire:model="defaultContraAccountId">
                                    <flux:select.option value="">— None —</flux:select.option>
                                    @foreach ($assetAccounts as $account)
                                        <flux:select.option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:description>Pre-selected when recording payments</flux:description>
                                <flux:error name="defaultContraAccountId" />
                            </flux:field>
                        </div>

                        <flux:field>
                            <flux:label>Tax Liability Account</flux:label>
                            <flux:select wire:model="taxLiabilityAccountId" class="max-w-sm">
                                <flux:select.option value="">— None —</flux:select.option>
                                @foreach ($liabilityAccounts as $account)
                                    <flux:select.option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:description>Liability account for output tax on sales invoices</flux:description>
                            <flux:error name="taxLiabilityAccountId" />
                        </flux:field>
                    </div>
                </div>

                {{-- Defaults --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 py-8">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">Defaults</h3>
                        <p class="mt-1 text-sm text-ink-muted">Values pre-selected when creating invoices.</p>
                    </div>
                    <div class="col-span-2 space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>Default Payment Terms</flux:label>
                                <flux:select wire:model="defaultPaymentTermId">
                                    <flux:select.option value="">— None —</flux:select.option>
                                    @foreach ($paymentTerms as $term)
                                        <flux:select.option value="{{ $term->id }}">{{ $term->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:description>Applied when a client has no payment term</flux:description>
                                <flux:error name="defaultPaymentTermId" />
                            </flux:field>

                            <flux:field>
                                <flux:label>Billing Period Day <span class="text-danger">*</span></flux:label>
                                <flux:input wire:model="billingPeriodDay" type="number" min="1" max="28" />
                                <flux:description>Day of month billing periods begin (1–28)</flux:description>
                                <flux:error name="billingPeriodDay" />
                            </flux:field>
                        </div>
                    </div>
                </div>

                {{-- Numbering --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 py-8">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">Numbering</h3>
                        <p class="mt-1 text-sm text-ink-muted">Controls how invoices and payments are numbered.</p>
                    </div>
                    <div class="col-span-2 space-y-5">
                        <flux:field>
                            <flux:label>Next Invoice Number</flux:label>
                            <flux:input wire:model="nextInvoiceNumber" type="number" min="1" class="max-w-xs" placeholder="Automatic" />
                            <flux:description>The number the next sales invoice will get. Leave blank to keep counting automatically from the highest existing number. Advances on its own after each invoice, so you only need to set this to jump the sequence forward.</flux:description>
                            <flux:error name="nextInvoiceNumber" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Next Receipt Number</flux:label>
                            <flux:input wire:model="nextReceiptNumber" type="number" min="1" class="max-w-xs" placeholder="Automatic" />
                            <flux:description>The number the next payment will get. Leave blank to keep counting automatically from the highest existing number. Advances on its own after each payment, so you only need to set this to jump the sequence forward.</flux:description>
                            <flux:error name="nextReceiptNumber" />
                        </flux:field>
                    </div>
                </div>

                {{-- Invoice Document --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 py-8">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">Invoice Document</h3>
                        <p class="mt-1 text-sm text-ink-muted">Printed on invoices that have no terms or footer of their own.</p>
                    </div>
                    <div class="col-span-2 space-y-5">
                        <flux:field>
                            <flux:label>Default Invoice Terms</flux:label>
                            <flux:textarea wire:model="defaultInvoiceTerms" rows="2" placeholder="All invoices are payable by due date." />
                            <flux:error name="defaultInvoiceTerms" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Default Invoice Footer</flux:label>
                            <flux:textarea wire:model="defaultInvoiceFooter" rows="2" placeholder="Thank you for your business." />
                            <flux:description>Extra text at the foot of the invoice. Banking details come from Settings → General.</flux:description>
                            <flux:error name="defaultInvoiceFooter" />
                        </flux:field>
                    </div>
                </div>

                {{-- Email --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 py-8">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">Email</h3>
                        <p class="mt-1 text-sm text-ink-muted">NettMail templates used for billing emails.</p>
                    </div>
                    <div class="col-span-2 divide-y divide-line">
                        <flux:field class="pb-5">
                            <flux:label>Invoice Email</flux:label>
                            <flux:select wire:model="invoiceEmailTemplateId" class="max-w-sm">
                                @foreach ($emailTemplates as $template)
                                    <flux:select.option value="{{ $template->id }}">{{ $template->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:description>The complete invoice email: subject and body, with merge tags such as <code class="text-xs bg-surface-alt px-1 rounded-sm">@{{first_name}}</code>, <code class="text-xs bg-surface-alt px-1 rounded-sm">@{{invoice_number}}</code> and <code class="text-xs bg-surface-alt px-1 rounded-sm">@{{amount_due}}</code>.</flux:description>
                            <flux:error name="invoiceEmailTemplateId" />
                        </flux:field>

                        <flux:field class="py-5">
                            <flux:label>Payment Email</flux:label>
                            <flux:select wire:model="paymentEmailTemplateId" class="max-w-sm">
                                @foreach ($emailTemplates as $template)
                                    <flux:select.option value="{{ $template->id }}">{{ $template->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:description>The complete payment receipt email: subject and body, with merge tags such as <code class="text-xs bg-surface-alt px-1 rounded-sm">@{{first_name}}</code>, <code class="text-xs bg-surface-alt px-1 rounded-sm">@{{amount}}</code> and <code class="text-xs bg-surface-alt px-1 rounded-sm">@{{invoices}}</code>.</flux:description>
                            <flux:error name="paymentEmailTemplateId" />
                        </flux:field>

                        @foreach ($reminders as $id => $reminder)
                            <div class="flex items-center gap-4 py-5 last:pb-0">
                                <flux:field class="w-64 shrink-0">
                                    <flux:label>{{ $reminder['name'] }}</flux:label>
                                    <flux:select wire:model="reminders.{{ $id }}.nettmail_template_id">
                                        @foreach ($emailTemplates as $template)
                                            <flux:select.option value="{{ $template->id }}">{{ $template->name }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:error name="reminders.{{ $id }}.nettmail_template_id" />
                                </flux:field>

                                <div class="flex items-center gap-2 shrink-0">
                                    <label class="inline-flex items-center gap-1 text-sm text-ink-muted">
                                        Offset
                                        <flux:tooltip content="Negative sends before the due date, positive sends after. For example -3 sends 3 days before due; 7 sends 7 days after due.">
                                            <flux:icon.information-circle class="size-3.5" />
                                        </flux:tooltip>
                                    </label>
                                    <flux:input wire:model="reminders.{{ $id }}.offset_days" type="number" class="w-20" />
                                    <flux:error name="reminders.{{ $id }}.offset_days" />
                                </div>

                                <flux:checkbox wire:model="reminders.{{ $id }}.enabled" label="Enabled" class="shrink-0" />
                            </div>
                        @endforeach
                    </div>
                </div>

            @endif
        </div>
    @endif
</div>
