<?php

use App\Livewire\Concerns\HasCrudForm;
use App\Livewire\Concerns\HasCrudTable;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountGroup;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layout.app')] class extends Component
{
    use HasCrudTable, HasCrudForm;

    #[Validate('required|exists:account_groups,id')]
    public string $accountGroupId = '';

    #[Validate('nullable|exists:accounts,id')]
    public ?string $parentId = null;

    #[Validate('required|string|max:20')]
    public string $code = '';

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:1000')]
    public string $description = '';

    #[Validate('required|boolean')]
    public bool $isActive = true;

    #[Validate('required|boolean')]
    public bool $allowDirectPosting = true;

    public function mount(): void
    {
        $this->authorize('viewAny', Account::class);
    }

    public function create(): void
    {
        $this->authorize('create', Account::class);
        $this->reset(['accountGroupId', 'parentId', 'code', 'name', 'description']);
        $this->isActive = true;
        $this->allowDirectPosting = true;
        $this->editingId = null;
        $this->showForm = true;
    }

    protected function fillForm(string $id): void
    {
        $account = Account::findOrFail($id);
        $this->authorize('update', $account);
        $this->accountGroupId = $account->account_group_id;
        $this->parentId = $account->parent_id;
        $this->code = $account->code;
        $this->name = $account->name;
        $this->description = $account->description ?? '';
        $this->isActive = $account->is_active;
        $this->allowDirectPosting = $account->allow_direct_posting;
    }

    protected function store(): void
    {
        $this->validate();
        Account::create([
            'account_group_id' => $this->accountGroupId,
            'parent_id' => $this->parentId ?: null,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description ?: null,
            'is_active' => $this->isActive,
            'allow_direct_posting' => $this->allowDirectPosting,
        ]);
    }

    protected function update(): void
    {
        $this->validate();
        $account = Account::findOrFail($this->editingId);
        $account->update([
            'account_group_id' => $this->accountGroupId,
            'parent_id' => $this->parentId ?: null,
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description ?: null,
            'is_active' => $this->isActive,
            'allow_direct_posting' => $this->allowDirectPosting,
        ]);
    }

    protected function performDelete(string $id): void
    {
        $account = Account::findOrFail($id);
        $this->authorize('delete', $account);
        $account->delete();
    }

    public function with(): array
    {
        $sortColumn = match ($this->sortBy) {
            'group' => 'account_groups.name',
            'is_active' => 'accounts.is_active',
            default => 'accounts.code',
        };

        return [
            'rows' => Account::join('account_groups', 'account_groups.id', '=', 'accounts.account_group_id')
                ->select('accounts.*')
                ->with('group')
                ->when(
                    $this->search,
                    fn ($q) => $q->where(function ($q): void {
                        $q->where('accounts.code', 'like', "%{$this->search}%")
                            ->orWhere('accounts.name', 'like', "%{$this->search}%");
                    })
                )
                ->orderBy($sortColumn, $this->sortDir)
                ->paginate($this->perPage),
            'accountGroups' => AccountGroup::with('type')->orderBy('code')->get(),
            'parentAccounts' => Account::orderBy('code')->get(['id', 'code', 'name']),
        ];
    }
}; ?>

<div>
<x-crud.table title="Accounts" description="Chart of accounts">
    <x-slot name="actions">
        @can('create', \App\Modules\Accounting\Models\Account::class)
            <flux:button wire:click="create" icon="plus" size="sm" variant="primary">
                New Account
            </flux:button>
        @endcan
    </x-slot>

    <table class="w-full text-sm">
        <thead>
            <tr>
                <x-crud.th column="code" :sort-by="$sortBy" :sort-dir="$sortDir">Code</x-crud.th>
                <x-crud.th column="name" :sort-by="$sortBy" :sort-dir="$sortDir">Name</x-crud.th>
                <x-crud.th column="group" :sort-by="$sortBy" :sort-dir="$sortDir">Group</x-crud.th>
                <x-crud.th>Posting</x-crud.th>
                <x-crud.th column="is_active" :sort-by="$sortBy" :sort-dir="$sortDir">Status</x-crud.th>
                <x-crud.th></x-crud.th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $account)
                <tr class="border-t border-line hover:bg-surface-alt group">
                    <td class="px-4 py-3 font-mono text-ink">{{ $account->code }}</td>
                    <td class="px-4 py-3 font-medium text-ink">{{ $account->name }}</td>
                    <td class="px-4 py-3 text-ink-soft">{{ $account->group?->name ?? '—' }}</td>
                    <td class="px-4 py-3 text-ink-soft">
                        {{ $account->allow_direct_posting ? 'Yes' : 'No' }}
                    </td>
                    <td class="px-4 py-3">
                        <span @class([
                            'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
                            'bg-green-50 text-success' => $account->is_active,
                            'bg-surface-alt text-ink-muted' => !$account->is_active,
                        ])>
                            {{ $account->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            @can('update', $account)
                                <flux:button wire:click="edit('{{ $account->id }}')" size="sm" variant="ghost" icon="pencil" />
                            @endcan
                            @can('delete', $account)
                                <flux:button
                                    wire:click="delete('{{ $account->id }}')"
                                    wire:confirm="Delete this account? This cannot be undone."
                                    size="sm" variant="ghost" icon="trash"
                                    class="text-danger hover:text-danger"
                                />
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-12 text-center">
                        <p class="font-medium text-ink">No accounts yet.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <x-slot name="pagination">
        {{ $rows->links() }}
    </x-slot>
</x-crud.table>

<x-crud.form title="Account" :editing="$editingId !== null">
    <flux:field>
        <flux:label>Account Group <span class="text-danger">*</span></flux:label>
        <flux:select wire:model="accountGroupId">
            <option value="">Select group…</option>
            @foreach($accountGroups as $group)
                <option value="{{ $group->id }}">{{ $group->code }} — {{ $group->name }} ({{ $group->type?->name }})</option>
            @endforeach
        </flux:select>
        <flux:error name="accountGroupId" />
    </flux:field>

    <flux:field>
        <flux:label>Parent Account</flux:label>
        <flux:select wire:model="parentId">
            <option value="">None (top-level)</option>
            @foreach($parentAccounts as $parent)
                <option value="{{ $parent->id }}">{{ $parent->code }} — {{ $parent->name }}</option>
            @endforeach
        </flux:select>
        <flux:error name="parentId" />
    </flux:field>

    <flux:field>
        <flux:label>Code <span class="text-danger">*</span></flux:label>
        <flux:input wire:model="code" placeholder="e.g. 5110" />
        <flux:error name="code" />
    </flux:field>

    <flux:field>
        <flux:label>Name <span class="text-danger">*</span></flux:label>
        <flux:input wire:model="name" placeholder="e.g. Office Supplies" />
        <flux:error name="name" />
    </flux:field>

    <flux:field>
        <flux:label>Description</flux:label>
        <flux:textarea wire:model="description" rows="2" />
        <flux:error name="description" />
    </flux:field>

    <div class="flex gap-6">
        <flux:field>
            <flux:label>Status</flux:label>
            <flux:select wire:model="isActive">
                <option value="1">Active</option>
                <option value="0">Inactive</option>
            </flux:select>
        </flux:field>

        <flux:field>
            <flux:label>Allow Direct Posting</flux:label>
            <flux:select wire:model="allowDirectPosting">
                <option value="1">Yes</option>
                <option value="0">No</option>
            </flux:select>
        </flux:field>
    </div>
</x-crud.form>
</div>
