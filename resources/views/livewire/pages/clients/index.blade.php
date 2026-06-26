<?php

use App\Livewire\Concerns\HasCrudForm;
use App\Livewire\Concerns\HasCrudTable;
use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\PaymentTerm;
use App\Modules\Core\Models\ContactAssignment;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\PartyRelationship;
use App\Modules\Core\Services\PartyService;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layout.app')] class extends Component
{
    use HasCrudTable, HasCrudForm;

    // ── Client fields ────────────────────────────────────────

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

    #[Validate('nullable|uuid|exists:payment_terms,id')]
    public string $paymentTermId = '';

    // ── First contact (create form only — optional) ──────────

    #[Validate('nullable|string|max:100')]
    public string $contactFirstName = '';

    #[Validate('nullable|string|max:100')]
    public string $contactLastName = '';

    #[Validate('nullable|email|max:255')]
    public string $contactEmail = '';

    #[Validate('nullable|string|max:50')]
    public string $contactPhone = '';

    public bool $contactReceivesInvoices = true;

    // ── Contacts list (edit form only) ───────────────────────

    /** @var array<int, array{id: string, full_name: string, email: string, phone: string, receives_invoices: bool, is_primary: bool}> */
    public array $contacts = [];

    public bool $showAddContact = false;

    public string $newContactFirstName = '';
    public string $newContactLastName = '';
    public string $newContactEmail = '';
    public string $newContactPhone = '';
    public bool $newContactReceivesInvoices = true;

    // ── Period filter ────────────────────────────────────────

    public string $dateRange = 'this_year';
    public string $dateFrom = '';
    public string $dateTo = '';

    public function updatedDateRange(): void
    {
        $this->resetPage();
    }

    public function updatedDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatedDateTo(): void
    {
        $this->resetPage();
    }

    // ── Supplier cross-link ──────────────────────────────────

    public ?string $addingSupplierForId = null;

    public bool $showSupplierRelationshipModal = false;

    public bool $supplierRelExists = false;

    #[Validate('nullable|uuid|exists:accounts,id')]
    public string $supplierPayableAccountId = '';

    #[Validate('nullable|uuid|exists:payment_terms,id')]
    public string $supplierPaymentTermId = '';

    // ── Lifecycle ────────────────────────────────────────────

    public function mount(): void
    {
        $this->authorize('viewAny', Party::class);
    }

    public function create(): void
    {
        $this->authorize('create', Party::class);
        $this->reset([
            'legalName', 'tradingName', 'email', 'phone', 'notes', 'paymentTermId',
            'contactFirstName', 'contactLastName', 'contactEmail', 'contactPhone',
            'contacts', 'showAddContact',
            'newContactFirstName', 'newContactLastName', 'newContactEmail', 'newContactPhone',
        ]);
        $this->status = 'active';
        $this->contactReceivesInvoices = true;
        $this->newContactReceivesInvoices = true;
        $this->editingId = null;
        $this->showForm = true;
    }

    protected function fillForm(string $id): void
    {
        $party = Party::with(['business', 'relationships'])->findOrFail($id);
        $this->authorize('update', $party);
        $this->legalName = $party->business?->legal_name ?? '';
        $this->tradingName = $party->business?->trading_name ?? '';
        $this->email = $party->primary_email ?? '';
        $this->phone = $party->primary_phone ?? '';
        $this->notes = $party->notes ?? '';
        $this->status = $party->status;

        $clientRel = $party->relationships->firstWhere('relationship_type', 'client');
        $this->paymentTermId = $clientRel?->payment_term_id ?? '';

        // Load contacts
        $this->showAddContact = false;
        $this->reset(['newContactFirstName', 'newContactLastName', 'newContactEmail', 'newContactPhone']);
        $this->newContactReceivesInvoices = true;

        $this->contacts = $party->contactAssignments()
            ->with('person')
            ->where('is_active', true)
            ->get()
            ->map(fn (ContactAssignment $a) => [
                'id'                => $a->id,
                'full_name'         => $a->person->full_name,
                'email'             => $a->person->email ?? '',
                'phone'             => $a->person->mobile ?? '',
                'receives_invoices' => $a->receives_invoices,
                'is_primary'        => $a->is_primary,
            ])
            ->toArray();
    }

    protected function store(): void
    {
        $this->validate();

        $party = app(PartyService::class)->createBusiness([
            'business_type' => 'company',
            'legal_name'    => $this->legalName,
            'trading_name'  => $this->tradingName ?: $this->legalName,
            'primary_email' => $this->email ?: null,
            'primary_phone' => $this->phone ?: null,
            'notes'         => $this->notes ?: null,
            'status'        => $this->status,
        ], ['client']);

        $this->saveClientRelationshipMetadata($party);

        if ($this->contactFirstName !== '') {
            $personParty = app(PartyService::class)->createPerson([
                'first_name' => $this->contactFirstName,
                'last_name'  => $this->contactLastName ?: '',
                'email'      => $this->contactEmail ?: null,
                'mobile'     => $this->contactPhone ?: null,
            ]);

            $party->assignContact($personParty->person, [
                'receives_invoices' => $this->contactReceivesInvoices,
                'is_primary'        => true,
                'is_active'         => true,
            ]);
        }
    }

    protected function update(): void
    {
        $this->validate();
        $party = Party::with(['business', 'relationships'])->findOrFail($this->editingId);
        $party->business?->update([
            'legal_name'   => $this->legalName,
            'trading_name' => $this->tradingName ?: $this->legalName,
        ]);
        $party->update([
            'primary_email' => $this->email ?: null,
            'primary_phone' => $this->phone ?: null,
            'notes'         => $this->notes ?: null,
            'status'        => $this->status,
        ]);

        $this->saveClientRelationshipMetadata($party);
    }

    public function addContact(): void
    {
        $this->validate([
            'newContactFirstName' => 'required|string|max:100',
            'newContactLastName'  => 'nullable|string|max:100',
            'newContactEmail'     => 'nullable|email|max:255',
            'newContactPhone'     => 'nullable|string|max:50',
        ]);

        $party = Party::findOrFail($this->editingId);
        $this->authorize('update', $party);

        $personParty = app(PartyService::class)->createPerson([
            'first_name' => $this->newContactFirstName,
            'last_name'  => $this->newContactLastName ?: '',
            'email'      => $this->newContactEmail ?: null,
            'mobile'     => $this->newContactPhone ?: null,
        ]);

        $party->assignContact($personParty->person, [
            'receives_invoices' => $this->newContactReceivesInvoices,
            'is_primary'        => count($this->contacts) === 0,
            'is_active'         => true,
        ]);

        // Refresh
        $this->contacts = $party->contactAssignments()
            ->with('person')
            ->where('is_active', true)
            ->get()
            ->map(fn (ContactAssignment $a) => [
                'id'                => $a->id,
                'full_name'         => $a->person->full_name,
                'email'             => $a->person->email ?? '',
                'phone'             => $a->person->mobile ?? '',
                'receives_invoices' => $a->receives_invoices,
                'is_primary'        => $a->is_primary,
            ])
            ->toArray();

        $this->reset(['newContactFirstName', 'newContactLastName', 'newContactEmail', 'newContactPhone']);
        $this->newContactReceivesInvoices = true;
        $this->showAddContact = false;
    }

    public function removeContact(string $assignmentId): void
    {
        $party = Party::findOrFail($this->editingId);
        $this->authorize('update', $party);

        ContactAssignment::findOrFail($assignmentId)->delete();

        $this->contacts = array_values(
            array_filter($this->contacts, fn ($c) => $c['id'] !== $assignmentId)
        );
    }

    private function saveClientRelationshipMetadata(Party $party): void
    {
        $rel = $party->relationships()->where('relationship_type', 'client')->first();

        if ($rel === null) {
            return;
        }

        $rel->mergeMetadata([
            'payment_term_id' => $this->paymentTermId ?: null,
        ]);
    }

    public function openSupplierRelationshipForm(string $id): void
    {
        $party = Party::with('relationships')->findOrFail($id);
        $this->authorize('update', $party);

        $rel = $party->relationships->firstWhere('relationship_type', 'supplier');
        $this->supplierPayableAccountId = $rel?->default_payable_account_id ?? '';
        $this->supplierPaymentTermId = $rel?->payment_term_id ?? '';
        $this->supplierRelExists = $rel !== null;
        $this->addingSupplierForId = $id;
        $this->showSupplierRelationshipModal = true;
    }

    public function saveSupplierRelationship(): void
    {
        $this->validate([
            'supplierPayableAccountId' => 'nullable|uuid|exists:accounts,id',
            'supplierPaymentTermId'    => 'nullable|uuid|exists:payment_terms,id',
        ]);

        $party = Party::findOrFail($this->addingSupplierForId);
        $this->authorize('update', $party);

        $rel = $party->relationships()->firstOrCreate(
            ['relationship_type' => 'supplier'],
            ['is_active' => true],
        );
        $rel->mergeMetadata([
            'default_payable_account_id' => $this->supplierPayableAccountId ?: null,
            'payment_term_id'            => $this->supplierPaymentTermId ?: null,
        ]);

        $this->supplierRelExists = true;
        $this->showSupplierRelationshipModal = false;
        $this->addingSupplierForId = null;
    }

    public function removeSupplierRelationship(string $id): void
    {
        $party = Party::findOrFail($id);
        $this->authorize('update', $party);
        $party->relationships()->where('relationship_type', 'supplier')->first()?->delete();
        $this->supplierRelExists = false;
        $this->showSupplierRelationshipModal = false;
        $this->addingSupplierForId = null;
    }

    protected function performDelete(string $id): void
    {
        $party = Party::findOrFail($id);
        $this->authorize('delete', $party);
        $party->delete();
    }

    private function dateRangeBounds(): array
    {
        return match ($this->dateRange) {
            'this_month' => [now()->startOfMonth(), now()],
            'custom' => [
                $this->dateFrom ? Carbon::parse($this->dateFrom) : now()->startOfYear(),
                $this->dateTo ? Carbon::parse($this->dateTo) : now(),
            ],
            default => [now()->startOfYear(), now()],
        };
    }

    public function with(): array
    {
        $sortColumn = match ($this->sortBy) {
            'status'          => 'parties.status',
            'primary_email'   => 'parties.primary_email',
            'total_owing'     => 'total_owing',
            'total_invoiced'  => 'total_invoiced',
            default           => 'businesses.legal_name',
        };

        [$dateStart, $dateEnd] = $this->dateRangeBounds();

        $totalInvoicedSub = Document::salesInvoices()
            ->selectRaw('COALESCE(SUM(total), 0)')
            ->whereColumn('party_id', 'parties.id')
            ->whereBetween('issue_date', [$dateStart->toDateString(), $dateEnd->toDateString()]);

        $totalOwingSub = Document::salesInvoices()
            ->selectRaw('COALESCE(SUM(balance_due), 0)')
            ->whereColumn('party_id', 'parties.id')
            ->unpaid();

        $baseQuery = Party::clients()
            ->join('businesses', 'businesses.id', '=', 'parties.id')
            ->select('parties.*')
            ->when(
                $this->search,
                fn ($q) => $q->where(function ($q): void {
                    $q->where('businesses.legal_name', 'like', "%{$this->search}%")
                        ->orWhere('businesses.trading_name', 'like', "%{$this->search}%")
                        ->orWhere('parties.primary_email', 'like', "%{$this->search}%");
                })
            );

        return [
            'paymentTerms' => PaymentTerm::orderBy('name')->get(['id', 'name']),
            'liabilityAccounts' => Account::postable()->active()
                ->whereHas('group.type', fn ($q) => $q->where('code', '2'))
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
            'rows' => $baseQuery
                ->selectSub($totalOwingSub, 'total_owing')
                ->selectSub($totalInvoicedSub, 'total_invoiced')
                ->with(['business', 'relationships'])
                ->orderBy($sortColumn, $this->sortDir)
                ->paginate($this->perPage),
        ];
    }
}; ?>

