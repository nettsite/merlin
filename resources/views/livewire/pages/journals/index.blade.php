<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentLine;
use App\Modules\Core\Services\DocumentService;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\Services\ExchangeRateService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('components.layout.app')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public bool $showCreateModal = false;

    /** @var array<string, mixed> */
    public array $createForm = [
        'issue_date' => '',
        'reference' => '',
        'notes' => '',
    ];

    public bool $showDetail = false;

    public ?string $detailId = null;

    public bool $editingHeader = false;

    /** @var array<string, mixed> */
    public array $headerForm = [];

    /** @var array<string, mixed> */
    public array $editingLine = [];

    public ?string $editingLineId = null;

    public bool $showAddLine = false;

    /** @var array<string, mixed> */
    public array $newLine = [];

    public bool $showDeleteConfirm = false;

    public function mount(): void
    {
        $this->authorize('viewAny', Document::class);
        $this->createForm['issue_date'] = now()->toDateString();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    public function openCreate(): void
    {
        $this->authorize('create', Document::class);
        $this->createForm = [
            'issue_date' => now()->toDateString(),
            'reference' => '',
            'notes' => '',
        ];
        $this->showCreateModal = true;
    }

    public function createJournal(): void
    {
        $this->authorize('create', Document::class);

        $this->validate([
            'createForm.issue_date' => 'required|date',
            'createForm.reference' => 'nullable|string|max:255',
            'createForm.notes' => 'nullable|string|max:2000',
        ]);

        $doc = Document::create([
            'document_type' => 'journal',
            'direction' => 'internal',
            'status' => 'draft',
            'issue_date' => $this->createForm['issue_date'],
            'reference' => $this->createForm['reference'] ?: null,
            'notes' => $this->createForm['notes'] ?: null,
            'currency' => app(CurrencySettings::class)->base_currency,
            'exchange_rate' => 1.0,
            'source' => 'manual',
        ]);

        $this->showCreateModal = false;
        $this->openDetail($doc->id);
    }

    // -------------------------------------------------------------------------
    // Detail
    // -------------------------------------------------------------------------

    public function openDetail(string $id): void
    {
        $this->authorize('view', Document::findOrFail($id));
        $this->detailId = $id;
        $this->editingLineId = null;
        $this->editingLine = [];
        $this->showAddLine = false;
        $this->newLine = [];
        $this->editingHeader = false;
        $this->headerForm = [];
        $this->showDetail = true;
    }

    public function closeDetail(): void
    {
        $this->showDetail = false;
        $this->detailId = null;
        $this->editingLineId = null;
        $this->editingLine = [];
        $this->showAddLine = false;
        $this->newLine = [];
        $this->editingHeader = false;
        $this->headerForm = [];
    }

    // -------------------------------------------------------------------------
    // Header editing
    // -------------------------------------------------------------------------

    public function openEditHeader(): void
    {
        $doc = Document::findOrFail($this->detailId);
        $this->authorize('update', $doc);

        $this->headerForm = [
            'reference' => $doc->reference ?? '',
            'issue_date' => $doc->issue_date?->format('Y-m-d') ?? '',
            'notes' => $doc->notes ?? '',
        ];
        $this->editingHeader = true;
    }

    public function saveHeader(): void
    {
        $doc = Document::findOrFail($this->detailId);
        $this->authorize('update', $doc);

        $this->validate([
            'headerForm.reference' => 'nullable|string|max:255',
            'headerForm.issue_date' => 'required|date',
            'headerForm.notes' => 'nullable|string|max:2000',
        ]);

        $doc->update([
            'reference' => $this->headerForm['reference'] ?: null,
            'issue_date' => $this->headerForm['issue_date'],
            'notes' => $this->headerForm['notes'] ?: null,
        ]);

        $this->editingHeader = false;
        $this->headerForm = [];
    }

    public function cancelEditHeader(): void
    {
        $this->editingHeader = false;
        $this->headerForm = [];
    }

    // -------------------------------------------------------------------------
    // Line editing — debit/credit columns, combined into a signed unit_price
    // (positive = debit, negative = credit) on save.
    // -------------------------------------------------------------------------

    public function editLine(string $lineId): void
    {
        $line = DocumentLine::findOrFail($lineId);
        $amount = (float) $line->unit_price;
        $this->showAddLine = false;
        $this->editingLineId = $lineId;
        $this->editingLine = [
            'description' => $line->description ?? '',
            'account_id' => $line->account_id ?? '',
            'debit' => $amount > 0 ? (string) $amount : '',
            'credit' => $amount < 0 ? (string) (-$amount) : '',
        ];
    }

    public function saveLine(): void
    {
        $this->validate([
            'editingLine.description' => 'required|string|max:1000',
            'editingLine.account_id' => 'required|exists:accounts,id',
            'editingLine.debit' => 'nullable|numeric|min:0',
            'editingLine.credit' => 'nullable|numeric|min:0',
        ]);

        $line = DocumentLine::findOrFail($this->editingLineId);
        $this->authorize('update', $line->document);

        $line->update([
            'description' => $this->editingLine['description'],
            'account_id' => $this->editingLine['account_id'],
            'quantity' => 1,
            'unit_price' => $this->lineAmount($this->editingLine),
            'discount_percent' => 0,
            'discount_amount' => 0,
            'tax_rate' => null,
        ]);

        $this->editingLineId = null;
        $this->editingLine = [];
    }

    public function cancelLine(): void
    {
        $this->editingLineId = null;
        $this->editingLine = [];
    }

    public function openAddLine(): void
    {
        $doc = Document::findOrFail($this->detailId);
        $this->authorize('update', $doc);
        $this->editingLineId = null;
        $this->editingLine = [];
        $this->showAddLine = true;
        $this->newLine = [
            'description' => '',
            'account_id' => '',
            'debit' => '',
            'credit' => '',
        ];
    }

    public function saveNewLine(): void
    {
        $doc = Document::findOrFail($this->detailId);
        $this->authorize('update', $doc);

        $this->validate([
            'newLine.description' => 'required|string|max:1000',
            'newLine.account_id' => 'required|exists:accounts,id',
            'newLine.debit' => 'nullable|numeric|min:0',
            'newLine.credit' => 'nullable|numeric|min:0',
        ]);

        $nextNumber = $doc->lines()->max('line_number') + 1;

        $doc->lines()->create([
            'line_number' => $nextNumber,
            'type' => 'description',
            'description' => $this->newLine['description'],
            'account_id' => $this->newLine['account_id'],
            'quantity' => 1,
            'unit_price' => $this->lineAmount($this->newLine),
            'discount_percent' => 0,
            'discount_amount' => 0,
            'tax_rate' => null,
        ]);

        $this->showAddLine = false;
        $this->newLine = [];
    }

    public function cancelAddLine(): void
    {
        $this->showAddLine = false;
        $this->newLine = [];
    }

    public function deleteLine(string $lineId): void
    {
        $line = DocumentLine::findOrFail($lineId);
        $this->authorize('update', $line->document);
        $line->delete();
    }

    /** @param  array<string, mixed>  $line */
    private function lineAmount(array $line): float
    {
        return (float) ($line['debit'] ?: 0) - (float) ($line['credit'] ?: 0);
    }

    // -------------------------------------------------------------------------
    // Status actions
    // -------------------------------------------------------------------------

    public function post(): void
    {
        $doc = Document::findOrFail($this->detailId);
        $this->authorize('can-post-journals');
        $this->authorize('update', $doc);
        app(DocumentService::class)->postJournal($doc, Auth::user());
    }

    public function reverse(): void
    {
        $doc = Document::findOrFail($this->detailId);
        $this->authorize('create', Document::class);

        $reversal = app(DocumentService::class)->createReversingJournal($doc, Auth::user());
        $this->openDetail($reversal->id);
    }

    // -------------------------------------------------------------------------
    // Delete
    // -------------------------------------------------------------------------

    public function openDeleteConfirm(): void
    {
        $this->authorize('delete', Document::findOrFail($this->detailId));
        $this->showDeleteConfirm = true;
    }

    public function confirmDelete(): void
    {
        $doc = Document::findOrFail($this->detailId);
        $this->authorize('delete', $doc);

        $doc->lines()->delete();
        $doc->delete();
        $this->showDeleteConfirm = false;
        $this->closeDetail();
        session()->flash('success', 'Journal deleted.');
    }

    public function with(): array
    {
        $rows = Document::journals()
            ->when($this->search, fn ($q) => $q->where(function ($q): void {
                $q->where('document_number', 'like', "%{$this->search}%")
                    ->orWhere('reference', 'like', "%{$this->search}%");
            }))
            ->when($this->statusFilter, fn ($q) => $q->joinBalance()->where('document_balances.status', $this->statusFilter))
            ->latest('documents.issue_date')
            ->latest('documents.created_at')
            ->paginate(25);

        $detail = null;
        $lines = collect();
        $accounts = collect();
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        if ($this->showDetail && $this->detailId) {
            $detail = Document::findOrFail($this->detailId);
            $lines = $detail->lines()->with('account')->orderBy('line_number')->get();
            $totalDebit = (float) $lines->sum(fn ($line) => max(0, (float) $line->unit_price));
            $totalCredit = (float) $lines->sum(fn ($line) => max(0, -(float) $line->unit_price));

            $controlAccountIds = app(DocumentService::class)->controlAccountIds();
            $accounts = Account::postable()->active()
                ->whereNotIn('id', $controlAccountIds)
                ->orderBy('code')
                ->get(['id', 'code', 'name']);
        }

        $currencySymbol = ExchangeRateService::currencySymbol(
            app(CurrencySettings::class)->base_currency
        );

        return [
            'rows' => $rows,
            'statusCounts' => Document::journals()
                ->join('document_balances', 'document_balances.document_id', '=', 'documents.id')
                ->selectRaw('document_balances.status, COUNT(*) as count')
                ->groupBy('document_balances.status')
                ->pluck('count', 'status'),
            'detail' => $detail,
            'lines' => $lines,
            'accounts' => $accounts,
            'totalDebit' => $totalDebit,
            'totalCredit' => $totalCredit,
            'currencySymbol' => $currencySymbol,
        ];
    }
}; ?>

