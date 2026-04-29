<?php

use App\Modules\Core\Models\Role;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Spatie\Permission\Models\Permission;

new #[Layout('components.layout.app')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public bool $showForm = false;

    public ?string $editingId = null;

    #[Validate('required|string|max:255')]
    public string $roleName = '';

    /** @var array<string, bool> */
    public array $selectedPermissions = [];

    public function mount(): void
    {
        $this->authorize('viewAny', \App\Modules\Core\Models\User::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function create(): void
    {
        $this->authorize('create', \App\Modules\Core\Models\User::class);
        $this->roleName = '';
        $this->selectedPermissions = [];
        $this->editingId = null;
        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        $this->authorize('update', \App\Modules\Core\Models\User::class);
        $role = Role::with('permissions')->findOrFail($id);
        $this->editingId = $id;
        $this->roleName = $role->name;
        $this->selectedPermissions = $role->permissions->pluck('name')->mapWithKeys(
            fn ($name) => [$name => true]
        )->toArray();
        $this->showForm = true;
    }

    public function save(): void
    {
        $this->validate();

        $permissions = array_keys(array_filter($this->selectedPermissions));

        if ($this->editingId) {
            $role = Role::findOrFail($this->editingId);
            $role->update(['name' => $this->roleName]);
            $role->syncPermissions($permissions);
        } else {
            $role = Role::create(['name' => $this->roleName, 'guard_name' => 'web']);
            $role->syncPermissions($permissions);
        }

        $this->showForm = false;
        $this->editingId = null;
    }

    public function delete(string $id): void
    {
        $this->authorize('delete', \App\Modules\Core\Models\User::class);
        Role::findOrFail($id)->delete();
        if ($this->editingId === $id) {
            $this->showForm = false;
            $this->editingId = null;
        }
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
    }

    public function with(): array
    {
        return [
            'rows' => Role::withCount('users', 'permissions')
                ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
                ->orderBy('name')
                ->paginate(20),
            'allPermissions' => Permission::orderBy('name')->pluck('name'),
        ];
    }
}; ?>

<div>
<div>
    <div class="flex items-start justify-between px-6 py-5 border-b border-line">
        <div>
            <h1 class="text-[17px] font-semibold tracking-tight text-ink">Roles</h1>
            <p class="mt-0.5 text-sm text-ink-muted">Manage roles and their permissions</p>
        </div>
        <div class="flex items-center gap-3 shrink-0 ml-4">
            <flux:button wire:click="create" icon="plus" size="sm" variant="primary">
                New Role
            </flux:button>
        </div>
    </div>

    <div class="px-6 py-3 border-b border-line bg-surface-alt">
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="Search roles…"
            size="sm"
            icon="magnifying-glass"
            class="max-w-xs"
        />
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Name</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Users</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Permissions</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $role)
                    <tr class="border-t border-line hover:bg-surface-alt group">
                        <td class="px-4 py-3 font-medium text-ink">{{ $role->name }}</td>
                        <td class="px-4 py-3 text-ink-soft tabular-nums">{{ $role->users_count }}</td>
                        <td class="px-4 py-3 text-ink-soft tabular-nums">{{ $role->permissions_count }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                <flux:button wire:click="edit('{{ $role->id }}')" size="sm" variant="ghost" icon="pencil" />
                                <flux:button
                                    wire:click="delete('{{ $role->id }}')"
                                    wire:confirm="Delete this role? Users assigned to it will lose their permissions."
                                    size="sm" variant="ghost" icon="trash"
                                    class="text-danger hover:text-danger"
                                />
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-12 text-center">
                            <p class="font-medium text-ink">No roles found.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="px-6 py-4 border-t border-line">
        {{ $rows->links() }}
    </div>
</div>

<flux:modal name="crud-form" flyout wire:model.self="showForm" class="w-[540px]">
    <form wire:submit="save" class="flex flex-col h-full">
        <div class="p-6 border-b border-line">
            <flux:heading size="lg" class="font-semibold">
                {{ $editingId ? 'Edit Role' : 'New Role' }}
            </flux:heading>
        </div>

        <div class="flex-1 p-6 space-y-5 overflow-y-auto">
            <flux:field>
                <flux:label>Role Name <span class="text-danger">*</span></flux:label>
                <flux:input wire:model="roleName" placeholder="e.g. accountant" />
                <flux:error name="roleName" />
            </flux:field>

            <div>
                <p class="text-sm font-medium text-ink mb-3">Permissions</p>
                <div class="space-y-1 max-h-96 overflow-y-auto border border-line rounded p-3">
                    @foreach($allPermissions as $permission)
                        <label class="flex items-center gap-2 text-sm cursor-pointer hover:bg-surface-alt px-2 py-1 rounded">
                            <input
                                type="checkbox"
                                wire:model="selectedPermissions.{{ $permission }}"
                                class="rounded border-line text-primary"
                            />
                            <span class="font-mono text-xs text-ink-soft">{{ $permission }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-line bg-surface-alt">
            <flux:button type="button" variant="ghost" wire:click="cancelForm">Cancel</flux:button>
            <flux:button type="submit" variant="primary">
                {{ $editingId ? 'Save changes' : 'Create Role' }}
            </flux:button>
        </div>
    </form>
</flux:modal>
</div>