<div>
<x-crud.table title="Clients" description="Your client businesses">
    <x-slot name="actions">
        @can('create', \App\Modules\Core\Models\Party::class)
            <flux:button wire:click="create" icon="plus" size="sm" variant="primary">
                New Client
            </flux:button>
        @endcan
    </x-slot>

    <x-slot name="filters">
        <div class="flex flex-wrap items-center justify-end gap-3 border-b border-line px-6 py-3">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="text-ink-muted">Total invoiced:</span>
                <div class="flex gap-1">
                    @foreach(['this_year' => 'This year', 'this_month' => 'This month', 'custom' => 'Custom'] as $value => $label)
                        <button
                            wire:click="$set('dateRange', '{{ $value }}')"
                            @class([
                                'px-3 py-1.5 text-sm rounded-md font-medium transition-colors',
                                'bg-white text-ink shadow-sm ring-1 ring-inset ring-line' => $dateRange === $value,
                                'text-ink-soft hover:text-ink' => $dateRange !== $value,
                            ])
                        >{{ $label }}</button>
                    @endforeach
                </div>
                @if($dateRange === 'custom')
                    <flux:input type="date" wire:model.live="dateFrom" size="sm" class="w-36" />
                    <span class="text-ink-muted">to</span>
                    <flux:input type="date" wire:model.live="dateTo" size="sm" class="w-36" />
                @endif
            </div>
        </div>
    </x-slot>

    <table class="w-full text-sm">
        <thead>
            <tr>
                <x-crud.th column="name" :sort-by="$sortBy" :sort-dir="$sortDir">Name</x-crud.th>
                <x-crud.th column="primary_email" :sort-by="$sortBy" :sort-dir="$sortDir">Email</x-crud.th>
                <x-crud.th>Phone</x-crud.th>
                <x-crud.th column="status" :sort-by="$sortBy" :sort-dir="$sortDir">Status</x-crud.th>
                <x-crud.th column="total_owing" :sort-by="$sortBy" :sort-dir="$sortDir" :right="true">Owing</x-crud.th>
                <x-crud.th column="total_invoiced" :sort-by="$sortBy" :sort-dir="$sortDir" :right="true">Invoiced</x-crud.th>
                <x-crud.th></x-crud.th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $party)
                <tr class="border-t border-line hover:bg-surface-alt group">
                    <td class="px-4 py-3 font-medium text-ink">
                        <a href="{{ route('clients.show', $party->id) }}" wire:navigate class="hover:text-accent hover:underline">
                            {{ $party->business?->display_name ?? '—' }}
                        </a>
                    </td>
                    <td class="px-4 py-3 text-ink-soft tabular-nums">
                        {{ $party->primary_email ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-ink-soft tabular-nums">
                        {{ $party->primary_phone ?? '—' }}
                    </td>
                    <td class="px-4 py-3">
                        <span @class([
                            'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
                            'bg-green-50 text-success' => $party->status === 'active',
                            'bg-yellow-50 text-warning' => $party->status === 'pending',
                            'bg-surface-alt text-ink-muted' => $party->status === 'inactive',
                        ])>
                            {{ ucfirst($party->status) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums">
                        @if((float) $party->total_owing > 0)
                            <span class="font-medium text-ink">{{ number_format((float) $party->total_owing, 2) }}</span>
                        @else
                            <span class="text-ink-muted">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-ink-soft">
                        @if((float) $party->total_invoiced > 0)
                            {{ number_format((float) $party->total_invoiced, 2) }}
                        @else
                            <span class="text-ink-muted">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            @can('view', $party)
                                <flux:button href="{{ route('clients.show', $party->id) }}" wire:navigate size="sm" variant="ghost" icon="eye" />
                            @endcan
                            @can('update', $party)
                                @php $isSupplier = $party->relationships->firstWhere('relationship_type', 'supplier') !== null @endphp
                                <flux:button
                                    wire:click="openSupplierRelationshipForm('{{ $party->id }}')"
                                    size="sm" variant="ghost"
                                    :class="$isSupplier ? 'text-blue-600' : ''"
                                    title="{{ $isSupplier ? 'Manage supplier relationship' : 'Add as supplier' }}"
                                >
                                    {{ $isSupplier ? 'Supplier ✓' : 'Add as Supplier' }}
                                </flux:button>
                                <flux:button wire:click="edit('{{ $party->id }}')" size="sm" variant="ghost" icon="pencil" />
                            @endcan
                            @can('delete', $party)
                                <flux:button
                                    wire:click="delete('{{ $party->id }}')"
                                    wire:confirm="Delete this client? This cannot be undone."
                                    size="sm" variant="ghost" icon="trash"
                                    class="text-danger hover:text-danger"
                                />
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-12 text-center">
                        <p class="font-medium text-ink">No clients yet.</p>
                        <p class="mt-1 text-sm text-ink-muted">Add your first client to get started.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <x-slot name="pagination">
        {{ $rows->links() }}
    </x-slot>
</x-crud.table>

{{-- Supplier relationship modal --}}
<flux:modal wire:model="showSupplierRelationshipModal" name="supplier-relationship" class="w-full max-w-lg">
    <div class="flex flex-col gap-4">
        <flux:heading>{{ $supplierRelExists ? 'Supplier Relationship' : 'Add as Supplier' }}</flux:heading>

        <flux:field>
            <flux:label>Default Payable Account</flux:label>
            <flux:select wire:model="supplierPayableAccountId">
                <option value="">— Use system default —</option>
                @foreach($liabilityAccounts as $account)
                    <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                @endforeach
            </flux:select>
            <flux:error name="supplierPayableAccountId" />
        </flux:field>

        <flux:field>
            <flux:label>Payment Terms</flux:label>
            <flux:select wire:model="supplierPaymentTermId">
                <option value="">— Use system default —</option>
                @foreach($paymentTerms as $term)
                    <option value="{{ $term->id }}">{{ $term->name }}</option>
                @endforeach
            </flux:select>
            <flux:error name="supplierPaymentTermId" />
        </flux:field>

        <div class="flex items-center justify-between pt-2">
            @if($supplierRelExists)
                <flux:button
                    wire:click="removeSupplierRelationship('{{ $addingSupplierForId }}')"
                    wire:confirm="Remove supplier relationship for this party?"
                    size="sm" variant="ghost"
                    class="text-danger hover:text-danger"
                >Remove Supplier</flux:button>
            @else
                <div></div>
            @endif
            <div class="flex gap-2">
                <flux:button wire:click="$set('showSupplierRelationshipModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="saveSupplierRelationship" variant="primary">Save</flux:button>
            </div>
        </div>
    </div>
</flux:modal>

<x-crud.form title="Client" :editing="$editingId !== null">
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
        <flux:input wire:model="email" type="email" placeholder="accounts@client.com" />
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
        <flux:label>Payment Terms</flux:label>
        <flux:select wire:model="paymentTermId">
            <option value="">— Use system default —</option>
            @foreach($paymentTerms as $term)
                <option value="{{ $term->id }}">{{ $term->name }}</option>
            @endforeach
        </flux:select>
        <flux:error name="paymentTermId" />
    </flux:field>

    <flux:field>
        <flux:label>Notes</flux:label>
        <flux:textarea wire:model="notes" rows="3" placeholder="Internal notes..." />
        <flux:error name="notes" />
    </flux:field>

    {{-- ===== First Contact (create only) ===== --}}
    @if($editingId === null)
    <div class="border-t border-line pt-4">
        <p class="text-sm font-semibold text-ink mb-3">First Contact <span class="font-normal text-ink-muted">(optional)</span></p>
        <div class="space-y-3">
            <div class="grid grid-cols-2 gap-3">
                <flux:field>
                    <flux:label>First Name</flux:label>
                    <flux:input wire:model="contactFirstName" placeholder="Jane" />
                    <flux:error name="contactFirstName" />
                </flux:field>
                <flux:field>
                    <flux:label>Last Name</flux:label>
                    <flux:input wire:model="contactLastName" placeholder="Smith" />
                    <flux:error name="contactLastName" />
                </flux:field>
            </div>
            <flux:field>
                <flux:label>Contact Email</flux:label>
                <flux:input wire:model="contactEmail" type="email" placeholder="jane@client.com" />
                <flux:error name="contactEmail" />
            </flux:field>
            <flux:field>
                <flux:label>Contact Phone</flux:label>
                <flux:input wire:model="contactPhone" placeholder="+27 82 000 0000" />
                <flux:error name="contactPhone" />
            </flux:field>
            <label class="flex items-center gap-2 text-sm text-ink cursor-pointer">
                <input type="checkbox" wire:model="contactReceivesInvoices" class="rounded border-line text-primary">
                Receives invoices
            </label>
        </div>
    </div>
    @endif

    {{-- ===== Contacts (edit only) ===== --}}
    @if($editingId !== null)
    <div class="border-t border-line pt-4">
        <div class="flex items-center justify-between mb-3">
            <p class="text-sm font-semibold text-ink">Contacts</p>
            @if(! $showAddContact)
                <flux:button wire:click="$set('showAddContact', true)" size="sm" variant="ghost" icon="plus">
                    Add Contact
                </flux:button>
            @endif
        </div>

        {{-- Existing contacts list --}}
        @forelse($contacts as $contact)
        <div class="flex items-start justify-between py-2.5 border-b border-line last:border-0 group">
            <div>
                <div class="flex items-center gap-2">
                    <span class="text-sm font-medium text-ink">{{ $contact['full_name'] }}</span>
                    @if($contact['is_primary'])
                        <span class="text-xs px-1.5 py-0.5 rounded bg-blue-50 text-blue-600 font-medium">Primary</span>
                    @endif
                    @if($contact['receives_invoices'])
                        <span class="text-xs px-1.5 py-0.5 rounded bg-surface-alt text-ink-muted">Invoices</span>
                    @endif
                </div>
                @if($contact['email'])
                    <div class="text-xs text-ink-muted mt-0.5">{{ $contact['email'] }}</div>
                @endif
                @if($contact['phone'])
                    <div class="text-xs text-ink-muted">{{ $contact['phone'] }}</div>
                @endif
            </div>
            <flux:button
                wire:click="removeContact('{{ $contact['id'] }}')"
                wire:confirm="Remove this contact?"
                size="sm" variant="ghost" icon="trash"
                class="text-danger hover:text-danger opacity-0 group-hover:opacity-100 transition-opacity"
            />
        </div>
        @empty
            <p class="text-sm text-ink-muted py-2">No contacts yet.</p>
        @endforelse

        {{-- Add contact inline form --}}
        @if($showAddContact)
        <div class="mt-3 p-3 rounded-lg border border-line bg-surface-alt space-y-3">
            <p class="text-xs font-semibold text-ink-muted uppercase tracking-wide">New Contact</p>
            <div class="grid grid-cols-2 gap-3">
                <flux:field>
                    <flux:label>First Name <span class="text-danger">*</span></flux:label>
                    <flux:input wire:model="newContactFirstName" placeholder="Jane" />
                    <flux:error name="newContactFirstName" />
                </flux:field>
                <flux:field>
                    <flux:label>Last Name</flux:label>
                    <flux:input wire:model="newContactLastName" placeholder="Smith" />
                    <flux:error name="newContactLastName" />
                </flux:field>
            </div>
            <flux:field>
                <flux:label>Email</flux:label>
                <flux:input wire:model="newContactEmail" type="email" placeholder="jane@client.com" />
                <flux:error name="newContactEmail" />
            </flux:field>
            <flux:field>
                <flux:label>Phone</flux:label>
                <flux:input wire:model="newContactPhone" placeholder="+27 82 000 0000" />
                <flux:error name="newContactPhone" />
            </flux:field>
            <label class="flex items-center gap-2 text-sm text-ink cursor-pointer">
                <input type="checkbox" wire:model="newContactReceivesInvoices" class="rounded border-line text-primary">
                Receives invoices
            </label>
            <div class="flex items-center gap-2 pt-1">
                <flux:button wire:click="addContact" size="sm" variant="primary">Save Contact</flux:button>
                <flux:button wire:click="$set('showAddContact', false)" size="sm" variant="ghost">Cancel</flux:button>
            </div>
        </div>
        @endif
    </div>
    @endif
</x-crud.form>
</div>
