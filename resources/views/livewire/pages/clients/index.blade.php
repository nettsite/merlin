<?php

use App\Livewire\Concerns\HasCrudForm;
use App\Livewire\Concerns\HasCrudTable;
use App\Modules\Billing\Models\PaymentTerm;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\PartyRelationship;
use App\Modules\Core\Services\PartyService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layout.app')] class extends Component
{
    use HasCrudTable, HasCrudForm;

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

    public function mount(): void
    {
        $this->authorize('viewAny', Party::class);
    }

    public function create(): void
    {
        $this->authorize('create', Party::class);
        $this->reset(['legalName', 'tradingName', 'email', 'phone', 'notes', 'paymentTermId']);
        $this->status = 'active';
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
    }

    protected function store(): void
    {
        $this->validate();
        $party = app(PartyService::class)->createBusiness([
            'business_type' => 'company',
            'legal_name' => $this->legalName,
            'trading_name' => $this->tradingName ?: $this->legalName,
            'primary_email' => $this->email ?: null,
            'primary_phone' => $this->phone ?: null,
            'notes' => $this->notes ?: null,
            'status' => $this->status,
        ], ['client']);

        $this->saveClientRelationshipMetadata($party);
    }

    protected function update(): void
    {
        $this->validate();
        $party = Party::with(['business', 'relationships'])->findOrFail($this->editingId);
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

        $this->saveClientRelationshipMetadata($party);
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

    protected function performDelete(string $id): void
    {
        $party = Party::findOrFail($id);
        $this->authorize('delete', $party);
        $party->delete();
    }

    public function with(): array
    {
        $sortColumn = match ($this->sortBy) {
            'status' => 'parties.status',
            'primary_email' => 'parties.primary_email',
            default => 'businesses.legal_name',
        };

        return [
            'paymentTerms' => PaymentTerm::orderBy('name')->get(['id', 'name']),
            'rows' => Party::clients()
                ->join('businesses', 'businesses.id', '=', 'parties.id')
                ->select('parties.*')
                ->with('business')
                ->when(
                    $this->search,
                    fn ($q) => $q->where(function ($q): void {
                        $q->where('businesses.legal_name', 'like', "%{$this->search}%")
                            ->orWhere('businesses.trading_name', 'like', "%{$this->search}%")
                            ->orWhere('parties.primary_email', 'like', "%{$this->search}%");
                    })
                )
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

    <table class="w-full text-sm">
        <thead>
            <tr>
                <x-crud.th column="name" :sort-by="$sortBy" :sort-dir="$sortDir">Name</x-crud.th>
                <x-crud.th column="primary_email" :sort-by="$sortBy" :sort-dir="$sortDir">Email</x-crud.th>
                <x-crud.th>Phone</x-crud.th>
                <x-crud.th column="status" :sort-by="$sortBy" :sort-dir="$sortDir">Status</x-crud.th>
                <x-crud.th></x-crud.th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $party)
                <tr class="border-t border-line hover:bg-surface-alt group">
                    <td class="px-4 py-3 font-medium text-ink">
                        {{ $party->business?->display_name ?? '—' }}
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
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            @can('update', $party)
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
                    <td colspan="5" class="px-4 py-12 text-center">
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
</x-crud.form>
</div>
