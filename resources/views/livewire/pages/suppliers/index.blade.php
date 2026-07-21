<?php

use App\Livewire\Concerns\HasCrudTable;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\Document;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('components.layout.app')] class extends Component
{
    use HasCrudTable;

    public string $filterStatus = '';
    public string $dateRange = 'this_year';
    public string $dateFrom = '';
    public string $dateTo = '';

    public array $selectedIds = [];
    public bool $selectAllFiltered = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Party::class);
    }

    public function updatedFilterStatus(): void
    {
        $this->resetPage();
    }

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

    public function updatedSelectedIds(): void
    {
        $this->selectAllFiltered = false;
    }

    // Quick row status actions

    public function approveSupplier(string $id): void
    {
        $party = Party::findOrFail($id);
        $this->authorize('update', $party);
        $party->update(['status' => 'active']);
    }

    public function deactivateSupplier(string $id): void
    {
        $party = Party::findOrFail($id);
        $this->authorize('update', $party);
        $party->update(['status' => 'inactive']);
    }

    // Bulk selection

    public function toggleSelectPage(array $pageIds): void
    {
        $allSelected = count($pageIds) > 0
            && count(array_intersect($this->selectedIds, $pageIds)) === count($pageIds);

        $this->selectedIds = $allSelected
            ? array_values(array_diff($this->selectedIds, $pageIds))
            : array_values(array_unique(array_merge($this->selectedIds, $pageIds)));

        $this->selectAllFiltered = false;
    }

    public function markSelectAllFiltered(): void
    {
        $this->selectAllFiltered = true;
    }

    public function clearSelection(): void
    {
        $this->selectedIds = [];
        $this->selectAllFiltered = false;
    }

    public function bulkApprove(): void
    {
        $this->authorize('update', new Party);
        $ids = $this->resolveTargetIds();
        Party::whereIn('id', $ids)->whereIn('status', ['pending', 'inactive'])->update(['status' => 'active']);
        $this->clearSelection();
    }

    public function bulkDeactivate(): void
    {
        $this->authorize('update', new Party);
        $ids = $this->resolveTargetIds();
        Party::whereIn('id', $ids)->whereIn('status', ['active', 'pending'])->update(['status' => 'inactive']);
        $this->clearSelection();
    }

    private function resolveTargetIds(): array
    {
        if ($this->selectAllFiltered) {
            return $this->filteredBaseQuery()->pluck('parties.id')->all();
        }

        return $this->selectedIds;
    }

    // Add/Edit — delegates to the shared suppliers.form-modal component.

    public function create(): void
    {
        $this->dispatch('open-supplier-form');
    }

    public function edit(string $id): void
    {
        $this->dispatch('open-supplier-form', partyId: $id);
    }

    public function delete(string $id): void
    {
        $party = Party::findOrFail($id);
        $this->authorize('delete', $party);
        $party->delete();
    }

    #[On('supplier-saved')]
    public function refreshAfterSupplierSaved(): void
    {
        // No-op: with() below re-queries on every render, this just forces one.
    }

    private function filteredBaseQuery(): Builder
    {
        return Party::suppliers()
            ->join('businesses', 'businesses.id', '=', 'parties.id')
            ->select('parties.*')
            ->when(
                $this->search,
                fn ($q) => $q->where(function ($q): void {
                    $q->where('businesses.legal_name', 'like', "%{$this->search}%")
                        ->orWhere('businesses.trading_name', 'like', "%{$this->search}%")
                        ->orWhere('parties.primary_email', 'like', "%{$this->search}%");
                })
            )
            ->when($this->filterStatus, fn ($q) => $q->where('parties.status', $this->filterStatus));
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
            'status' => 'parties.status',
            'primary_email' => 'parties.primary_email',
            'total_owing' => 'total_owing',
            'total_spent' => 'total_spent',
            default => 'businesses.legal_name',
        };

        [$dateStart, $dateEnd] = $this->dateRangeBounds();

        $totalSpentSub = Document::purchaseInvoices()
            ->selectRaw('COALESCE(SUM(total), 0)')
            ->whereColumn('party_id', 'parties.id')
            ->whereBetween('issue_date', [$dateStart->toDateString(), $dateEnd->toDateString()]);

        $totalOwingSub = Document::purchaseInvoices()
            ->selectRaw('COALESCE(SUM(balance_due), 0)')
            ->whereColumn('party_id', 'parties.id')
            ->unpaid();

        $rows = $this->filteredBaseQuery()
            ->selectSub($totalSpentSub, 'total_spent')
            ->selectSub($totalOwingSub, 'total_owing')
            ->with('business')
            ->orderBy($sortColumn, $this->sortDir)
            ->paginate($this->perPage);

        $pageIds = collect($rows->items())->pluck('id')->all();
        $totalFiltered = $rows->total();
        $allPageSelected = count($pageIds) > 0
            && count(array_intersect($this->selectedIds, $pageIds)) === count($pageIds);

        return [
            'rows' => $rows,
            'pageIds' => $pageIds,
            'totalFiltered' => $totalFiltered,
            'allPageSelected' => $allPageSelected,
            'selectedCount' => $this->selectAllFiltered ? $totalFiltered : count($this->selectedIds),
        ];
    }
}; ?>

