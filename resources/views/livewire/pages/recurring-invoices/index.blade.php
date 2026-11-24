<?php

use App\Livewire\Concerns\HasCrudForm;
use App\Livewire\Concerns\HasCrudTable;
use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Enums\RecurringFrequency;
use App\Modules\Billing\Enums\RecurringInvoiceStatus;
use App\Modules\Core\Models\PaymentTerm;
use App\Modules\Billing\Models\RecurringInvoice;
use App\Modules\Billing\Services\RecurringInvoiceService;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\Document;
use App\Modules\Purchasing\Services\ExchangeRateService;
use App\Modules\Core\Settings\CurrencySettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('components.layout.app')] class extends Component
{
    use HasCrudTable, HasCrudForm;

    #[Validate('required|uuid|exists:parties,id')]
    public string $clientId = '';

    #[Validate('required|string')]
    public string $frequency = 'monthly';

    #[Validate('required|integer|min:1|max:28')]
    public int $billingPeriodDay = 1;

    #[Validate('required|date')]
    public string $startDate = '';

    #[Validate('nullable|date|after_or_equal:startDate')]
    public string $endDate = '';

    /** Edit only: new templates derive it from the start date. Validated in update(). */
    public string $nextInvoiceDate = '';

    #[Validate('nullable|uuid|exists:payment_terms,id')]
    public string $paymentTermId = '';

    #[Validate('nullable|string|max:2000')]
    public string $notes = '';

    #[Validate('boolean')]
    public bool $autoSend = true;

    /** @var array<int, array<string, mixed>> */
    public array $formLines = [];

    public string $filterFrequency = '';

    public string $filterStatus = '';

    public bool $filterThisMonth = false;

    /** @var array<int, string> */
    public array $selectedIds = [];

    public bool $selectAllFiltered = false;

    public bool $showBulkEdit = false;

    // Bulk edit: '' means "no change" for every field.
    public string $bulkStatus = '';

    public string $bulkNextDate = '';

    /** '' = no change, 'none' = use the client's term, otherwise a payment term id. */
    public string $bulkPaymentTermId = '';

    /** '' = no change, '1' = on, '0' = off. */
    public string $bulkAutoSend = '';

    public string $activeTab = 'details';

    public function mount(): void
    {
        $this->authorize('viewAny', RecurringInvoice::class);
        $this->sortBy = session('ri.sortBy', 'next_invoice_date');
        $this->sortDir = session('ri.sortDir', 'desc');
        $this->filterFrequency = session('ri.filterFrequency', '');
        $this->filterStatus = session('ri.filterStatus', '');
        $this->filterThisMonth = (bool) session('ri.filterThisMonth', false);
        $this->search = session('ri.search', '');
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }

        session(['ri.sortBy' => $this->sortBy, 'ri.sortDir' => $this->sortDir]);
        $this->resetPage();
    }

    // Changing what is listed clears the selection, so "all matching" can never
    // silently mean a different set than the one the user selected.

    public function updatedSearch(): void
    {
        session(['ri.search' => $this->search]);
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedFilterFrequency(): void
    {
        session(['ri.filterFrequency' => $this->filterFrequency]);
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedFilterStatus(): void
    {
        session(['ri.filterStatus' => $this->filterStatus]);
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedFilterThisMonth(): void
    {
        session(['ri.filterThisMonth' => $this->filterThisMonth]);
        $this->resetPage();
        $this->clearSelection();
    }

    public function updatedSelectedIds(): void
    {
        $this->selectAllFiltered = false;
    }

    // -------------------------------------------------------------------------
    // Bulk selection and bulk edit
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, string>  $pageIds
     */
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

    public function openBulkEdit(): void
    {
        $this->authorize('update', new RecurringInvoice);

        if (! $this->selectAllFiltered && $this->selectedIds === []) {
            return;
        }

        $this->reset(['bulkStatus', 'bulkNextDate', 'bulkPaymentTermId', 'bulkAutoSend']);
        $this->resetErrorBag();
        $this->showBulkEdit = true;
    }

    public function applyBulkEdit(): void
    {
        $this->authorize('update', new RecurringInvoice);

        $this->validate([
            'bulkStatus' => 'nullable|in:active,paused',
            'bulkNextDate' => 'nullable|date',
            'bulkPaymentTermId' => ['nullable', Rule::when(
                $this->bulkPaymentTermId !== 'none',
                ['uuid', 'exists:payment_terms,id'],
            )],
            'bulkAutoSend' => 'nullable|in:0,1',
        ]);

        $changes = [];

        if ($this->bulkStatus !== '') {
            $changes['status'] = RecurringInvoiceStatus::from($this->bulkStatus);
        }

        if ($this->bulkPaymentTermId !== '') {
            $changes['payment_term_id'] = $this->bulkPaymentTermId === 'none' ? null : $this->bulkPaymentTermId;
        }

        if ($this->bulkAutoSend !== '') {
            $changes['auto_send'] = $this->bulkAutoSend === '1';
        }

        // Runs advance from next_period_anchor, so a chosen date must move it too
        // (same rule as editing a single template).
        if ($this->bulkNextDate !== '') {
            $changes['next_invoice_date'] = $this->bulkNextDate;
            $changes['next_period_anchor'] = $this->bulkNextDate;
        }

        if ($changes === []) {
            $this->addError('bulkEdit', 'Choose at least one change.');

            return;
        }

        $templates = RecurringInvoice::whereIn('id', $this->resolveTargetIds())->get();

        foreach ($templates as $template) {
            $this->authorize('update', $template);
        }

        // One model update per template so each change lands in the activity log.
        DB::transaction(function () use ($templates, $changes): void {
            foreach ($templates as $template) {
                $template->update($changes);
            }
        });

        session()->flash('success', "Updated {$templates->count()} recurring invoice".($templates->count() === 1 ? '' : 's').'.');

        $this->showBulkEdit = false;
        $this->clearSelection();
    }

    /**
     * @return array<int, string>
     */
    private function resolveTargetIds(): array
    {
        if ($this->selectAllFiltered) {
            return $this->filteredQuery()->pluck('recurring_invoices.id')->all();
        }

        // Ids come from the browser: keep only well-formed ones; each template is
        // still authorized individually before it is changed.
        return array_values(array_filter($this->selectedIds, fn ($id) => Str::isUuid($id)));
    }

    /**
     * The filtered set shown in the table, without relations, sorting or paging.
     *
     * @return \Illuminate\Database\Eloquent\Builder<RecurringInvoice>
     */
    private function filteredQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return RecurringInvoice::query()
            ->when($this->search, fn ($q) => $q->whereHas('client.business', fn ($b) => $b
                ->where('trading_name', 'like', "%{$this->search}%")
                ->orWhere('legal_name', 'like', "%{$this->search}%")
            ))
            ->when($this->filterFrequency, fn ($q) => $q->where('frequency', $this->filterFrequency))
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterThisMonth, fn ($q) => $q->generatingThisMonth());
    }

    public function create(): void
    {
        $this->authorize('create', RecurringInvoice::class);
        $this->reset(['clientId', 'paymentTermId', 'notes', 'endDate', 'nextInvoiceDate', 'formLines']);
        $this->autoSend = true;
        $this->frequency = 'monthly';
        $this->billingPeriodDay = 1;
        $this->startDate = now()->toDateString();
        $this->activeTab = 'details';
        $this->editingId = null;
        $this->showForm = true;
    }

    protected function fillForm(string $id): void
    {
        $template = RecurringInvoice::with('lines')->findOrFail($id);
        $this->authorize('update', $template);

        $this->clientId = $template->client_id;
        $this->frequency = $template->frequency->value;
        $this->billingPeriodDay = $template->billing_period_day;
        $this->startDate = $template->start_date->toDateString();
        $this->endDate = $template->end_date?->toDateString() ?? '';
        $this->nextInvoiceDate = $template->next_invoice_date->toDateString();
        $this->paymentTermId = $template->payment_term_id ?? '';
        $this->notes = $template->notes ?? '';
        $this->autoSend = $template->auto_send;
        $this->activeTab = 'details';

        $this->formLines = $template->lines->map(fn ($line) => [
            'description' => $line->description,
            'account_id' => $line->account_id ?? '',
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $line->unit_price,
            'discount_percent' => (string) $line->discount_percent,
            'tax_rate' => (string) ($line->tax_rate ?? ''),
        ])->all();
    }

    protected function store(): void
    {
        $this->authorize('create', RecurringInvoice::class);
        $this->validate();

        app(RecurringInvoiceService::class)->createTemplate([
            'client_id' => $this->clientId,
            'frequency' => $this->frequency,
            'billing_period_day' => $this->billingPeriodDay,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate ?: null,
            'payment_term_id' => $this->paymentTermId ?: null,
            'notes' => $this->notes ?: null,
            'auto_send' => $this->autoSend,
            'lines' => $this->formLines,
        ]);
    }

    protected function update(): void
    {
        $this->validate();
        $this->validate(['nextInvoiceDate' => 'required|date']);

        $template = RecurringInvoice::findOrFail($this->editingId);
        $this->authorize('update', $template);

        // Runs advance from next_period_anchor, so a manually chosen date must move the anchor too.
        if ($this->nextInvoiceDate !== $template->next_invoice_date->toDateString()) {
            $template->update([
                'next_invoice_date' => $this->nextInvoiceDate,
                'next_period_anchor' => $this->nextInvoiceDate,
            ]);
        }

        $template->update([
            'client_id' => $this->clientId,
            'frequency' => $this->frequency,
            'billing_period_day' => $this->billingPeriodDay,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate ?: null,
            'payment_term_id' => $this->paymentTermId ?: null,
            'notes' => $this->notes ?: null,
            'auto_send' => $this->autoSend,
        ]);

        // Sync lines: replace all
        $template->lines()->delete();
        foreach ($this->formLines as $i => $line) {
            $template->lines()->create([
                'line_number' => $i + 1,
                'description' => $line['description'],
                'account_id' => $line['account_id'] ?: null,
                'quantity' => $line['quantity'] ?? 1,
                'unit_price' => $line['unit_price'] ?? 0,
                'discount_percent' => $line['discount_percent'] ?? 0,
                'tax_rate' => $line['tax_rate'] !== '' ? $line['tax_rate'] : null,
            ]);
        }
    }

    public function activate(string $id): void
    {
        $template = RecurringInvoice::findOrFail($id);
        $this->authorize('update', $template);
        $template->update(['status' => RecurringInvoiceStatus::Active]);
    }

    public function pause(string $id): void
    {
        $template = RecurringInvoice::findOrFail($id);
        $this->authorize('update', $template);
        $template->update(['status' => RecurringInvoiceStatus::Paused]);
    }

    protected function performDelete(string $id): void
    {
        $template = RecurringInvoice::findOrFail($id);
        $this->authorize('delete', $template);
        $template->lines()->delete();
        $template->delete();
    }

    // -------------------------------------------------------------------------
    // Line management
    // -------------------------------------------------------------------------

    public function addFormLine(): void
    {
        $this->formLines[] = [
            'description' => '',
            'account_id' => '',
            'quantity' => '1',
            'unit_price' => '',
            'discount_percent' => '0',
            'tax_rate' => '15',
        ];
    }

    public function removeFormLine(int $index): void
    {
        array_splice($this->formLines, $index, 1);
        $this->formLines = array_values($this->formLines);
    }

    public function with(): array
    {
        $historyInvoices = collect();

        if ($this->showForm && $this->editingId) {
            $historyInvoices = Document::salesInvoices()
                ->where('metadata->recurring_invoice_id', $this->editingId)
                ->with('party.business')
                ->latest('issue_date')
                ->get();
        }

        $rows = $this->filteredQuery()
            ->with(['client.business', 'lines'])
            ->when(
                $this->sortBy === 'client',
                fn ($q) => $q
                    ->join('businesses', 'businesses.id', '=', 'recurring_invoices.client_id')
                    ->orderBy('businesses.trading_name', $this->sortDir)
                    ->select('recurring_invoices.*'),
                fn ($q) => $q->orderBy($this->sortBy ?: 'next_invoice_date', $this->sortDir)
            )
            ->paginate($this->perPage);

        $pageIds = collect($rows->items())->pluck('id')->all();
        $allPageSelected = count($pageIds) > 0
            && count(array_intersect($this->selectedIds, $pageIds)) === count($pageIds);

        return [
            'rows' => $rows,
            'pageIds' => $pageIds,
            'totalFiltered' => $rows->total(),
            'allPageSelected' => $allPageSelected,
            'selectedCount' => $this->selectAllFiltered ? $rows->total() : count($this->selectedIds),
            // Client dropdown only renders inside the form flyout.
            'clients' => $this->showForm ? Party::clients()->with('business')->get() : collect(),
            'paymentTerms' => PaymentTerm::orderBy('name')->get(['id', 'name']),
            'accounts' => Account::postable()->income()->active()->orderBy('code')->get(['id', 'code', 'name']),
            'frequencies' => RecurringInvoice::select('frequency')->distinct()->get()->pluck('frequency')->sortBy(fn ($f) => $f->value)->values(),
            'historyInvoices' => $historyInvoices,
            'currencySymbol' => ExchangeRateService::currencySymbol(
                app(CurrencySettings::class)->base_currency
            ),
        ];
    }
}; ?>

