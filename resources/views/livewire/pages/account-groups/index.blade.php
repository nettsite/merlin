<?php

use App\Livewire\Concerns\HasCrudForm;
use App\Livewire\Concerns\HasCrudTable;
use App\Modules\Accounting\Models\AccountGroup;
use App\Modules\Accounting\Models\AccountType;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layout.app')] class extends Component
{
    use HasCrudTable, HasCrudForm;

    #[Validate('required|exists:account_types,id')]
    public string $accountTypeId = '';

    #[Validate('required|string|max:20')]
    public string $code = '';

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:1000')]
    public string $description = '';

    #[Validate('required|boolean')]
    public bool $isActive = true;

    public function mount(): void
    {
        $this->authorize('viewAny', AccountGroup::class);
    }

    public function create(): void
    {
        $this->authorize('create', AccountGroup::class);
        $this->reset(['accountTypeId', 'code', 'name', 'description']);
        $this->isActive = true;
        $this->editingId = null;
        $this->showForm = true;
    }

    protected function fillForm(string $id): void
    {
        $group = AccountGroup::findOrFail($id);
        $this->authorize('update', $group);
        $this->accountTypeId = $group->account_type_id;
        $this->code = $group->code;
        $this->name = $group->name;
        $this->description = $group->description ?? '';
        $this->isActive = $group->is_active;
    }

    protected function store(): void
    {
        $this->validate();
        AccountGroup::create([
            'account_type_id' => $this->accountTypeId,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description ?: null,
            'is_active' => $this->isActive,
        ]);
    }

    protected function update(): void
    {
        $this->validate();
        $group = AccountGroup::findOrFail($this->editingId);
        $group->update([
            'account_type_id' => $this->accountTypeId,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description ?: null,
            'is_active' => $this->isActive,
        ]);
    }

    protected function performDelete(string $id): void
    {
        $group = AccountGroup::findOrFail($id);
        $this->authorize('delete', $group);
        $group->delete();
    }

    public function with(): array
    {
        $sortColumn = match ($this->sortBy) {
            'type' => 'account_types.name',
            'is_active' => 'account_groups.is_active',
            default => 'account_groups.code',
        };

        return [
            'rows' => AccountGroup::join('account_types', 'account_types.id', '=', 'account_groups.account_type_id')
                ->select('account_groups.*')
                ->with('type')
                ->when(
                    $this->search,
                    fn ($q) => $q->where(function ($q): void {
                        $q->where('account_groups.code', 'like', "%{$this->search}%")
                            ->orWhere('account_groups.name', 'like', "%{$this->search}%");
                    })
                )
                ->orderBy($sortColumn, $this->sortDir)
                ->paginate($this->perPage),
            'accountTypes' => AccountType::orderBy('sort_order')->get(),
        ];
    }
}; ?>

<div>
<x-crud.table title="Account Groups" description="Group accounts by type for reporting">
    <x-slot name="actions">
        @can('create', \App\Modules\Accounting\Models\AccountGroup::class)
            <flux:button wire:click="create" icon="plus" size="sm" variant="primary">
                New Group
            </flux:button>
        @endcan
    </x-slot>

    <table class="w-full text-sm">
        <thead>
            <tr>
                <x-crud.th column="code" :sort-by="$sortBy" :sort-dir="$sortDir">Code</x-crud.th>
                <x-crud.th column="name" :sort-by="$sortBy" :sort-dir="$sortDir">Name</x-crud.th>
                <x-crud.th column="type" :sort-by="$sortBy" :sort-dir="$sortDir">Type</x-crud.th>
                <x-crud.th column="is_active" :sort-by="$sortBy" :sort-dir="$sortDir">Status</x-crud.th>
                <x-crud.th></x-crud.th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $group)
                <tr class="border-t border-line hover:bg-surface-alt group">
                    <td class="px-4 py-3 font-mono text-ink">{{ $group->code }}</td>
                    <td class="px-4 py-3 font-medium text-ink">{{ $group->name }}</td>
                    <td class="px-4 py-3 text-ink-soft">{{ $group->type?->name ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <span @class([
                            'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
                            'bg-green-50 text-success' => $group->is_active,
                            'bg-surface-alt text-ink-muted' => !$group->is_active,
                        ])>
                            {{ $group->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            @can('update', $group)
                                <flux:button wire:click="edit('{{ $group->id }}')" size="sm" variant="ghost" icon="pencil" />
                            @endcan
                            @can('delete', $group)
                                <flux:button
                                    wire:click="delete('{{ $group->id }}')"
                                    wire:confirm="Delete this account group? This cannot be undone."
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
                        <p class="font-medium text-ink">No account groups yet.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <x-slot name="pagination">
        {{ $rows->links() }}
    </x-slot>
</x-crud.table>

<x-crud.form title="Account Group" :editing="$editingId !== null">
    <flux:field>
        <flux:label>Account Type <span class="text-danger">*</span></flux:label>
        <flux:select wire:model="accountTypeId">
            <option value="">Select type…</option>
            @foreach($accountTypes as $type)
                <option value="{{ $type->id }}">{{ $type->name }}</option>
            @endforeach
        </flux:select>
        <flux:error name="accountTypeId" />
    </flux:field>

    <flux:field>
        <flux:label>Code <span class="text-danger">*</span></flux:label>
        <flux:input wire:model="code" placeholder="e.g. 5100" />
        <flux:error name="code" />
    </flux:field>

    <flux:field>
        <flux:label>Name <span class="text-danger">*</span></flux:label>
        <flux:input wire:model="name" placeholder="e.g. Operating Expenses" />
        <flux:error name="name" />
    </flux:field>

    <flux:field>
        <flux:label>Description</flux:label>
        <flux:textarea wire:model="description" rows="2" />
        <flux:error name="description" />
    </flux:field>

    <flux:field>
        <flux:label>Status</flux:label>
        <flux:select wire:model="isActive">
            <option value="1">Active</option>
            <option value="0">Inactive</option>
        </flux:select>
        <flux:error name="isActive" />
    </flux:field>
</x-crud.form>
</div>
