<?php

use App\Livewire\Concerns\HasCrudForm;
use App\Livewire\Concerns\HasCrudTable;
use App\Modules\Core\Models\BankTemplate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layout.app')] class extends Component
{
    use HasCrudTable, HasCrudForm;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|max:255')]
    public string $bankName = '';

    #[Validate('nullable|string')]
    public string $layoutHints = '';

    #[Validate('required|boolean')]
    public bool $isActive = true;

    public function mount(): void
    {
        $this->authorize('viewAny', BankTemplate::class);
    }

    public function create(): void
    {
        $this->authorize('create', BankTemplate::class);
        $this->reset(['name', 'bankName', 'layoutHints']);
        $this->isActive = true;
        $this->editingId = null;
        $this->showForm = true;
    }

    protected function fillForm(string $id): void
    {
        $template = BankTemplate::findOrFail($id);
        $this->authorize('update', $template);
        $this->name = $template->name;
        $this->bankName = $template->bank_name;
        $this->layoutHints = $template->layout_hints ?? '';
        $this->isActive = $template->is_active;
    }

    protected function store(): void
    {
        $this->validate();
        BankTemplate::create([
            'name' => $this->name,
            'bank_name' => $this->bankName,
            'layout_hints' => $this->layoutHints ?: null,
            'is_active' => $this->isActive,
        ]);
    }

    protected function update(): void
    {
        $this->validate();
        $template = BankTemplate::findOrFail($this->editingId);
        $this->authorize('update', $template);
        $template->update([
            'name' => $this->name,
            'bank_name' => $this->bankName,
            'layout_hints' => $this->layoutHints ?: null,
            'is_active' => $this->isActive,
        ]);
    }

    protected function performDelete(string $id): void
    {
        $template = BankTemplate::findOrFail($id);
        $this->authorize('delete', $template);
        $template->delete();
    }

    public function with(): array
    {
        return [
            'rows' => BankTemplate::when(
                $this->search,
                fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('bank_name', 'like', "%{$this->search}%")
            )
                ->orderBy($this->sortBy === 'name' ? 'name' : 'bank_name', $this->sortDir)
                ->paginate($this->perPage),
        ];
    }
}; ?>

<div>
<x-crud.table title="Bank Templates" description="Layout hints used by the AI when reading bank statements. Generated automatically after each successful extraction.">
    <x-slot name="actions">
        @can('create', \App\Modules\Core\Models\BankTemplate::class)
            <flux:button wire:click="create" icon="plus" size="sm" variant="primary">
                New Template
            </flux:button>
        @endcan
    </x-slot>

    <table class="w-full text-sm">
        <thead>
            <tr>
                <x-crud.th>Name</x-crud.th>
                <x-crud.th>Bank</x-crud.th>
                <x-crud.th>Hints</x-crud.th>
                <x-crud.th>Status</x-crud.th>
                <x-crud.th></x-crud.th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $template)
                <tr class="border-t border-line hover:bg-surface-alt group">
                    <td class="px-4 py-3 font-medium text-ink">{{ $template->name }}</td>
                    <td class="px-4 py-3 text-ink-soft">{{ $template->bank_name }}</td>
                    <td class="px-4 py-3">
                        @if($template->layout_hints)
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-50 text-green-700">Generated</span>
                        @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-surface-alt text-ink-muted">None yet</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <span @class([
                            'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
                            'bg-green-50 text-success' => $template->is_active,
                            'bg-surface-alt text-ink-muted' => !$template->is_active,
                        ])>{{ $template->is_active ? 'Active' : 'Inactive' }}</span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            @can('update', $template)
                                <flux:button wire:click="edit('{{ $template->id }}')" size="sm" variant="ghost" icon="pencil" />
                            @endcan
                            @can('delete', $template)
                                <flux:button
                                    wire:click="delete('{{ $template->id }}')"
                                    wire:confirm="Delete this bank template? This will not affect statements already processed with it."
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
                        <p class="font-medium text-ink">No bank templates yet.</p>
                        <p class="mt-1 text-sm text-ink-muted">Templates are created automatically the first time a statement from a new bank is processed.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <x-slot name="pagination">
        {{ $rows->links() }}
    </x-slot>
</x-crud.table>

<x-crud.form title="Bank Template" :editing="$editingId !== null">
    <flux:field>
        <flux:label>Name <span class="text-danger">*</span></flux:label>
        <flux:input wire:model="name" placeholder="e.g. FNB Business Account" />
        <flux:error name="name" />
    </flux:field>

    <flux:field>
        <flux:label>Bank name <span class="text-danger">*</span></flux:label>
        <flux:input wire:model="bankName" placeholder="e.g. First National Bank" />
        <flux:description>Must match the bank name the AI extracts from the statement header.</flux:description>
        <flux:error name="bankName" />
    </flux:field>

    <flux:field>
        <flux:label>Layout hints</flux:label>
        <flux:textarea wire:model="layoutHints" rows="10" placeholder="Leave blank — generated automatically after the first successful extraction." />
        <flux:description>Plain-text bullet points passed to the AI when reading statements from this bank. Auto-generated and updated after each extraction.</flux:description>
        <flux:error name="layoutHints" />
    </flux:field>

    <flux:field>
        <flux:label>Status</flux:label>
        <flux:select wire:model="isActive">
            <option value="1">Active</option>
            <option value="0">Inactive</option>
        </flux:select>
    </flux:field>
</x-crud.form>
</div>
