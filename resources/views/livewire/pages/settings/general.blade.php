<?php

use App\Modules\Core\Settings\CompanySettings;
use App\Modules\Core\Settings\CurrencySettings;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layout.app')] class extends Component
{
    use WithFileUploads;

    // Company
    #[Validate('required|string|max:255')]
    public string $companyName = '';

    #[Validate('nullable|string|max:255')]
    public string $addressLine1 = '';

    #[Validate('nullable|string|max:255')]
    public string $addressLine2 = '';

    #[Validate('nullable|string|max:100')]
    public string $city = '';

    #[Validate('nullable|string|max:100')]
    public string $stateProvince = '';

    #[Validate('nullable|string|max:20')]
    public string $postalCode = '';

    #[Validate('nullable|string|max:100')]
    public string $country = '';

    #[Validate('nullable|string|max:50')]
    public string $phone = '';

    #[Validate('nullable|email|max:255')]
    public string $companyEmail = '';

    #[Validate('nullable|string|max:50')]
    public string $taxNumber = '';

    #[Validate('nullable|image|max:2048')]
    public $logo = null;

    public ?string $existingLogoUrl = null;

    // Currency
    #[Validate('required|string|min:3|max:3')]
    public string $baseCurrency = '';

    #[Validate('required|string|max:20')]
    public string $locale = '';

    public bool $saved = false;

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
    }

    public function save(): void
    {
        $this->validate();

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
            // Delete old logo if present
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
}; ?>

<div>
<div class="max-w-2xl mx-auto px-6 py-8">
    <div class="mb-6">
        <h1 class="text-[17px] font-semibold tracking-tight text-ink">General Settings</h1>
        <p class="mt-0.5 text-sm text-ink-muted">Company details, logo, and currency configuration</p>
    </div>

    <form wire:submit="save" class="space-y-8">

        {{-- ===== Company Details ===== --}}
        <div>
            <h2 class="text-sm font-semibold text-ink mb-4">Company Details</h2>
            <div class="space-y-5">

                <flux:field>
                    <flux:label>Company Name <span class="text-danger">*</span></flux:label>
                    <flux:input wire:model="companyName" placeholder="Acme (Pty) Ltd" />
                    <flux:error name="companyName" />
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
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

        {{-- ===== Address ===== --}}
        <div>
            <h2 class="text-sm font-semibold text-ink mb-4">Address</h2>
            <div class="space-y-4">
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

                <div class="grid grid-cols-2 gap-4">
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
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Postal Code</flux:label>
                        <flux:input wire:model="postalCode" placeholder="2000" class="max-w-xs" />
                        <flux:error name="postalCode" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Country</flux:label>
                        <flux:input wire:model="country" placeholder="South Africa" />
                        <flux:error name="country" />
                    </flux:field>
                </div>
            </div>
        </div>

        {{-- ===== Logo ===== --}}
        <div>
            <h2 class="text-sm font-semibold text-ink mb-4">Logo</h2>

            @if($existingLogoUrl)
                <div class="flex items-center gap-4 mb-4">
                    <img src="{{ $existingLogoUrl }}" alt="Company logo" class="h-16 max-w-[200px] object-contain border border-line rounded p-1 bg-white">
                    <flux:button type="button" wire:click="removeLogo" variant="ghost" size="sm" class="text-danger">Remove</flux:button>
                </div>
            @endif

            @if($logo)
                <div class="mb-3">
                    <img src="{{ $logo->temporaryUrl() }}" alt="Preview" class="h-16 max-w-[200px] object-contain border border-line rounded p-1 bg-white">
                </div>
            @endif

            <flux:field>
                <flux:label>{{ $existingLogoUrl ? 'Replace Logo' : 'Upload Logo' }}</flux:label>
                <input
                    type="file"
                    wire:model="logo"
                    accept="image/*"
                    class="block text-sm text-ink-muted file:mr-3 file:py-1.5 file:px-3 file:rounded file:border file:border-line file:text-sm file:bg-surface-alt file:text-ink hover:file:bg-surface cursor-pointer"
                />
                <flux:description>PNG, JPG or SVG. Max 2 MB.</flux:description>
                <flux:error name="logo" />
            </flux:field>
        </div>

        {{-- ===== Currency ===== --}}
        <div>
            <h2 class="text-sm font-semibold text-ink mb-4">Currency & Localisation</h2>
            <div class="space-y-5">
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
