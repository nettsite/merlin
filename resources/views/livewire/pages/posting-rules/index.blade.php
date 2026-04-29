<?php

use App\Livewire\Concerns\HasCrudForm;
use App\Livewire\Concerns\HasCrudTable;
use App\Modules\Core\Models\Party;
use App\Modules\Purchasing\Models\PostingRule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layout.app')] class extends Component
{
    use HasCrudTable, HasCrudForm;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:1000')]
    public string $description = '';

    #[Validate('nullable|exists:parties,id')]
    public ?string $partyId = null;

    #[Validate('required|boolean')]
    public bool $isActive = true;

    // Condition fields
    public string $minConfidence = '';

    public string $totalMin = '';

    public string $totalMax = '';

    public string $lineDescContains = '';

    // Action flags
    public bool $autoApprove = false;

    public bool $autoPost = false;

    public function mount(): void
    {
        $this->authorize('viewAny', PostingRule::class);
    }

    public function create(): void
    {
        $this->authorize('create', PostingRule::class);
        $this->reset(['name', 'description', 'partyId', 'minConfidence', 'totalMin', 'totalMax', 'lineDescContains']);
        $this->isActive = true;
        $this->autoApprove = false;
        $this->autoPost = false;
        $this->editingId = null;
        $this->showForm = true;
    }

    protected function fillForm(string $id): void
    {
        $rule = PostingRule::findOrFail($id);
        $this->authorize('update', $rule);
        $this->name = $rule->name;
        $this->description = $rule->description ?? '';
        $this->partyId = $rule->party_id;
        $this->isActive = $rule->is_active;

        $conditions = $rule->conditions ?? [];
        $this->minConfidence = (string) ($conditions['min_confidence'] ?? '');
        $this->totalMin = (string) ($conditions['total_range']['min'] ?? '');
        $this->totalMax = (string) ($conditions['total_range']['max'] ?? '');
        $this->lineDescContains = implode(', ', (array) ($conditions['line_description_contains'] ?? []));

        $actions = $rule->actions ?? [];
        $this->autoApprove = (bool) ($actions['auto_approve'] ?? false);
        $this->autoPost = (bool) ($actions['auto_post'] ?? false);
    }

    protected function store(): void
    {
        $this->validate();
        PostingRule::create([
            'name' => $this->name,
            'description' => $this->description ?: null,
            'party_id' => $this->partyId ?: null,
            'is_active' => $this->isActive,
            'conditions' => $this->buildConditions(),
            'actions' => $this->buildActions(),
        ]);
    }

    protected function update(): void
    {
        $this->validate();
        PostingRule::findOrFail($this->editingId)->update([
            'name' => $this->name,
            'description' => $this->description ?: null,
            'party_id' => $this->partyId ?: null,
            'is_active' => $this->isActive,
            'conditions' => $this->buildConditions(),
            'actions' => $this->buildActions(),
        ]);
    }

    protected function performDelete(string $id): void
    {
        $rule = PostingRule::findOrFail($id);
        $this->authorize('delete', $rule);
        $rule->delete();
    }

    /** @return array<string, mixed> */
    private function buildConditions(): array
    {
        $conditions = [];

        if ($this->minConfidence !== '') {
            $conditions['min_confidence'] = (float) $this->minConfidence;
        }

        if ($this->totalMin !== '' || $this->totalMax !== '') {
            $range = [];
            if ($this->totalMin !== '') {
                $range['min'] = (float) $this->totalMin;
            }
            if ($this->totalMax !== '') {
                $range['max'] = (float) $this->totalMax;
            }
            $conditions['total_range'] = $range;
        }

        if ($this->lineDescContains !== '') {
            $conditions['line_description_contains'] = array_map(
                'trim',
                explode(',', $this->lineDescContains)
            );
        }

        return $conditions;
    }

    /** @return array<string, bool> */
    private function buildActions(): array
    {
        return [
            'auto_approve' => $this->autoApprove,
            'auto_post' => $this->autoPost,
        ];
    }

    public function with(): array
    {
        return [
            'rows' => PostingRule::with('party.business')
                ->when(
                    $this->search,
                    fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                )
                ->orderByDesc('match_count')
                ->orderBy('name')
                ->paginate($this->perPage),
            'suppliers' => Party::suppliers()->with('business')->orderBy('id')->get(),
        ];
    }
}; ?>

