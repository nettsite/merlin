<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Models\BillingEmailTemplate;
use App\Modules\Billing\Models\PaymentTerm;
use App\Modules\Billing\Services\InvoiceEmailTemplateService;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Settings\CompanySettings;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\Settings\PurchasingSettings;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
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

    public string $taxNumber = '';

    public $logo = null;

    public ?string $existingLogoUrl = null;

    // ── General / Currency ─────────────────────────────────────────────────
    public string $baseCurrency = '';

    public string $locale = '';

    // ── Purchasing ─────────────────────────────────────────────────────────
    public string $defaultPayableAccount = '';

    public float|string $taxDefaultRate = 15.00;

    public string $taxLabel = '';

    public float|string $autopostConfidence = 0.90;

    public float|string $fallbackConfidence = 0.80;

    public float|string $amountTolerance = 10.0;

    public float|string $descriptionSimilarity = 60.0;

    // ── Billing ────────────────────────────────────────────────────────────
    public ?string $defaultReceivableAccountId = null;

    public ?string $defaultBankAccountId = null;

    public ?string $defaultPaymentTermId = null;

    public ?string $taxLiabilityAccountId = null;

    public int $billingPeriodDay = 1;

    public ?string $baseEmailTemplateId = null;

    // ── Email Templates ────────────────────────────────────────────────────
    public ?string $editingTemplateId = null;

    public string $tplType = 'invoice';

    public string $tplName = '';

    public string $tplSubject = '';

    public string $tplBody = '';

    public ?int $tplOffsetDays = null;

    public bool $tplEnabled = true;

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
        $this->taxNumber = $company->tax_number;

        if ($company->logo_path && Storage::disk('public')->exists($company->logo_path)) {
            $this->existingLogoUrl = Storage::disk('public')->url($company->logo_path);
        }

        $currency = app(CurrencySettings::class);
        $this->baseCurrency = $currency->base_currency;
        $this->locale = $currency->locale;

        $purchasing = app(PurchasingSettings::class);
        $this->defaultPayableAccount = $purchasing->default_payable_account;
        $this->taxDefaultRate = $purchasing->tax_default_rate;
        $this->taxLabel = $purchasing->tax_label;
        $this->autopostConfidence = $purchasing->autopost_confidence;
        $this->fallbackConfidence = $purchasing->fallback_confidence;
        $this->amountTolerance = $purchasing->amount_tolerance;
        $this->descriptionSimilarity = $purchasing->description_similarity;

        $billing = app(BillingSettings::class);
        $this->defaultReceivableAccountId = $billing->default_receivable_account_id;
        $this->defaultBankAccountId = $billing->default_bank_account_id;
        $this->defaultPaymentTermId = $billing->default_payment_term_id;
        $this->taxLiabilityAccountId = $billing->tax_liability_account_id;
        $this->billingPeriodDay = $billing->billing_period_day;
        $this->baseEmailTemplateId = $billing->base_email_template_id;

        if ($this->tab === 'templates') {
            $this->autoSelectFirstTemplate();
        }
    }

    public function updatedTab(string $value): void
    {
        if ($value === 'templates' && $this->editingTemplateId === null) {
            $this->autoSelectFirstTemplate();
        }

        $this->saved = false;
    }

    private function autoSelectFirstTemplate(): void
    {
        $first = BillingEmailTemplate::orderByRaw("type = 'invoice' DESC")
            ->orderBy('offset_days')
            ->first();

        if ($first) {
            $this->loadTemplate($first->id);
        }
    }

    public function loadTemplate(string $id): void
    {
        $template = BillingEmailTemplate::findOrFail($id);
        $this->editingTemplateId = $template->id;
        $this->tplType = $template->type;
        $this->tplName = $template->name;
        $this->tplSubject = $template->subject;
        $this->tplBody = $template->body;
        $this->tplOffsetDays = $template->offset_days;
        $this->tplEnabled = $template->enabled;
        $this->saved = false;

        // Push the new body into the wire:ignore'd Quill editor, which Livewire
        // can no longer update via DOM morphing.
        $this->dispatch('template-loaded', body: $template->body);
    }

    public function save(): void
    {
        $this->saved = false;

        match ($this->tab) {
            'general' => $this->saveGeneral(),
            'purchasing' => $this->savePurchasing(),
            'billing' => $this->saveBilling(),
            'templates' => $this->saveTemplate(),
            default => null,
        };
    }

    private function saveGeneral(): void
    {
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
            'taxNumber' => 'nullable|string|max:50',
            'logo' => 'nullable|image|max:2048',
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
        $company->tax_number = $this->taxNumber;

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

    private function savePurchasing(): void
    {
        $this->validate([
            'defaultPayableAccount' => 'required|string|max:20',
            'taxDefaultRate' => 'required|numeric|min:0|max:100',
            'taxLabel' => 'required|string|max:20',
            'autopostConfidence' => 'required|numeric|min:0|max:1',
            'fallbackConfidence' => 'required|numeric|min:0|max:1',
            'amountTolerance' => 'required|numeric|min:0',
            'descriptionSimilarity' => 'required|numeric|min:0|max:100',
        ]);

        $settings = app(PurchasingSettings::class);
        $settings->default_payable_account = $this->defaultPayableAccount;
        $settings->tax_default_rate = (float) $this->taxDefaultRate;
        $settings->tax_label = $this->taxLabel;
        $settings->autopost_confidence = (float) $this->autopostConfidence;
        $settings->fallback_confidence = (float) $this->fallbackConfidence;
        $settings->amount_tolerance = (float) $this->amountTolerance;
        $settings->description_similarity = (float) $this->descriptionSimilarity;
        $settings->save();

        $this->saved = true;
    }

    private function saveBilling(): void
    {
        $this->validate([
            'defaultReceivableAccountId' => 'nullable|uuid|exists:accounts,id',
            'defaultBankAccountId' => 'nullable|uuid|exists:accounts,id',
            'defaultPaymentTermId' => 'nullable|uuid|exists:payment_terms,id',
            'taxLiabilityAccountId' => 'nullable|uuid|exists:accounts,id',
            'billingPeriodDay' => 'required|integer|min:1|max:28',
            'baseEmailTemplateId' => 'nullable|uuid|exists:nettmail_templates,id',
        ]);

        $settings = app(BillingSettings::class);
        $settings->default_receivable_account_id = $this->defaultReceivableAccountId ?: null;
        $settings->default_bank_account_id = $this->defaultBankAccountId ?: null;
        $settings->default_payment_term_id = $this->defaultPaymentTermId ?: null;
        $settings->tax_liability_account_id = $this->taxLiabilityAccountId ?: null;
        $settings->billing_period_day = $this->billingPeriodDay;
        $settings->base_email_template_id = $this->baseEmailTemplateId ?: null;
        $settings->save();

        $this->saved = true;
    }

    private function saveTemplate(): void
    {
        if ($this->editingTemplateId === null) {
            return;
        }

        $this->validate([
            'tplName' => 'required|string|max:255',
            'tplSubject' => 'required|string|max:500',
            'tplBody' => 'required|string',
            'tplOffsetDays' => 'nullable|integer',
        ]);

        $template = BillingEmailTemplate::findOrFail($this->editingTemplateId);
        $template->name = $this->tplName;
        $template->subject = $this->tplSubject;
        $template->body = $this->tplBody;
        $template->enabled = $this->tplEnabled;

        if ($template->type === 'reminder') {
            $template->offset_days = (int) $this->tplOffsetDays;
        }

        $template->save();

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

        if ($this->tab === 'templates') {
            $data['allTemplates'] = BillingEmailTemplate::orderByRaw("type = 'invoice' DESC")
                ->orderBy('offset_days')
                ->get();
        }

        return $data;
    }
}; ?>