<div>
@if(session('success'))
    <div class="mx-6 mt-4 px-4 py-3 rounded bg-green-50 border border-green-200 text-sm text-success">
        {{ session('success') }}
    </div>
@endif

<div class="flex items-start justify-between px-6 py-5 border-b border-line">
    <div>
        <h1 class="text-[17px] font-semibold tracking-tight text-ink">Journals</h1>
        <p class="mt-0.5 text-sm text-ink-muted">Manual general ledger adjustments</p>
    </div>
    @can('create', \App\Modules\Core\Models\Document::class)
        <flux:button wire:click="openCreate" icon="plus" size="sm" variant="primary">
            New Journal
        </flux:button>
    @endcan
</div>

{{-- Status tabs --}}
<div class="flex items-center gap-1 px-6 pt-4 border-b border-line overflow-x-auto">
    @php
        $tabs = ['' => 'All', 'draft' => 'Draft', 'posted' => 'Posted'];
    @endphp
    @foreach($tabs as $status => $label)
        <button
            wire:click="$set('statusFilter', '{{ $status }}')"
            @class([
                'px-3 py-2 text-sm font-medium border-b-2 whitespace-nowrap transition-colors',
                'border-primary text-primary' => $statusFilter === $status,
                'border-transparent text-ink-soft hover:text-ink' => $statusFilter !== $status,
            ])
        >
            {{ $label }}
            @if($status !== '' && ($statusCounts[$status] ?? 0) > 0)
                <span class="ml-1 text-xs text-ink-muted">({{ $statusCounts[$status] }})</span>
            @endif
        </button>
    @endforeach
