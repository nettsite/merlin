<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\PaymentTerm;
use App\Modules\Core\Services\PartyService;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public bool $showForm = false;

    public ?string $editingId = null;

    #[Validate('required|string|max:255')]
    public string $legalName = '';

    #[Validate('nullable|string|max:255')]
    public string $tradingName = '';

    #[Validate('nullable|email|max:255')]
    public string $email = '';

    #[Validate('nullable|string|max:50')]
    public string $phone = '';

    #[Validate('nullable|string|max:1000')]
    public string $notes = '';

    #[Validate('required|in:active,pending,inactive')]
    public string $status = 'active';

    #[Validate('nullable|uuid|exists:accounts,id')]
    public string $defaultPayableAccountId = '';

    #[Validate('nullable|uuid|exists:payment_terms,id')]
    public string $paymentTermId = '';

    #[Validate('nullable|string|max:1000')]
    public string $paymentBehaviorNotes = '';

    /**
     * Shared "Add/Edit Supplier" modal, embedded on both the Suppliers list
     * page and a Supplier's own detail page — opened by dispatching
     * 'open-supplier-form' (optionally with a partyId to edit).
     */
    #[On('open-supplier-form')]
    public function open(?string $partyId = null): void
    {
        $this->resetValidation();
        $this->reset(['legalName', 'tradingName', 'email', 'phone', 'notes', 'defaultPayableAccountId', 'paymentTermId', 'paymentBehaviorNotes']);
        $this->status = 'active';
        $this->editingId = $partyId;

        if ($partyId === null) {
            $this->authorize('create', Party::class);
        } else {
            $party = Party::with(['business', 'relationships'])->findOrFail($partyId);
            $this->authorize('update', $party);

            $this->legalName = $party->business?->legal_name ?? '';
            $this->tradingName = $party->business?->trading_name ?? '';
            $this->email = $party->primary_email ?? '';
            $this->phone = $party->primary_phone ?? '';
            $this->notes = $party->notes ?? '';
            $this->status = $party->status;

            $supplierRel = $party->relationships->firstWhere('relationship_type', 'supplier');
            $this->defaultPayableAccountId = $supplierRel?->default_payable_account_id ?? '';
            $this->paymentTermId = $supplierRel?->payment_term_id ?? '';
            $this->paymentBehaviorNotes = $supplierRel?->payment_behavior_notes ?? '';
        }

        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate();

        if ($this->editingId === null) {
            $this->authorize('create', Party::class);

            $party = app(PartyService::class)->createBusiness([
                'business_type' => 'company',
                'legal_name' => $this->legalName,
                'trading_name' => $this->tradingName ?: $this->legalName,
                'primary_email' => $this->email ?: null,
                'primary_phone' => $this->phone ?: null,
                'notes' => $this->notes ?: null,
                'status' => $this->status,
            ], ['supplier']);
        } else {
            $party = Party::with(['business', 'relationships'])->findOrFail($this->editingId);
            $this->authorize('update', $party);

            $party->business?->update([
                'legal_name' => $this->legalName,
                'trading_name' => $this->tradingName ?: $this->legalName,
            ]);
            $party->update([
                'primary_email' => $this->email ?: null,
                'primary_phone' => $this->phone ?: null,
                'notes' => $this->notes ?: null,
                'status' => $this->status,
            ]);
        }

        $rel = $party->relationships()->where('relationship_type', 'supplier')->first();

        if ($rel !== null) {
            $rel->mergeMetadata([
                'default_payable_account_id' => $this->defaultPayableAccountId ?: null,
                'payment_term_id' => $this->paymentTermId ?: null,
                'payment_behavior_notes' => $this->paymentBehaviorNotes ?: null,
            ]);
        }

        $this->showForm = false;
        $this->dispatch('supplier-saved', partyId: $party->id);
    }

    public function cancel(): void
    {
        $this->showForm = false;
    }

    public function getLiabilityAccountsProperty()
    {
        return Account::postable()->active()
            ->whereHas('group.type', fn ($q) => $q->where('code', '2'))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);
    }

    public function getPaymentTermsProperty()
    {
        return PaymentTerm::orderBy('name')->get(['id', 'name']);
    }
}; ?>

<flux:modal wire:model="showForm" name="supplier-form" class="w-full max-w-2xl">
    <div class="mb-4">
        <flux:heading size="lg">{{ $editingId ? 'Edit Supplier' : 'Add Supplier' }}</flux:heading>
    </div>

    <form wire:submit="save" class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <flux:field>
                <flux:label>Legal Name <span class="text-danger">*</span></flux:label>
                <flux:input wire:model="legalName" placeholder="Acme Pty Ltd" />
                <flux:error name="legalName" />
            </flux:field>

            <flux:field>
                <flux:label>Trading Name</flux:label>
                <flux:input wire:model="tradingName" placeholder="Leave blank to use legal name" />
                <flux:error name="tradingName" />
            </flux:field>

            <flux:field>
                <flux:label>Email</flux:label>
                <flux:input wire:model="email" type="email" placeholder="accounts@supplier.com" />
                <flux:error name="email" />
            </flux:field>

            <flux:field>
                <flux:label>Phone</flux:label>
                <flux:input wire:model="phone" placeholder="+27 11 000 0000" />
                <flux:error name="phone" />
            </flux:field>

            <flux:field>
                <flux:label>Status</flux:label>
                <flux:select wire:model="status">
                    <option value="active">Active</option>
                    <option value="pending">Pending</option>
                    <option value="inactive">Inactive</option>
                </flux:select>
                <flux:error name="status" />
            </flux:field>

            <flux:field>
                <flux:label>Default Payable Account</flux:label>
                <flux:select wire:model="defaultPayableAccountId">
                    <option value="">— Use system default —</option>
                    @foreach($this->liabilityAccounts as $account)
                        <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                    @endforeach
                </flux:select>
                <flux:error name="defaultPayableAccountId" />
            </flux:field>

            <flux:field>
                <flux:label>Payment Terms</flux:label>
                <flux:select wire:model="paymentTermId">
                    <option value="">— Use system default —</option>
                    @foreach($this->paymentTerms as $term)
                        <option value="{{ $term->id }}">{{ $term->name }}</option>
                    @endforeach
                </flux:select>
                <flux:error name="paymentTermId" />
            </flux:field>

            <flux:field class="sm:col-span-2">
                <flux:label>Payment Behaviour</flux:label>
                <flux:textarea wire:model="paymentBehaviorNotes" rows="3" placeholder="e.g. &quot;This supplier always sends the invoice already paid — a zero balance means record a payment too&quot;, or &quot;Sends an unpaid invoice, then re-sends a paid copy under a different invoice number.&quot; Leave blank to use automatic detection." />
                <flux:description>Plain English — read by the AI extraction step to decide whether a new invoice from this supplier should be recorded as already paid. Leave blank to keep the default automatic detection.</flux:description>
                <flux:error name="paymentBehaviorNotes" />
            </flux:field>

            <flux:field class="sm:col-span-2">
                <flux:label>Notes</flux:label>
                <flux:textarea wire:model="notes" rows="3" placeholder="Internal notes..." />
                <flux:error name="notes" />
            </flux:field>
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <flux:button type="button" wire:click="cancel" variant="ghost">Cancel</flux:button>
            <flux:button type="submit" variant="primary">Save</flux:button>
        </div>
    </form>
</flux:modal>
