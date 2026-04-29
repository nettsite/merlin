<?php

use App\Livewire\Concerns\HasCrudForm;
use App\Livewire\Concerns\HasCrudTable;
use App\Modules\Core\Models\Role;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layout.app')] class extends Component
{
    use HasCrudTable, HasCrudForm;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|email|max:255')]
    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public string $selectedRole = '';

    public function mount(): void
    {
        $this->authorize('viewAny', User::class);
    }

    public function create(): void
    {
        $this->authorize('create', User::class);
        $this->reset(['name', 'email', 'password', 'passwordConfirmation', 'selectedRole']);
        $this->editingId = null;
        $this->showForm = true;
    }

    protected function fillForm(string $id): void
    {
        $user = User::with('roles')->findOrFail($id);
        $this->authorize('update', $user);
        $this->name = $user->name;
        $this->email = $user->email;
        $this->password = '';
        $this->passwordConfirmation = '';
        $this->selectedRole = $user->roles->first()?->name ?? '';
    }

    protected function store(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|same:passwordConfirmation',
        ]);

        $user = User::create([
            'name' => $this->name,
            'email' => $this->email,
            'password' => Hash::make($this->password),
        ]);

        if ($this->selectedRole) {
            $user->syncRoles([$this->selectedRole]);
        }
    }

    protected function update(): void
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => "required|email|max:255|unique:users,email,{$this->editingId}",
        ];

        if ($this->password !== '') {
            $rules['password'] = 'required|string|min:8|same:passwordConfirmation';
        }

        $this->validate($rules);

        $user = User::findOrFail($this->editingId);
        $data = ['name' => $this->name, 'email' => $this->email];

        if ($this->password !== '') {
            $data['password'] = Hash::make($this->password);
        }

        $user->update($data);
        $user->syncRoles($this->selectedRole ? [$this->selectedRole] : []);
    }

    protected function performDelete(string $id): void
    {
        $user = User::findOrFail($id);
        $this->authorize('delete', $user);
        $user->delete();
    }

    public function with(): array
    {
        return [
            'rows' => User::with('roles')
                ->when(
                    $this->search,
                    fn ($q) => $q->where(function ($q): void {
                        $q->where('name', 'like', "%{$this->search}%")
                            ->orWhere('email', 'like', "%{$this->search}%");
                    })
                )
                ->orderBy('name')
                ->paginate($this->perPage),
            'roles' => Role::orderBy('name')->pluck('name'),
        ];
    }
}; ?>

<div>
<x-crud.table title="Users" description="Manage user accounts and roles">
    <x-slot name="actions">
        @can('create', \App\Modules\Core\Models\User::class)
            <flux:button wire:click="create" icon="plus" size="sm" variant="primary">
                New User
            </flux:button>
        @endcan
    </x-slot>

    <table class="w-full text-sm">
        <thead>
            <tr>
                <x-crud.th>Name</x-crud.th>
                <x-crud.th>Email</x-crud.th>
                <x-crud.th>Role</x-crud.th>
                <x-crud.th></x-crud.th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $user)
                <tr class="border-t border-line hover:bg-surface-alt group">
                    <td class="px-4 py-3 font-medium text-ink">{{ $user->name }}</td>
                    <td class="px-4 py-3 text-ink-soft">{{ $user->email }}</td>
                    <td class="px-4 py-3 text-ink-soft">
                        {{ $user->roles->first()?->name ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            @can('update', $user)
                                <flux:button wire:click="edit('{{ $user->id }}')" size="sm" variant="ghost" icon="pencil" />
                            @endcan
                            @can('delete', $user)
                                <flux:button
                                    wire:click="delete('{{ $user->id }}')"
                                    wire:confirm="Delete this user? This cannot be undone."
                                    size="sm" variant="ghost" icon="trash"
                                    class="text-danger hover:text-danger"
                                />
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-4 py-12 text-center">
                        <p class="font-medium text-ink">No users found.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <x-slot name="pagination">
        {{ $rows->links() }}
    </x-slot>
</x-crud.table>

<x-crud.form title="User" :editing="$editingId !== null">
    <flux:field>
        <flux:label>Name <span class="text-danger">*</span></flux:label>
        <flux:input wire:model="name" />
        <flux:error name="name" />
    </flux:field>

    <flux:field>
        <flux:label>Email <span class="text-danger">*</span></flux:label>
        <flux:input wire:model="email" type="email" />
        <flux:error name="email" />
    </flux:field>

    <flux:field>
        <flux:label>{{ $editingId ? 'New Password' : 'Password' }} @if(!$editingId)<span class="text-danger">*</span>@endif</flux:label>
        <flux:input wire:model="password" type="password" placeholder="{{ $editingId ? 'Leave blank to keep current' : '' }}" />
        <flux:error name="password" />
    </flux:field>

    <flux:field>
        <flux:label>Confirm Password</flux:label>
        <flux:input wire:model="passwordConfirmation" type="password" />
    </flux:field>

    <flux:field>
        <flux:label>Role</flux:label>
        <flux:select wire:model="selectedRole">
            <option value="">No role</option>
            @foreach($roles as $role)
                <option value="{{ $role }}">{{ $role }}</option>
            @endforeach
        </flux:select>
    </flux:field>
</x-crud.form>
</div>