</div>

{{-- Search --}}
<div class="px-6 py-3 border-b border-line bg-surface-alt">
    <flux:input wire:model.live.debounce.300ms="search" placeholder="Search journals…" size="sm" icon="magnifying-glass" class="max-w-xs" />
</div>

{{-- Table --}}
<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Number</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Date</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Amount</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $journal)
                <tr wire:click="openDetail('{{ $journal->id }}')" class="border-t border-line hover:bg-surface-alt cursor-pointer">
                    <td class="px-4 py-3 font-mono text-xs text-ink">
                        {{ $journal->document_number ?? '—' }}
                        @if($journal->reference)
                            <span class="text-ink-muted block text-xs">{{ $journal->reference }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-ink-soft text-xs">{{ $journal->issue_date?->format('d M Y') ?? '—' }}</td>
                    <td class="px-4 py-3 text-right font-medium text-ink tabular-nums">
                        {{ $currencySymbol }}{{ number_format((float) $journal->total, 2) }}
                    </td>
                    <td class="px-4 py-3">
                        @php
                            $badgeClass = match($journal->status) {
                                'draft' => 'bg-surface-alt text-ink-muted',
                                'posted' => 'bg-green-50 text-green-700',
                                default => 'bg-surface-alt text-ink-muted',
                            };
                        @endphp
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $badgeClass }}">
                            {{ ucfirst($journal->status) }}
                        </span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="4" class="px-4 py-12 text-center">
                        <p class="font-medium text-ink">No journals yet.</p>
                        @can('create', \App\Modules\Core\Models\Document::class)
                            <p class="mt-1 text-sm text-ink-muted">Create your first journal to get started.</p>
                        @endcan
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="px-6 py-4 border-t border-line">
    {{ $rows->links() }}