<div>

{{-- Flash --}}
@if(session('success'))
    <div class="mx-6 mt-4 px-4 py-3 rounded-sm bg-green-50 border border-green-200 text-sm text-success">
        {{ session('success') }}
    </div>
@endif

{{-- Table --}}
<x-crud.table title="Recurring Invoices" description="Automated billing templates that generate invoices on a schedule.">
    <x-slot name="actions">
        @can('create', \App\Modules\Billing\Models\RecurringInvoice::class)
            <flux:button wire:click="create" variant="primary" icon="plus" size="sm">New Template</flux:button>
        @endcan
    </x-slot>

    <x-slot name="search">
        <div class="flex items-center gap-3 border-b border-line bg-surface-alt px-6 py-3">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search clients…" icon="magnifying-glass" size="sm" class="max-w-xs" />
            <div class="flex gap-1">
                @foreach(['' => 'All', 'active' => 'Active', 'paused' => 'Paused', 'completed' => 'Completed'] as $value => $label)
                    <button
                        wire:click="$set('filterStatus', '{{ $value }}')"
                        @class([
                            'px-3 py-1.5 text-sm rounded-md font-medium transition-colors',
                            'bg-white text-ink shadow-xs ring-1 ring-inset ring-line' => $filterStatus === $value,
                            'text-ink-soft hover:text-ink' => $filterStatus !== $value,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>
            <button
                type="button"
                wire:click="$toggle('filterThisMonth')"
                title="Active templates whose next invoice date is on or before the end of this month (overdue ones included)"
                @class([
                    'px-3 py-1.5 text-sm rounded-md font-medium transition-colors',
                    'bg-white text-ink shadow-xs ring-1 ring-inset ring-line' => $filterThisMonth,
                    'text-ink-soft hover:text-ink' => ! $filterThisMonth,
                ])
            >Generating this month</button>
            <div class="flex-none">
                <flux:select wire:model.live="filterFrequency" size="sm">
                    <option value="">Frequency</option>
                    @foreach($frequencies as $freq)
                        <option value="{{ $freq->value }}">{{ $freq->label() }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        {{-- Bulk action bar --}}
        @if($selectedCount > 0 || $selectAllFiltered)
            <div class="flex items-center gap-3 border-b border-line bg-blue-50 px-6 py-2 text-sm">
                <span class="font-medium text-ink">
                    @if($selectAllFiltered)
                        All {{ $totalFiltered }} {{ \Illuminate\Support\Str::plural('template', $totalFiltered) }} selected
                    @else
                        {{ $selectedCount }} selected
                    @endif
                </span>

                @if(! $selectAllFiltered && $selectedCount < $totalFiltered)
                    <button wire:click="markSelectAllFiltered" class="text-accent-600 hover:underline">
                        Select all {{ $totalFiltered }} matching
                    </button>
                @endif

                @can('update', new \App\Modules\Billing\Models\RecurringInvoice)
                    <flux:button wire:click="openBulkEdit" size="sm" variant="ghost" icon="pencil-square">Bulk edit</flux:button>
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
                        class="rounded-sm border-line"
                        @if($selectAllFiltered) disabled @endif
                    />
                </th>
                <x-crud.th column="client" :sort-by="$sortBy" :sort-dir="$sortDir">Client</x-crud.th>
                <x-crud.th :right="true">Amount (ex tax)</x-crud.th>
                <x-crud.th column="frequency" :sort-by="$sortBy" :sort-dir="$sortDir">Frequency</x-crud.th>
                <x-crud.th column="next_invoice_date" :sort-by="$sortBy" :sort-dir="$sortDir">Next Date</x-crud.th>
                <x-crud.th column="status" :sort-by="$sortBy" :sort-dir="$sortDir">Status</x-crud.th>
                <th class="px-4 py-3 text-right text-xs font-semibold text-ink-muted uppercase tracking-wide"></th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $template)
                @php
                    $templateValue = $template->lines->sum(
                        fn ($l) => $l->quantity * $l->unit_price * (1 - $l->discount_percent / 100)
                    );
                @endphp
                <tr class="group border-t border-line hover:bg-surface-alt transition-colors">
                    <td class="px-4 py-3">
                        @if($selectAllFiltered)
                            <input type="checkbox" checked disabled class="rounded-sm border-line opacity-60" />
                        @else
                            <input
                                type="checkbox"
                                wire:model.live="selectedIds"
                                value="{{ $template->id }}"
                                class="rounded-sm border-line"
                            />
                        @endif
                    </td>
                    <td class="px-4 py-3 font-medium text-ink">
                        {{ $template->client?->business?->display_name ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums text-ink-soft">
                        {{ $currencySymbol }}{{ number_format($templateValue, 2) }}
                    </td>
                    <td class="px-4 py-3 text-ink-soft">
                        {{ $template->frequency->label() }}
                    </td>
                    <td class="px-4 py-3 tabular-nums text-ink-soft">
                        {{ $template->next_invoice_date->format('d M Y') }}
                    </td>
                    <td class="px-4 py-3">
                        <span @class([
                            'inline-flex items-center px-2 py-0.5 rounded-sm text-xs font-medium',
                            'bg-green-50 text-success' => $template->status === \App\Modules\Billing\Enums\RecurringInvoiceStatus::Active,
                            'bg-yellow-50 text-warning' => $template->status === \App\Modules\Billing\Enums\RecurringInvoiceStatus::Paused,
                            'bg-surface-alt text-ink-muted' => $template->status === \App\Modules\Billing\Enums\RecurringInvoiceStatus::Completed,
                        ])>
                            {{ $template->status->label() }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-2">
                            @can('update', $template)
                                @if($template->status === \App\Modules\Billing\Enums\RecurringInvoiceStatus::Active)
                                    <flux:button wire:click="pause('{{ $template->id }}')" size="sm" variant="ghost">Pause</flux:button>
                                @elseif($template->status === \App\Modules\Billing\Enums\RecurringInvoiceStatus::Paused)
                                    <flux:button wire:click="activate('{{ $template->id }}')" size="sm" variant="ghost">Activate</flux:button>
                                @endif
                                <flux:button wire:click="edit('{{ $template->id }}')" size="sm" variant="ghost" icon="pencil" />
                            @endcan
                            @can('delete', $template)
                                <flux:button
                                    wire:click="delete('{{ $template->id }}')"
                                    wire:confirm="Delete this recurring invoice template? This cannot be undone."
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
                        <p class="font-medium text-ink">No recurring invoice templates yet.</p>
                        <p class="mt-1 text-sm text-ink-muted">Create a template to automate invoice generation.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <x-slot name="pagination">
        {{ $rows->links() }}
    </x-slot>
</x-crud.table>

{{-- ===== Bulk edit ===== --}}
<flux:modal name="bulk-edit" wire:model.self="showBulkEdit" class="w-[480px]">
    <form wire:submit="applyBulkEdit" class="p-6 space-y-5">
        <div>
            <flux:heading size="lg" class="font-semibold">
                Bulk edit {{ $selectedCount }} {{ \Illuminate\Support\Str::plural('template', $selectedCount) }}
            </flux:heading>
            <p class="mt-1 text-sm text-ink-muted">Only the fields you change are updated; everything else stays as it is.</p>
        </div>

        <flux:field>
            <flux:label>Status</flux:label>
            <flux:select wire:model="bulkStatus">
                <option value="">— No change —</option>
                <option value="active">Active</option>
                <option value="paused">Paused</option>
            </flux:select>
            <flux:error name="bulkStatus" />
        </flux:field>

        <flux:field>
            <flux:label>Next invoice date</flux:label>
            <flux:input type="date" wire:model="bulkNextDate" />
            <flux:description>Leave blank to keep each template's own date. Following invoices repeat from the date you choose.</flux:description>
            <flux:error name="bulkNextDate" />
        </flux:field>

        <flux:field>
            <flux:label>Payment terms</flux:label>
            <flux:select wire:model="bulkPaymentTermId">
                <option value="">— No change —</option>
                <option value="none">— Use client default —</option>
                @foreach($paymentTerms as $term)
                    <option value="{{ $term->id }}">{{ $term->name }}</option>
                @endforeach
            </flux:select>
            <flux:error name="bulkPaymentTermId" />
        </flux:field>

        <flux:field>
            <flux:label>Send automatically</flux:label>
            <flux:select wire:model="bulkAutoSend">
                <option value="">— No change —</option>
                <option value="1">On</option>
                <option value="0">Off</option>
            </flux:select>
            <flux:error name="bulkAutoSend" />
        </flux:field>

        <flux:error name="bulkEdit" />

        <div class="flex justify-end gap-3 pt-2">
            <flux:button type="button" variant="ghost" wire:click="$set('showBulkEdit', false)">Cancel</flux:button>
            <flux:button type="submit" variant="primary">Apply to {{ $selectedCount }} {{ \Illuminate\Support\Str::plural('template', $selectedCount) }}</flux:button>
        </div>
    </form>
</flux:modal>

{{-- ===== Form Flyout ===== --}}
<flux:modal name="crud-form" flyout wire:model.self="showForm" class="w-[640px]">
    <form wire:submit="save" class="flex flex-col h-full">
        <div class="p-6 border-b border-line">
            <flux:heading size="lg" class="font-semibold">
                {{ $editingId ? 'Edit Recurring Invoice' : 'New Recurring Invoice' }}
            </flux:heading>
        </div>

        {{-- Tabs (show history tab only when editing) --}}
        @if($editingId)
        <div class="flex border-b border-line">
            <button
                type="button"
                wire:click="$set('activeTab', 'details')"
                @class([
                    'px-5 py-3 text-sm font-medium border-b-2 -mb-px transition-colors',
                    'border-primary text-primary' => $activeTab === 'details',
                    'border-transparent text-ink-muted hover:text-ink' => $activeTab !== 'details',
                ])
            >Details</button>
            <button
                type="button"
                wire:click="$set('activeTab', 'history')"
                @class([
                    'px-5 py-3 text-sm font-medium border-b-2 -mb-px transition-colors',
                    'border-primary text-primary' => $activeTab === 'history',
                    'border-transparent text-ink-muted hover:text-ink' => $activeTab !== 'history',
                ])
            >History ({{ $historyInvoices->count() }})</button>
        </div>
        @endif

        <div class="flex-1 overflow-y-auto">

            {{-- Details Tab --}}
            @if($activeTab === 'details')
            <div class="p-6 space-y-4">

                <div class="grid grid-cols-2 gap-4">
                    <flux:field class="col-span-2">
                        <flux:label>Client <span class="text-danger">*</span></flux:label>
                        <flux:select wire:model="clientId">
                            <option value="">— Select client —</option>
                            @foreach($clients as $client)
                                <option value="{{ $client->id }}">{{ $client->business?->display_name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="clientId" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Frequency <span class="text-danger">*</span></flux:label>
                        <flux:select wire:model="frequency">
                            @foreach($frequencies as $freq)
                                <option value="{{ $freq->value }}">{{ $freq->label() }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="frequency" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Billing Period Day <span class="text-danger">*</span></flux:label>
                        <flux:input type="number" wire:model="billingPeriodDay" min="1" max="28" />
                        <flux:description>Day of month when the billing period starts (1–28)</flux:description>
                        <flux:error name="billingPeriodDay" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Start Date <span class="text-danger">*</span></flux:label>
                        <flux:input type="date" wire:model="startDate" />
                        <flux:error name="startDate" />
                    </flux:field>

                    <flux:field>
                        <flux:label>End Date</flux:label>
                        <flux:input type="date" wire:model="endDate" />
                        <flux:description>Leave blank for indefinite</flux:description>
                        <flux:error name="endDate" />
                    </flux:field>

                    @if($editingId)
                        <flux:field>
                            <flux:label>Next Invoice Date <span class="text-danger">*</span></flux:label>
                            <flux:input type="date" wire:model="nextInvoiceDate" />
                            <flux:description>Following invoices repeat from this date</flux:description>
                            <flux:error name="nextInvoiceDate" />
                        </flux:field>
                    @endif

                    <flux:field class="col-span-2">
                        <flux:label>Payment Terms</flux:label>
                        <flux:select wire:model="paymentTermId">
                            <option value="">— Use client default —</option>
                            @foreach($paymentTerms as $term)
                                <option value="{{ $term->id }}">{{ $term->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="paymentTermId" />
                    </flux:field>

                    <flux:field class="col-span-2">
                        <flux:label>Notes</flux:label>
                        <flux:textarea wire:model="notes" rows="2" placeholder="Internal notes…" />
                        <flux:error name="notes" />
                    </flux:field>

                    <flux:field class="col-span-2" variant="inline">
                        <flux:checkbox wire:model="autoSend" />
                        <flux:label>Email generated invoices to the client automatically</flux:label>
                        <flux:error name="autoSend" />
                    </flux:field>
                </div>

                {{-- Lines section --}}
                <div class="pt-2">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-xs font-semibold text-ink-muted uppercase tracking-wide">Line Items</p>
                        <flux:button type="button" wire:click="addFormLine" size="xs" variant="ghost" icon="plus">Add Line</flux:button>
                    </div>

                    @if(count($formLines) > 0)
                    <div class="border border-line rounded-sm overflow-hidden">
                        <table class="w-full text-xs">
                            <thead>
                                <tr class="bg-surface-alt border-b border-line">
                                    <th class="px-3 py-2 text-left text-ink-muted font-semibold uppercase tracking-wide">Description</th>
                                    <th class="px-3 py-2 text-left text-ink-muted font-semibold uppercase tracking-wide w-36">Account</th>
                                    <th class="px-3 py-2 text-right text-ink-muted font-semibold uppercase tracking-wide w-16">Qty</th>
                                    <th class="px-3 py-2 text-right text-ink-muted font-semibold uppercase tracking-wide w-24">Price</th>
                                    <th class="px-3 py-2 text-right text-ink-muted font-semibold uppercase tracking-wide w-16">Tax %</th>
                                    <th class="px-2 py-2 w-8"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($formLines as $i => $line)
                                <tr class="border-b border-line last:border-b-0">
                                    <td class="px-2 py-1.5">
                                        <input
                                            type="text"
                                            wire:model="formLines.{{ $i }}.description"
                                            placeholder="Description"
                                            class="w-full border border-line rounded-sm px-2 py-1 text-xs focus:outline-hidden focus:ring-1 focus:ring-primary"
                                        />
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <select
                                            wire:model="formLines.{{ $i }}.account_id"
                                            class="w-full border border-line rounded-sm px-2 py-1 text-xs focus:outline-hidden focus:ring-1 focus:ring-primary"
                                        >
                                            <option value="">—</option>
                                            @foreach($accounts as $account)
                                                <option value="{{ $account->id }}">{{ $account->code }} {{ $account->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <input
                                            type="number"
                                            wire:model="formLines.{{ $i }}.quantity"
                                            step="0.0001"
                                            min="0"
                                            class="w-full border border-line rounded-sm px-2 py-1 text-xs text-right focus:outline-hidden focus:ring-1 focus:ring-primary"
                                        />
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <input
                                            type="number"
                                            wire:model="formLines.{{ $i }}.unit_price"
                                            step="0.01"
                                            min="0"
                                            placeholder="0.00"
                                            class="w-full border border-line rounded-sm px-2 py-1 text-xs text-right focus:outline-hidden focus:ring-1 focus:ring-primary"
                                        />
                                    </td>
                                    <td class="px-2 py-1.5">
                                        <input
                                            type="number"
                                            wire:model="formLines.{{ $i }}.tax_rate"
                                            step="0.01"
                                            min="0"
                                            max="100"
                                            placeholder="15"
                                            class="w-full border border-line rounded-sm px-2 py-1 text-xs text-right focus:outline-hidden focus:ring-1 focus:ring-primary"
                                        />
                                    </td>
                                    <td class="px-2 py-1.5 text-center">
                                        <button
                                            type="button"
                                            wire:click="removeFormLine({{ $i }})"
                                            class="text-ink-muted hover:text-danger transition-colors"
                                        >
                                            <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                                        </button>
                                    </td>
                                </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @else
                    <div class="text-center py-4 text-xs text-ink-muted border border-dashed border-line rounded-sm">
                        No lines added. Click "Add Line" to add invoice lines.
                    </div>
                    @endif
                </div>

            </div>
            @endif

            {{-- History Tab --}}
            @if($activeTab === 'history')
            <div class="p-6">
                @if($historyInvoices->isEmpty())
                    <p class="text-sm text-ink-muted text-center py-8">No invoices generated from this template yet.</p>
                @else
                <div class="border border-line rounded-sm overflow-hidden">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-surface-alt border-b border-line">
                                <th class="px-4 py-3 text-left text-xs font-semibold text-ink-muted uppercase tracking-wide">Invoice #</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-ink-muted uppercase tracking-wide">Date</th>
                                <th class="px-4 py-3 text-right text-xs font-semibold text-ink-muted uppercase tracking-wide">Total</th>
                                <th class="px-4 py-3 text-left text-xs font-semibold text-ink-muted uppercase tracking-wide">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($historyInvoices as $invoice)
                            <tr class="border-b border-line last:border-b-0">
                                <td class="px-4 py-3 font-medium text-ink">
                                    {{ $invoice->document_number ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-ink-soft tabular-nums">
                                    {{ $invoice->issue_date?->format('d M Y') ?? '—' }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums text-ink">
                                    {{ $currencySymbol }}{{ number_format((float)$invoice->total, 2) }}
                                </td>
                                <td class="px-4 py-3">
                                    <span @class([
                                        'inline-flex items-center px-2 py-0.5 rounded-sm text-xs font-medium',
                                        'bg-surface-alt text-ink-muted' => $invoice->status === 'draft',
                                        'bg-blue-50 text-blue-700' => $invoice->status === 'sent',
                                        'bg-purple-50 text-purple-700' => $invoice->is_fully_credited,
                                    ])>
                                        {{ $invoice->is_fully_credited ? 'Credited' : ucfirst($invoice->status) }}
                                    </span>
                                </td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @endif
            </div>
            @endif

        </div>

        <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-line bg-surface-alt">
            <flux:button type="button" variant="ghost" wire:click="cancelForm">Cancel</flux:button>
            <flux:button type="submit" variant="primary">
                {{ $editingId ? 'Save changes' : 'Create Template' }}
            </flux:button>
        </div>
    </form>
</flux:modal>

</div>
