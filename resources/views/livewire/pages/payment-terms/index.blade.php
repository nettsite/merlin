<?php

use App\Livewire\Concerns\HasCrudForm;
use App\Livewire\Concerns\HasCrudTable;
use App\Modules\Billing\Enums\PaymentTermRule;
use App\Modules\Billing\Models\PaymentTerm;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layout.app')] class extends Component
{
    use HasCrudTable, HasCrudForm;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string')]
    public string $rule = '';

    #[Validate('nullable|integer|min:1|max:365')]
    public ?int $days = null;

    #[Validate('nullable|integer|min:1|max:28')]
    public ?int $dayOfMonth = null;

    public function mount(): void
    {
        $this->authorize('viewAny', PaymentTerm::class);
    }

    public function create(): void
    {
        $this->authorize('create', PaymentTerm::class);
        $this->reset(['name', 'days', 'dayOfMonth']);
        $this->rule = PaymentTermRule::DaysAfterInvoice->value;
        $this->editingId = null;
        $this->showForm = true;
    }

    protected function fillForm(string $id): void
    {
        $term = PaymentTerm::findOrFail($id);
        $this->authorize('update', $term);
        $this->name = $term->name;
        $this->rule = $term->rule->value;
        $this->days = $term->days;
        $this->dayOfMonth = $term->day_of_month;
    }

    protected function store(): void
    {
        $this->validateTermForm();
        PaymentTerm::create($this->termData());
    }

    protected function update(): void
    {
        $this->validateTermForm();
        PaymentTerm::findOrFail($this->editingId)->update($this->termData());
    }

    private function validateTermForm(): void
    {
        $rule = $this->rule ? PaymentTermRule::tryFrom($this->rule) : null;

        $this->validate([
            'name' => 'required|string|max:255',
            'rule' => 'required|string',
            'days' => $rule?->requiresDays() ? 'required|integer|min:1|max:365' : 'nullable|integer|min:1|max:365',
            'dayOfMonth' => $rule?->requiresDayOfMonth() ? 'required|integer|min:1|max:28' : 'nullable|integer|min:1|max:28',
        ]);
    }

    protected function performDelete(string $id): void
    {
        $term = PaymentTerm::findOrFail($id);
        $this->authorize('delete', $term);
        $term->delete();
    }

    /** @return array<string, mixed> */
    private function termData(): array
    {
        $rule = PaymentTermRule::from($this->rule);

        return [
            'name' => $this->name,
            'rule' => $rule,
            'days' => $rule->requiresDays() ? $this->days : null,
            'day_of_month' => $rule->requiresDayOfMonth() ? $this->dayOfMonth : null,
        ];
    }

    public function with(): array
    {
        return [
            'rules' => PaymentTermRule::cases(),
            'rows' => PaymentTerm::when(
                $this->search,
                fn ($q) => $q->where('name', 'like', "%{$this->search}%")
            )
                ->orderBy('name')
                ->paginate($this->perPage),
        ];
    }
}; ?>

<div>
<x-crud.table title="Payment Terms" description="Reusable due date rules for clients and invoices">
    <x-slot name="actions">
        @can('create', \App\Modules\Billing\Models\PaymentTerm::class)
            <flux:button wire:click="create" icon="plus" size="sm" variant="primary">
                New Payment Term
            </flux:button>
        @endcan
    </x-slot>

    <table class="w-full text-sm">
        <thead>
            <tr>
                <x-crud.th column="name" :sort-by="$sortBy" :sort-dir="$sortDir">Name</x-crud.th>
                <x-crud.th>Rule</x-crud.th>
                <x-crud.th>Parameters</x-crud.th>
                <x-crud.th></x-crud.th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $term)
                <tr class="border-t border-line hover:bg-surface-alt group">
                    <td class="px-4 py-3 font-medium text-ink">
                        {{ $term->name }}
                    </td>
                    <td class="px-4 py-3 text-ink-soft">
                        {{ $term->rule->label() }}
                    </td>
                    <td class="px-4 py-3 text-ink-soft tabular-nums">
                        @if($term->days !== null)
                            {{ $term->days }} days
                        @elseif($term->day_of_month !== null)
                            Day {{ $term->day_of_month }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            @can('update', $term)
                                <flux:button wire:click="edit('{{ $term->id }}')" size="sm" variant="ghost" icon="pencil" />
                            @endcan
                            @can('delete', $term)
                                <flux:button
                                    wire:click="delete('{{ $term->id }}')"
                                    wire:confirm="Delete this payment term? This cannot be undone."
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
                        <p class="font-medium text-ink">No payment terms yet.</p>
                        <p class="mt-1 text-sm text-ink-muted">Add payment terms to define due date rules for your clients.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <x-slot name="pagination">
        {{ $rows->links() }}
    </x-slot>
</x-crud.table>

<x-crud.form title="Payment Term" :editing="$editingId !== null">
    <flux:field>
        <flux:label>Name <span class="text-danger">*</span></flux:label>
        <flux:input wire:model="name" placeholder="e.g. 30 Days, EOM" />
        <flux:error name="name" />
    </flux:field>

    <flux:field>
        <flux:label>Rule <span class="text-danger">*</span></flux:label>
        <flux:select wire:model.live="rule">
            <option value="">Select a rule…</option>
            @foreach($rules as $ruleCase)
                <option value="{{ $ruleCase->value }}">{{ $ruleCase->label() }}</option>
            @endforeach
        </flux:select>
        <flux:error name="rule" />
    </flux:field>

    @if($rule && \App\Modules\Billing\Enums\PaymentTermRule::from($rule)->requiresDays())
        <flux:field>
            <flux:label>Days <span class="text-danger">*</span></flux:label>
            <flux:input wire:model="days" type="number" min="1" max="365" placeholder="e.g. 30" />
            <flux:error name="days" />
        </flux:field>
    @endif

    @if($rule && \App\Modules\Billing\Enums\PaymentTermRule::from($rule)->requiresDayOfMonth())
        <flux:field>
            <flux:label>Day of Month <span class="text-danger">*</span></flux:label>
            <flux:input wire:model="dayOfMonth" type="number" min="1" max="28" placeholder="e.g. 25" />
            <flux:description>Enter a day between 1 and 28 (to work across all months).</flux:description>
            <flux:error name="dayOfMonth" />
        </flux:field>
    @endif
</x-crud.form>
</div>