</div>

{{-- ===== Create Modal ===== --}}
<flux:modal name="create-journal-modal" wire:model.self="showCreateModal" class="w-[480px]">
    <form wire:submit="createJournal" class="flex flex-col">
        <div class="p-6 border-b border-line">
            <flux:heading size="lg" class="font-semibold">New Journal</flux:heading>
        </div>
        <div class="p-6 space-y-4">
            <flux:field>
                <flux:label>Date <span class="text-danger">*</span></flux:label>
                <flux:input type="date" wire:model="createForm.issue_date" />
                <flux:error name="createForm.issue_date" />
            </flux:field>

            <flux:field>
                <flux:label>Reference</flux:label>
                <flux:input wire:model="createForm.reference" placeholder="e.g. Depreciation — March" />
                <flux:error name="createForm.reference" />
            </flux:field>

            <flux:field>
                <flux:label>Notes</flux:label>
                <flux:textarea wire:model="createForm.notes" rows="2" placeholder="Reason for this entry…" />
                <flux:error name="createForm.notes" />
            </flux:field>
        </div>
        <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-line bg-surface-alt">
            <flux:button type="button" variant="ghost" wire:click="$set('showCreateModal', false)">Cancel</flux:button>
            <flux:button type="submit" variant="primary">Create Journal</flux:button>
        </div>
    </form>
</flux:modal>