@assets
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2/dist/quill.snow.css">
<script src="https://cdn.jsdelivr.net/npm/quill@2/dist/quill.js"></script>
@endassets

<div>
    {{-- Sticky header --}}
    <div class="sticky top-0 z-10 bg-white border-b border-line">
        <div class="flex items-center justify-between px-6 pt-5 pb-0">
            <h1 class="text-[17px] font-semibold tracking-tight text-ink">Settings</h1>

            @if($tab === 'templates' && $editingTemplateId)
                <div class="flex items-center gap-3 pb-4">
                    @if($saved)
                        <span class="text-sm text-success">Saved.</span>
                    @endif
                    {{-- Dispatch event so Alpine syncs Quill content before Livewire saves --}}
                    <flux:button x-on:click="$dispatch('sync-and-save-template')" variant="primary" size="sm">
                        Save Changes
                    </flux:button>
                </div>
            @elseif(!in_array($tab, ['roles', 'templates']))
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
            @foreach(['general' => 'General', 'purchasing' => 'Purchasing', 'billing' => 'Billing', 'roles' => 'Roles', 'templates' => 'Email Templates'] as $key => $label)
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

    @elseif($tab === 'templates')
        {{-- Template list sidebar + editor --}}
        <div class="flex min-h-[600px] divide-x divide-line">

            {{-- Sidebar: template list --}}
            <div class="w-52 shrink-0 py-3">
                @foreach ($allTemplates as $tmpl)
                    <button
                        type="button"
                        wire:click="loadTemplate('{{ $tmpl->id }}')"
                        class="w-full text-left px-4 py-3 border-l-2 transition-colors
                            {{ $editingTemplateId === $tmpl->id
                                ? 'border-accent bg-surface-alt text-ink'
                                : 'border-transparent hover:bg-surface-alt text-ink-muted hover:text-ink' }}"
                    >
                        <p class="text-sm font-medium leading-tight">{{ $tmpl->name }}</p>
                        <p class="text-xs text-ink-soft mt-0.5">
                            @if($tmpl->type === 'invoice')
                                New invoice
                            @elseif($tmpl->offset_days < 0)
                                {{ abs($tmpl->offset_days) }}d before due
                            @else
                                {{ $tmpl->offset_days }}d overdue
                            @endif
                            @if(!$tmpl->enabled)
                                · <span class="text-danger">off</span>
                            @endif
                        </p>
                    </button>
                @endforeach
            </div>

            {{-- Editor --}}
            @if($editingTemplateId)
                <div class="flex-1 px-8 py-6 max-w-4xl"
                    wire:key="template-editor"
                    x-data="{
                        cursorIndex: 0,
                        init() {
                            // Use style-based size so output has inline font-size (email-safe).
                            const SizeStyle = Quill.import('attributors/style/size');
                            SizeStyle.whitelist = ['12px', '13px', '14px', '15px', '16px', '18px', '20px'];
                            Quill.register(SizeStyle, true);

                            // Store raw Quill instance on the DOM element, not in Alpine's
                            // reactive data — wrapping a Quill instance in Alpine's Proxy
                            // corrupts Quill's internal this.selection / savedRange access.
                            const quill = new Quill(this.$refs.editorEl, {
                                theme: 'snow',
                                modules: {
                                    toolbar: [
                                        [{ size: ['12px', '13px', '14px', '15px', false, '16px', '18px', '20px'] }],
                                        ['bold', 'italic', 'underline'],
                                        ['link'],
                                        [{ list: 'bullet' }, { list: 'ordered' }],
                                        ['clean']
                                    ]
                                }
                            });
                            quill.root.style.fontFamily = 'Inter, ui-sans-serif, system-ui, sans-serif';
                            quill.root.style.fontSize = '16px';
                            quill.root.innerHTML = @js($tplBody);
                            quill.on('text-change', () => {
                                $wire.set('tplBody', quill.root.innerHTML, false);
                            });
                            quill.on('selection-change', (range) => {
                                if (range) this.cursorIndex = range.index;
                            });

                            // Stash on the editor element (stable via $refs) — not in
                            // Alpine reactive data (Proxy corrupts Quill) and not on
                            // $el (contextual: resolves to the clicked button in handlers).
                            this.$refs.editorEl.__quill = quill;
                        },
                        syncContent() {
                            const quill = this.$refs.editorEl.__quill;
                            if (quill) {
                                $wire.set('tplBody', quill.root.innerHTML, false);
                            }
                        },
                        insertTag(tag) {
                            const quill = this.$refs.editorEl.__quill;
                            if (!quill) return;
                            const idx = this.cursorIndex;
                            quill.focus();
                            quill.setSelection(idx, 0);
                            quill.insertText(idx, tag);
                            this.cursorIndex = idx + tag.length;
                        },
                        loadBody(body) {
                            // May fire before init() (server dispatches template-loaded
                            // while the panel is morphed in); init seeds the first body,
                            // so a no-op here is safe.
                            const quill = this.$refs.editorEl?.__quill;
                            if (!quill) return;
                            quill.root.innerHTML = body;
                            this.cursorIndex = 0;
                        }
                    }"
                    x-on:sync-and-save-template.window="syncContent(); $nextTick(() => $wire.save())"
                    x-on:template-loaded.window="loadBody($event.detail.body)"
                >
                    <div class="space-y-5">
                        {{-- Name / offset / enabled --}}
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
                            <flux:field>
                                <flux:label>Name</flux:label>
                                <flux:input wire:model="tplName" />
                                <flux:error name="tplName" />
                            </flux:field>

                            @if($tplType === 'reminder')
                                <flux:field>
                                    <flux:label>Offset Days</flux:label>
                                    <flux:input wire:model="tplOffsetDays" type="number" />
                                    <flux:description>Negative = before due, positive = after</flux:description>
                                    <flux:error name="tplOffsetDays" />
                                </flux:field>
                            @endif

                            <div class="pb-1">
                                <flux:field>
                                    <flux:checkbox wire:model="tplEnabled" label="Enabled" />
                                </flux:field>
                            </div>
                        </div>

                        {{-- Subject --}}
                        <flux:field>
                            <flux:label>Subject</flux:label>
                            <flux:input wire:model="tplSubject" />
                            <flux:error name="tplSubject" />
                        </flux:field>

                        {{-- Body (Quill) --}}
                        <flux:field>
                            <flux:label>Body</flux:label>
                            <div wire:ignore class="border border-line rounded-md overflow-hidden [&_.ql-toolbar]:border-0 [&_.ql-toolbar]:border-b [&_.ql-toolbar]:border-line [&_.ql-container]:border-0">
                                <div x-ref="editorEl" class="min-h-64 text-sm"></div>
                            </div>
                            <flux:error name="tplBody" />
                        </flux:field>

                        {{-- Variables reference --}}
                        <div class="rounded-md border border-line bg-surface-alt p-4 text-sm">
                            <p class="text-xs font-semibold text-ink-muted uppercase tracking-wide mb-2">Available variables</p>
                            <div class="flex flex-wrap gap-2">
                                @foreach (InvoiceEmailTemplateService::availableShortcodes() as $tag => $desc)
                                    <button
                                        type="button"
                                        title="{{ $desc }}"
                                        x-on:click="insertTag('{{ $tag }}')"
                                        class="font-mono text-xs bg-white border border-line rounded px-1.5 py-0.5 text-ink-soft hover:text-ink hover:border-ink-muted transition-colors cursor-pointer"
                                    >{{ $tag }}</button>
                                @endforeach
                            </div>
                            <p class="text-xs text-ink-muted mt-2">Click a variable to insert it at the cursor.</p>
                        </div>
                    </div>
                </div>
            @endif
        </div>

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

                {{-- Logo --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 py-8">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">Logo</h3>
                        <p class="mt-1 text-sm text-ink-muted">Shown on PDFs and the portal. PNG, JPG or SVG, max 2 MB.</p>
                    </div>
                    <div class="col-span-2 space-y-4">
                        @if($existingLogoUrl)
                            <div class="flex items-center gap-4">
                                <img src="{{ $existingLogoUrl }}" alt="Company logo" class="h-16 max-w-[200px] object-contain border border-line rounded p-1 bg-white">
                                <flux:button type="button" wire:click="removeLogo" variant="ghost" size="sm" class="text-danger">Remove</flux:button>
                            </div>
                        @endif

                        @if($logo)
                            <img src="{{ $logo->temporaryUrl() }}" alt="Preview" class="h-16 max-w-[200px] object-contain border border-line rounded p-1 bg-white">
                        @endif

                        <flux:field>
                            <flux:label>{{ $existingLogoUrl ? 'Replace Logo' : 'Upload Logo' }}</flux:label>
                            <input
                                type="file"
                                wire:model="logo"
                                accept="image/*"
                                class="block text-sm text-ink-muted file:mr-3 file:py-1.5 file:px-3 file:rounded file:border file:border-line file:text-sm file:bg-surface-alt file:text-ink hover:file:bg-surface cursor-pointer"
                            />
                            <flux:error name="logo" />
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

            {{-- ── PURCHASING TAB ──────────────────────────────── --}}
            @elseif($tab === 'purchasing')

                {{-- Defaults --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 py-8">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">Defaults</h3>
                        <p class="mt-1 text-sm text-ink-muted">Default values applied to new purchase invoices.</p>
                    </div>
                    <div class="col-span-2 space-y-5">
                        <flux:field>
                            <flux:label>Default Payable Account <span class="text-danger">*</span></flux:label>
                            <flux:input wire:model="defaultPayableAccount" placeholder="2000" class="max-w-xs" />
                            <flux:description>Account code used as the default AP account on new invoices</flux:description>
                            <flux:error name="defaultPayableAccount" />
                        </flux:field>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
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
                </div>

                {{-- Auto-Posting Thresholds --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 py-8">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">Auto-Posting Thresholds</h3>
                        <p class="mt-1 text-sm text-ink-muted">Controls when LLM extractions are auto-posted and when a stronger model is used as fallback.</p>
                    </div>
                    <div class="col-span-2 space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>Min. Confidence for Auto-Post <span class="text-danger">*</span></flux:label>
                                <flux:input wire:model="autopostConfidence" type="number" step="0.01" min="0" max="1" />
                                <flux:description>0–1 scale</flux:description>
                                <flux:error name="autopostConfidence" />
                            </flux:field>
                            <flux:field>
                                <flux:label>Min. Fast-Model Confidence (fallback) <span class="text-danger">*</span></flux:label>
                                <flux:input wire:model="fallbackConfidence" type="number" step="0.01" min="0" max="1" />
                                <flux:description>Below this, re-run on stronger model</flux:description>
                                <flux:error name="fallbackConfidence" />
                            </flux:field>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <flux:field>
                                <flux:label>Amount Tolerance (%) <span class="text-danger">*</span></flux:label>
                                <flux:input wire:model="amountTolerance" type="number" step="0.1" min="0" />
                                <flux:description>Max % diff for pattern-based auto-posting</flux:description>
                                <flux:error name="amountTolerance" />
                            </flux:field>
                            <flux:field>
                                <flux:label>Min. Description Similarity (0–100) <span class="text-danger">*</span></flux:label>
                                <flux:input wire:model="descriptionSimilarity" type="number" step="1" min="0" max="100" />
                                <flux:description>Min score for line description matching</flux:description>
                                <flux:error name="descriptionSimilarity" />
                            </flux:field>
                        </div>
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
                                <flux:select wire:model="defaultBankAccountId">
                                    <flux:select.option value="">— None —</flux:select.option>
                                    @foreach ($assetAccounts as $account)
                                        <flux:select.option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:description>Pre-selected when recording payments</flux:description>
                                <flux:error name="defaultBankAccountId" />
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

                {{-- Email --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 py-8">
                    <div>
                        <h3 class="text-sm font-semibold text-ink">Email</h3>
                        <p class="mt-1 text-sm text-ink-muted">NettMail/Unlayer template used as the branded wrapper for all billing emails. Must contain <code class="text-xs bg-surface-alt px-1 rounded">[email_body]</code> where the message body should appear.</p>
                    </div>
                    <div class="col-span-2">
                        <flux:field>
                            <flux:label>Base Email Template</flux:label>
                            <flux:select wire:model="baseEmailTemplateId" class="max-w-sm">
                                <flux:select.option value="">— None —</flux:select.option>
                                @foreach ($emailTemplates as $template)
                                    <flux:select.option value="{{ $template->id }}">{{ $template->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:description>Edit individual email content under the Email Templates tab.</flux:description>
                            <flux:error name="baseEmailTemplateId" />
                        </flux:field>
                    </div>
                </div>

            @endif
        </div>
    @endif
</div>