<div>
<x-crud.table title="Posting Rules" description="Automated invoice approval and posting rules">
    <x-slot name="actions">
        @can('create', \App\Modules\Purchasing\Models\PostingRule::class)
            <flux:button wire:click="create" icon="plus" size="sm" variant="primary">
                New Rule
            </flux:button>
        @endcan
    </x-slot>

    <table class="w-full text-sm">
        <thead>
            <tr>
                <x-crud.th>Name</x-crud.th>
                <x-crud.th>Supplier</x-crud.th>
                <x-crud.th>Actions</x-crud.th>
                <x-crud.th>Matches</x-crud.th>
                <x-crud.th>Status</x-crud.th>
                <x-crud.th></x-crud.th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $rule)
                <tr class="border-t border-line hover:bg-surface-alt group">
                    <td class="px-4 py-3 font-medium text-ink">
                        {{ $rule->name }}
                        @if($rule->description)
                            <p class="text-xs text-ink-muted mt-0.5">{{ $rule->description }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-ink-soft">
                        {{ $rule->party?->business?->display_name ?? 'Any supplier' }}
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex gap-1 flex-wrap">
                            @if($rule->actions['auto_approve'] ?? false)
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-50 text-blue-700">Auto-approve</span>
                            @endif
                            @if($rule->actions['auto_post'] ?? false)
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-purple-50 text-purple-700">Auto-post</span>
                            @endif
                        </div>
                    </td>
                    <td class="px-4 py-3 text-ink-soft tabular-nums">
                        {{ $rule->match_count }}
                        @if($rule->last_matched_at)
                            <span class="text-xs text-ink-muted block">{{ $rule->last_matched_at->diffForHumans() }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <span @class([
                            'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
                            'bg-green-50 text-success' => $rule->is_active,
                            'bg-surface-alt text-ink-muted' => !$rule->is_active,
                        ])>
                            {{ $rule->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            @can('update', $rule)
                                <flux:button wire:click="edit('{{ $rule->id }}')" size="sm" variant="ghost" icon="pencil" />
                            @endcan
                            @can('delete', $rule)
                                <flux:button
                                    wire:click="delete('{{ $rule->id }}')"
                                    wire:confirm="Delete this posting rule?"
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
                        <p class="font-medium text-ink">No posting rules yet.</p>
                        <p class="mt-1 text-sm text-ink-muted">Rules automate invoice approval and posting based on conditions.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <x-slot name="pagination">
        {{ $rows->links() }}
    </x-slot>
</x-crud.table>

<x-crud.form title="Posting Rule" :editing="$editingId !== null">
    <flux:field>
        <flux:label>Name <span class="text-danger">*</span></flux:label>
        <flux:input wire:model="name" placeholder="e.g. Regular office supplier" />
        <flux:error name="name" />
    </flux:field>

    <flux:field>
        <flux:label>Description</flux:label>
        <flux:textarea wire:model="description" rows="2" />
    </flux:field>

    <flux:field>
        <flux:label>Restrict to Supplier</flux:label>
        <flux:select wire:model="partyId">
            <option value="">Any supplier</option>
            @foreach($suppliers as $supplier)
                <option value="{{ $supplier->id }}">{{ $supplier->business?->display_name }}</option>
            @endforeach
        </flux:select>
        <flux:error name="partyId" />
    </flux:field>

    <div class="border border-line rounded-lg p-4 space-y-3">
        <p class="text-sm font-medium text-ink">Conditions</p>

        <flux:field>
            <flux:label>Min. LLM Confidence (0–1)</flux:label>
            <flux:input wire:model="minConfidence" type="number" step="0.01" min="0" max="1" placeholder="e.g. 0.95" />
        </flux:field>

        <div class="grid grid-cols-2 gap-3">
            <flux:field>
                <flux:label>Total Amount ≥</flux:label>
                <flux:input wire:model="totalMin" type="number" step="0.01" placeholder="0.00" />
            </flux:field>
            <flux:field>
                <flux:label>Total Amount ≤</flux:label>
                <flux:input wire:model="totalMax" type="number" step="0.01" placeholder="Any" />
            </flux:field>
        </div>

        <flux:field>
            <flux:label>Line Description Contains</flux:label>
            <flux:input wire:model="lineDescContains" placeholder="keyword1, keyword2" />
            <flux:description>Comma-separated keywords. At least one line must match.</flux:description>
        </flux:field>
    </div>

    <div class="border border-line rounded-lg p-4 space-y-3">
        <p class="text-sm font-medium text-ink">Actions</p>

        <label class="flex items-center gap-3 cursor-pointer">
            <flux:checkbox wire:model="autoApprove" />
            <div>
                <span class="text-sm font-medium text-ink">Auto-approve</span>
                <p class="text-xs text-ink-muted">Advance through reviewed → approved automatically</p>
            </div>
        </label>

        <label class="flex items-center gap-3 cursor-pointer">
            <flux:checkbox wire:model="autoPost" />
            <div>
                <span class="text-sm font-medium text-ink">Auto-post</span>
                <p class="text-xs text-ink-muted">Post the invoice to the GL without manual approval</p>
            </div>
        </label>
    </div>

    <flux:field>
        <flux:label>Status</flux:label>
        <flux:select wire:model="isActive">
            <option value="1">Active</option>
            <option value="0">Inactive</option>
        </flux:select>
    </flux:field>
</x-crud.form>
</div>