<div>
<x-crud.table title="Suppliers" description="Your supplier businesses">
    <x-slot name="actions">
        @can('create', \App\Modules\Core\Models\Party::class)
            <flux:button wire:click="create" icon="plus" size="sm" variant="primary">
                New Supplier
            </flux:button>
        @endcan
    </x-slot>

    <x-slot name="filters">
        {{-- Status filter + date range --}}
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-6 py-3">
            <div class="flex gap-1">
                @foreach(['All' => '', 'Active' => 'active', 'Pending' => 'pending', 'Inactive' => 'inactive'] as $label => $value)
                    <button
                        wire:click="$set('filterStatus', '{{ $value }}')"
                        @class([
                            'px-3 py-1.5 text-sm rounded-md font-medium transition-colors',
                            'bg-white text-ink shadow-sm ring-1 ring-inset ring-line' => $filterStatus === $value,
                            'text-ink-soft hover:text-ink' => $filterStatus !== $value,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>

            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="text-ink-muted">Total spent:</span>
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

        {{-- Bulk action bar --}}
        @if($selectedCount > 0 || $selectAllFiltered)
            <div class="flex items-center gap-3 border-b border-line bg-blue-50 px-6 py-2 text-sm">
                <span class="font-medium text-ink">
                    @if($selectAllFiltered)
                        All {{ $totalFiltered }} suppliers selected
                    @else
                        {{ $selectedCount }} selected
                    @endif
                </span>

                @if(! $selectAllFiltered && $selectedCount < $totalFiltered)
                    <button wire:click="markSelectAllFiltered" class="text-accent-600 hover:underline">
                        Select all {{ $totalFiltered }} matching
                    </button>
                @endif

                @can('update', new \App\Modules\Core\Models\Party)
                    <flux:button wire:click="bulkApprove" size="sm" variant="ghost" icon="check">Activate</flux:button>
                    <flux:button wire:click="bulkDeactivate" size="sm" variant="ghost" icon="x-mark">Deactivate</flux:button>
                @endcan

                <button wire:click="clearSelection" class="ml-auto text-ink-muted hover:text-ink">Clear</button>
            </div>
        @endif
    </x-slot>

    <table class="w-full text-sm">
        <thead>
            <tr>
                <th class="w-10 px-4 py-3">
                    <input
                        type="checkbox"
                        wire:click="toggleSelectPage({{ json_encode($pageIds) }})"
                        @checked($selectAllFiltered || $allPageSelected)
                        class="rounded border-line"
                        @if($selectAllFiltered) disabled @endif
                    />
                </th>
                <x-crud.th column="name" :sort-by="$sortBy" :sort-dir="$sortDir">Name</x-crud.th>
                <x-crud.th column="primary_email" :sort-by="$sortBy" :sort-dir="$sortDir">Email</x-crud.th>
                <x-crud.th>Phone</x-crud.th>
                <x-crud.th column="status" :sort-by="$sortBy" :sort-dir="$sortDir">Status</x-crud.th>
                <x-crud.th column="total_owing" :sort-by="$sortBy" :sort-dir="$sortDir" :right="true">Owing</x-crud.th>
                <x-crud.th column="total_spent" :sort-by="$sortBy" :sort-dir="$sortDir" :right="true">Spent</x-crud.th>
                <x-crud.th></x-crud.th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $party)
                <tr class="border-t border-line hover:bg-surface-alt group">
                    <td class="px-4 py-3">
                        @if($selectAllFiltered)
                            <input type="checkbox" checked disabled class="rounded border-line opacity-60" />
                        @else
                            <input
                                type="checkbox"
                                wire:model.live="selectedIds"
                                value="{{ $party->id }}"
                                class="rounded border-line"
                            />
                        @endif
                    </td>
                    <td class="px-4 py-3 font-medium text-ink">
                        <a href="{{ route('suppliers.show', $party->id) }}" wire:navigate class="hover:text-accent hover:underline">
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
                        @if((float) $party->total_spent > 0)
                            {{ number_format((float) $party->total_spent, 2) }}
                        @else
                            <span class="text-ink-muted">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                            @if(in_array($party->status, ['pending', 'inactive']))
                                @can('update', $party)
                                    <flux:button wire:click="approveSupplier('{{ $party->id }}')" size="sm" variant="ghost" icon="check">Activate</flux:button>
                                @endcan
                            @endif
                            @if(in_array($party->status, ['active', 'pending']))
                                @can('update', $party)
                                    <flux:button wire:click="deactivateSupplier('{{ $party->id }}')" size="sm" variant="ghost" icon="x-mark">Deactivate</flux:button>
                                @endcan
                            @endif
                            @can('view', $party)
                                <flux:button href="{{ route('suppliers.show', $party->id) }}" wire:navigate size="sm" variant="ghost" icon="eye" />
                            @endcan
                            @can('update', $party)
                                <flux:button wire:click="edit('{{ $party->id }}')" size="sm" variant="ghost" icon="pencil" />
                            @endcan
                            @can('delete', $party)
                                <flux:button
                                    wire:click="delete('{{ $party->id }}')"
                                    wire:confirm="Delete this supplier? This cannot be undone."
                                    size="sm" variant="ghost" icon="trash"
                                    class="text-danger hover:text-danger"
                                />
                            @endcan
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="px-4 py-12 text-center">
                        <p class="font-medium text-ink">No suppliers yet.</p>
                        <p class="mt-1 text-sm text-ink-muted">Add your first supplier to get started.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <x-slot name="pagination">
        {{ $rows->links() }}
    </x-slot>
</x-crud.table>

<livewire:suppliers.form-modal />
</div>