{{-- ===== Detail Flyout ===== --}}
<flux:modal name="journal-detail-flyout" flyout wire:model.self="showDetail" class="w-[760px]" @close="closeDetail">
    @if($detail)
    <div class="flex flex-col h-full">
        <div class="p-6 border-b border-line">
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <flux:heading size="lg" class="font-semibold">
                            {{ $detail->document_number ?? 'Draft Journal' }}
                        </flux:heading>
                        @php
                            $badgeClass = match($detail->status) {
                                'draft' => 'bg-surface-alt text-ink-muted',
                                'posted' => 'bg-green-50 text-green-700',
                                default => 'bg-surface-alt text-ink-muted',
                            };
                        @endphp
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $badgeClass }}">
                            {{ ucfirst($detail->status) }}
                        </span>
                    </div>
                    <p class="text-sm text-ink-soft mt-1">
                        @if($detail->issue_date)
                            {{ $detail->issue_date->format('d M Y') }}
                        @endif
                        @if($detail->reference)
                            · {{ $detail->reference }}
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    @if(!$editingHeader && $detail->status === 'draft')
                        @can('update', $detail)
                            <flux:button wire:click="openEditHeader" size="xs" variant="ghost" icon="pencil">Edit</flux:button>
                        @endcan
                    @endif

                    @if($detail->status === 'draft')
                        @can('update', $detail)
                            <flux:button
                                wire:click="post"
                                wire:confirm="Post this journal? Once posted it cannot be edited or deleted — only reversed."
                                size="xs"
                                variant="primary"
                                icon="check"
                                :disabled="round($totalDebit - $totalCredit, 2) !== 0.0 || $lines->isEmpty()"
                            >Post</flux:button>
                        @endcan
                    @endif

                    @if($detail->status === 'posted')
                        @can('create', \App\Modules\Core\Models\Document::class)
                            <flux:button wire:click="reverse" size="xs" variant="ghost" icon="arrow-uturn-left">Reverse</flux:button>
                        @endcan
                    @endif

                    @if($detail->status === 'draft')
                        @can('delete', $detail)
                            <flux:button wire:click="openDeleteConfirm" size="xs" variant="ghost" class="text-danger">Delete</flux:button>
                        @endcan
                    @endif

                    <button wire:click="closeDetail" class="ml-2 text-ink-muted hover:text-ink">
                        <svg class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    </button>
                </div>
            </div>

            @if($editingHeader)
                <div class="mt-4 pt-4 border-t border-line space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <flux:field>
                            <flux:label>Date <span class="text-danger">*</span></flux:label>
                            <flux:input type="date" wire:model="headerForm.issue_date" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Reference</flux:label>
                            <flux:input wire:model="headerForm.reference" />
                        </flux:field>
                    </div>
                    <flux:field>
                        <flux:label>Notes</flux:label>
                        <flux:textarea wire:model="headerForm.notes" rows="2" />
                    </flux:field>
                    <div class="flex gap-2">
                        <flux:button wire:click="saveHeader" size="sm" variant="primary">Save</flux:button>
                        <flux:button wire:click="cancelEditHeader" size="sm" variant="ghost">Cancel</flux:button>
                    </div>
                </div>
            @endif
        </div>

        <div class="flex-1 overflow-y-auto">
            <div class="grid grid-cols-3 divide-x divide-line border-b border-line">
                <div class="px-6 py-4">
                    <p class="text-xs text-ink-muted uppercase tracking-wide">Total Debit</p>
                    <p class="text-lg font-semibold text-ink mt-1">{{ $currencySymbol }}{{ number_format($totalDebit, 2) }}</p>
                </div>
                <div class="px-6 py-4">
                    <p class="text-xs text-ink-muted uppercase tracking-wide">Total Credit</p>
                    <p class="text-lg font-semibold text-ink mt-1">{{ $currencySymbol }}{{ number_format($totalCredit, 2) }}</p>
                </div>
                <div class="px-6 py-4">
                    <p class="text-xs text-ink-muted uppercase tracking-wide">Difference</p>
                    @php $difference = round($totalDebit - $totalCredit, 2); @endphp
                    <p class="text-lg font-semibold mt-1 {{ $difference === 0.0 ? 'text-success' : 'text-danger' }}">
                        {{ $currencySymbol }}{{ number_format($difference, 2) }}
                        {{ $difference === 0.0 ? '✓ Balanced' : '' }}
                    </p>
                </div>
            </div>

            <div class="px-6 py-4 border-b border-line">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-ink">Lines</h3>
                    @if($detail->status === 'draft' && !$showAddLine)
                        @can('update', $detail)
                            <flux:button wire:click="openAddLine" size="xs" variant="ghost" icon="plus">Add Line</flux:button>
                        @endcan
                    @endif
                </div>
                @if($errors->any())
                    <div class="mb-3 px-3 py-2 rounded bg-red-50 border border-red-200 text-xs text-danger">
                        <ul class="list-disc pl-4 space-y-0.5">
                            @foreach($errors->all() as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                <div class="overflow-x-auto">
                    <table class="w-full text-xs border-separate border-spacing-0">
                        <thead>
                            <tr class="text-ink-muted uppercase tracking-wide">
                                <th class="text-left py-2 pr-4 w-[38%]">Description</th>
                                <th class="text-left py-2 px-4">Account</th>
                                <th class="text-right py-2 px-4 whitespace-nowrap">Debit</th>
                                <th class="text-right py-2 pl-4 whitespace-nowrap">Credit</th>
                                @if($detail->status === 'draft')
                                    <th class="w-12"></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($lines as $line)
                                @if($editingLineId === $line->id)
                                    <tr class="border-t border-line">
                                        <td class="py-2 pr-2"><input type="text" wire:model="editingLine.description" class="w-full border border-line rounded px-2 py-1 text-xs" /></td>
                                        <td class="py-2 px-2">
                                            <select wire:model="editingLine.account_id" class="w-full border border-line rounded px-2 py-1 text-xs">
                                                <option value="">—</option>
                                                @foreach($accounts as $account)
                                                    <option value="{{ $account->id }}">{{ $account->code }} {{ $account->name }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="py-2 px-2"><input type="number" wire:model="editingLine.debit" step="0.01" min="0" class="w-24 border border-line rounded px-2 py-1 text-xs text-right" /></td>
                                        <td class="py-2 pl-2"><input type="number" wire:model="editingLine.credit" step="0.01" min="0" class="w-24 border border-line rounded px-2 py-1 text-xs text-right" /></td>
                                        @if($detail->status === 'draft')
                                            <td class="py-2">
                                                <div class="flex gap-1">
                                                    <button wire:click="saveLine" class="text-success hover:text-green-700"><svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg></button>
                                                    <button wire:click="cancelLine" class="text-ink-muted hover:text-ink"><svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg></button>
                                                </div>
                                            </td>
                                        @endif
                                    </tr>
                                @else
                                    <tr class="border-t border-line group">
                                        <td class="py-2.5 pr-4 text-ink">{{ $line->description ?? '—' }}</td>
                                        <td class="py-2.5 px-4 text-ink-soft">{{ $line->account?->display_name ?? '—' }}</td>
                                        <td class="py-2.5 px-4 text-right text-ink tabular-nums whitespace-nowrap">{{ (float) $line->unit_price > 0 ? $currencySymbol.number_format((float) $line->unit_price, 2) : '—' }}</td>
                                        <td class="py-2.5 pl-4 text-right text-ink tabular-nums whitespace-nowrap">{{ (float) $line->unit_price < 0 ? $currencySymbol.number_format(-(float) $line->unit_price, 2) : '—' }}</td>
                                        @if($detail->status === 'draft')
                                            <td class="py-2">
                                                <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                                    <button wire:click="editLine('{{ $line->id }}')" class="text-ink-muted hover:text-ink"><svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg></button>
                                                    <button wire:click="deleteLine('{{ $line->id }}')" wire:confirm="Delete this line?" class="text-ink-muted hover:text-danger"><svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg></button>
                                                </div>
                                            </td>
                                        @endif
                                    </tr>
                                @endif
                            @endforeach

                            @if($showAddLine)
                                <tr class="border-t border-line bg-surface-alt">
                                    <td class="py-2 pr-2"><input type="text" wire:model="newLine.description" placeholder="Description" class="w-full border border-line rounded px-2 py-1 text-xs" autofocus /></td>
                                    <td class="py-2 px-2">
                                        <select wire:model="newLine.account_id" class="w-full border border-line rounded px-2 py-1 text-xs">
                                            <option value="">—</option>
                                            @foreach($accounts as $account)
                                                <option value="{{ $account->id }}">{{ $account->code }} {{ $account->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="py-2 px-2"><input type="number" wire:model="newLine.debit" step="0.01" min="0" placeholder="0.00" class="w-24 border border-line rounded px-2 py-1 text-xs text-right" /></td>
                                    <td class="py-2 pl-2"><input type="number" wire:model="newLine.credit" step="0.01" min="0" placeholder="0.00" class="w-24 border border-line rounded px-2 py-1 text-xs text-right" /></td>
                                    <td class="py-2">
                                        <div class="flex gap-1">
                                            <button wire:click="saveNewLine" class="text-success hover:text-green-700"><svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg></button>
                                            <button wire:click="cancelAddLine" class="text-ink-muted hover:text-ink"><svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg></button>
                                        </div>
                                    </td>
                                </tr>
                            @endif

                            @if($lines->isEmpty() && !$showAddLine)
                                <tr><td colspan="5" class="py-6 text-center text-xs text-ink-muted">No lines yet.</td></tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>

            @if($detail->notes)
                <div class="px-6 py-4">
                    <p class="text-xs font-medium text-ink-muted uppercase tracking-wide mb-1">Notes</p>
                    <p class="text-sm text-ink">{{ $detail->notes }}</p>
                </div>
            @endif
        </div>
    </div>
    @endif
</flux:modal>

{{-- Delete Confirm --}}
<flux:modal name="journal-delete-confirm" wire:model.self="showDeleteConfirm" class="w-[400px]">
    <div class="p-6">
        <flux:heading size="lg" class="font-semibold mb-2">Delete Journal?</flux:heading>
        <p class="text-sm text-ink-soft mb-6">This will permanently delete the draft journal and all its lines.</p>
        <div class="flex gap-3 justify-end">
            <flux:button variant="ghost" wire:click="$set('showDeleteConfirm', false)">Cancel</flux:button>
            <flux:button variant="danger" wire:click="confirmDelete">Delete</flux:button>
        </div>
    </div>
</flux:modal>

</div>
