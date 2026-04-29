<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Models\DocumentLine;
use App\Modules\Purchasing\Services\DocumentService;
use App\Modules\Purchasing\Services\ExchangeRateService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('components.layout.app')] class extends Component
{
    use WithPagination, WithFileUploads;

    // List filters
    public string $search = '';

    public string $statusFilter = '';

    // Upload modal
    public bool $showUpload = false;

    public mixed $uploadFile = null;

    public string $uploadPartyId = '';

    public string $uploadCurrency = '';

    public string $uploadError = '';

    // Detail flyout
    public bool $showDetail = false;

    public ?string $detailId = null;

    // Inline line editing
    /** @var array<string, mixed> */
    public array $editingLine = [];

    public ?string $editingLineId = null;

    // Status action modals
    public bool $showRejectReason = false;

    public bool $showDisputeReason = false;

    public bool $showReprocessConfirm = false;

    public bool $showDeleteConfirm = false;

    public string $actionReason = '';

    // Header editing
    public bool $editingHeader = false;

    /** @var array<string, mixed> */
    public array $headerForm = [];

    public function mount(): void
    {
        $this->authorize('viewAny', Document::class);
        $this->uploadCurrency = app(CurrencySettings::class)->base_currency;
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
    // Upload
    // -------------------------------------------------------------------------

    public function openUpload(): void
    {
        $this->authorize('create', Document::class);
        $this->reset(['uploadFile', 'uploadPartyId', 'uploadError']);
        $this->uploadCurrency = app(CurrencySettings::class)->base_currency;
        $this->showUpload = true;
    }

    public function processUpload(): void
    {
        $this->authorize('create', Document::class);
        $this->uploadError = '';

        $this->validate([
            'uploadFile' => 'required|file|mimes:pdf|max:20480',
        ]);

        $path = $this->uploadFile->getRealPath();

        try {
            $result = app(DocumentService::class)->createFromPdf($path, [
                'party_id' => $this->uploadPartyId ?: null,
                'currency' => $this->uploadCurrency,
            ]);

            $this->showUpload = false;
            $this->reset(['uploadFile', 'uploadPartyId']);

            if ($result['duplicate']) {
                session()->flash('warning', 'This PDF has already been uploaded (document '.$result['document']->document_number.').');
            } else {
                session()->flash('success', 'Invoice uploaded and queued for processing.');
            }
        } catch (\Throwable $e) {
            $this->uploadError = $e->getMessage();
        }
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
        $this->showDetail = true;
    }

    public function closeDetail(): void
    {
        $this->showDetail = false;
        $this->detailId = null;
        $this->editingLineId = null;
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
            'party_id' => $doc->party_id ?? '',
            'reference' => $doc->reference ?? '',
            'issue_date' => $doc->issue_date?->format('Y-m-d') ?? '',
            'due_date' => $doc->due_date?->format('Y-m-d') ?? '',
            'notes' => $doc->notes ?? '',
        ];
        $this->editingHeader = true;
    }

    public function saveHeader(): void
    {
        $doc = Document::findOrFail($this->detailId);
        $this->authorize('update', $doc);

        $this->validate([
            'headerForm.party_id' => 'nullable|exists:parties,id',
            'headerForm.reference' => 'nullable|string|max:255',
            'headerForm.issue_date' => 'nullable|date',
            'headerForm.due_date' => 'nullable|date',
            'headerForm.notes' => 'nullable|string|max:2000',
        ]);

        $doc->update([
            'party_id' => $this->headerForm['party_id'] ?: null,
            'reference' => $this->headerForm['reference'] ?: null,
            'issue_date' => $this->headerForm['issue_date'] ?: null,
            'due_date' => $this->headerForm['due_date'] ?: null,
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
    // Delete
    // -------------------------------------------------------------------------

    public function openDeleteConfirm(): void
    {
        $doc = Document::findOrFail($this->detailId);
        $this->authorize('delete', $doc);
        $this->showDeleteConfirm = true;
    }

    public function confirmDelete(): void
    {
        $doc = Document::findOrFail($this->detailId);
        $this->authorize('delete', $doc);
        app(DocumentService::class)->deleteDocument($doc, Auth::user());
        $this->showDeleteConfirm = false;
        $this->closeDetail();
        session()->flash('success', 'Invoice deleted.');
    }

    // -------------------------------------------------------------------------
    // Reprocess
    // -------------------------------------------------------------------------

    public function openReprocessConfirm(): void
    {
        $this->authorize('can-reprocess-invoices');
        $this->showReprocessConfirm = true;
    }

    public function confirmReprocess(): void
    {
        $this->authorize('can-reprocess-invoices');
        $doc = Document::findOrFail($this->detailId);
        app(DocumentService::class)->reprocess($doc, Auth::user());
        $this->showReprocessConfirm = false;
        session()->flash('success', 'Invoice queued for reprocessing.');
    }

    // -------------------------------------------------------------------------
    // Line editing
    // -------------------------------------------------------------------------

    public function editLine(string $lineId): void
    {
        $line = DocumentLine::findOrFail($lineId);
        $this->editingLineId = $lineId;
        $this->editingLine = [
            'description' => $line->description ?? '',
            'account_id' => $line->account_id ?? '',
            'quantity' => (string) $line->quantity,
            'unit_price' => (string) $line->unit_price,
            'tax_rate' => (string) ($line->tax_rate ?? ''),
        ];
    }

    public function saveLine(): void
    {
        $this->validate([
            'editingLine.description' => 'nullable|string|max:1000',
            'editingLine.account_id' => 'nullable|exists:accounts,id',
            'editingLine.quantity' => 'required|numeric|min:0',
            'editingLine.unit_price' => 'required|numeric',
            'editingLine.tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $line = DocumentLine::findOrFail($this->editingLineId);
        $this->authorize('update', $line->document);

        $line->update([
            'description' => $this->editingLine['description'] ?: null,
            'account_id' => $this->editingLine['account_id'] ?: null,
            'quantity' => (float) $this->editingLine['quantity'],
            'unit_price' => (float) $this->editingLine['unit_price'],
            'tax_rate' => $this->editingLine['tax_rate'] !== '' ? (float) $this->editingLine['tax_rate'] : null,
        ]);

        $this->editingLineId = null;
        $this->editingLine = [];
    }

    public function cancelLine(): void
    {
        $this->editingLineId = null;
        $this->editingLine = [];
    }

    // -------------------------------------------------------------------------
    // Status transitions
    // -------------------------------------------------------------------------

    public function markReviewed(): void
    {
        $doc = Document::findOrFail($this->detailId);
        $this->authorize('can-review-invoices');
        app(DocumentService::class)->markAsReviewed($doc, Auth::user());
    }

    public function approve(): void
    {
        $doc = Document::findOrFail($this->detailId);
        $this->authorize('can-authorise-invoices');
        app(DocumentService::class)->approve($doc, Auth::user());
    }

    public function post(): void
    {
        $doc = Document::findOrFail($this->detailId);
        $this->authorize('can-post-invoices');
        app(DocumentService::class)->post($doc, Auth::user());
    }

    public function openReject(): void
    {
        $this->actionReason = '';
        $this->showRejectReason = true;
    }

    public function confirmReject(): void
    {
        $this->validate(['actionReason' => 'required|string|max:500']);
        $doc = Document::findOrFail($this->detailId);
        $this->authorize('can-review-invoices');
        app(DocumentService::class)->reject($doc, Auth::user(), $this->actionReason);
        $this->showRejectReason = false;
    }

    public function openDispute(): void
    {
        $this->actionReason = '';
        $this->showDisputeReason = true;
    }

    public function confirmDispute(): void
    {
        $this->validate(['actionReason' => 'required|string|max:500']);
        $doc = Document::findOrFail($this->detailId);
        $this->authorize('can-review-invoices');
        app(DocumentService::class)->dispute($doc, Auth::user(), $this->actionReason);
        $this->showDisputeReason = false;
    }

    public function with(): array
    {
        $rows = Document::purchaseInvoices()
            ->with('party.business')
            ->when($this->search, fn ($q) => $q->where(function ($q): void {
                $q->where('document_number', 'like', "%{$this->search}%")
                    ->orWhere('reference', 'like', "%{$this->search}%")
                    ->orWhereHas('party.business', fn ($b) => $b->where('trading_name', 'like', "%{$this->search}%")
                        ->orWhere('legal_name', 'like', "%{$this->search}%")
                    );
            }))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->latest('issue_date')
            ->latest('created_at')
            ->paginate(25);

        $detail = null;
        $lines = collect();
        $activities = collect();
        $accounts = collect();

        if ($this->showDetail && $this->detailId) {
            $detail = Document::with(['party.business', 'payableAccount'])->findOrFail($this->detailId);
            $lines = $detail->lines()->with(['account', 'llmSuggestedAccount'])->get();
            $activities = $detail->activities()->with('user')->get();
            $accounts = Account::postable()->active()->orderBy('code')->get(['id', 'code', 'name']);
        }

        return [
            'rows' => $rows,
            'statusCounts' => Document::purchaseInvoices()
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
            'suppliers' => Party::suppliers()->with('business')->get(),
            'detail' => $detail,
            'lines' => $lines,
            'activities' => $activities,
            'accounts' => $accounts,
            'baseCurrency' => app(CurrencySettings::class)->base_currency,
            'baseCurrencySymbol' => ExchangeRateService::currencySymbol(app(CurrencySettings::class)->base_currency),
            'detailCurrencySymbol' => $detail ? ExchangeRateService::currencySymbol($detail->currency) : '',
        ];
    }
}; ?>

<div>
{{-- Flash messages --}}
@if(session('success'))
    <div class="mx-6 mt-4 px-4 py-3 rounded bg-green-50 border border-green-200 text-sm text-success">
        {{ session('success') }}
    </div>
@endif
@if(session('warning'))
    <div class="mx-6 mt-4 px-4 py-3 rounded bg-yellow-50 border border-yellow-200 text-sm text-warning">
        {{ session('warning') }}
    </div>
@endif

{{-- Page header --}}
<div class="flex items-start justify-between px-6 py-5 border-b border-line">
    <div>
        <h1 class="text-[17px] font-semibold tracking-tight text-ink">Purchase Invoices</h1>
        <p class="mt-0.5 text-sm text-ink-muted">Supplier invoices and their processing status</p>
    </div>
    @can('create', \App\Modules\Purchasing\Models\Document::class)
        <flux:button wire:click="openUpload" icon="arrow-up-tray" size="sm" variant="primary">
            Upload Invoice
        </flux:button>
    @endcan
</div>

{{-- Status tabs --}}
<div class="flex items-center gap-1 px-6 pt-4 border-b border-line overflow-x-auto">
    @php
        $tabs = [
            '' => 'All',
            'received' => 'Received',
            'reviewed' => 'Reviewed',
            'approved' => 'Approved',
            'posted' => 'Posted',
            'disputed' => 'Disputed',
            'rejected' => 'Rejected',
        ];
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
    <flux:input
        wire:model.live.debounce.300ms="search"
        placeholder="Search invoices…"
        size="sm"
        icon="magnifying-glass"
        class="max-w-xs"
    />
</div>

{{-- Invoice table --}}
<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Number</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Supplier</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Date</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Total</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Balance Due</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $invoice)
                <tr
                    wire:click="openDetail('{{ $invoice->id }}')"
                    class="border-t border-line hover:bg-surface-alt cursor-pointer"
                >
                    <td class="px-4 py-3 font-mono text-xs text-ink">
                        {{ $invoice->document_number ?? '—' }}
                        @if($invoice->reference)
                            <span class="text-ink-muted block text-xs">{{ $invoice->reference }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 font-medium text-ink">
                        {{ $invoice->party?->business?->display_name ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-ink-soft text-xs">
                        {{ $invoice->issue_date?->format('d M Y') ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-right font-medium text-ink tabular-nums">
                        {{ $baseCurrencySymbol }}{{ number_format((float) $invoice->total, 2) }}
                        @if($invoice->is_foreign_currency && $invoice->foreign_total !== null)
                            <span class="text-xs text-ink-muted block">{{ ExchangeRateService::currencySymbol($invoice->currency) }}{{ number_format((float) $invoice->foreign_total, 2) }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right tabular-nums">
                        <span @class([
                            'font-medium',
                            'text-danger' => (float) $invoice->balance_due > 0 && !in_array($invoice->status, ['rejected']),
                            'text-ink-soft' => (float) $invoice->balance_due <= 0 || in_array($invoice->status, ['rejected']),
                        ])>
                            {{ number_format((float) $invoice->balance_due, 2) }}
                        </span>
                    </td>
                    <td class="px-4 py-3">
                        @include('livewire.pages.purchase-invoices._status-badge', ['status' => $invoice->status])
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-12 text-center">
                        <p class="font-medium text-ink">No invoices found.</p>
                        @if(!$statusFilter && !$search)
                            <p class="mt-1 text-sm text-ink-muted">Upload a PDF to get started.</p>
                        @endif
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="px-6 py-4 border-t border-line">
    {{ $rows->links() }}
</div>

{{-- ===== Upload Modal ===== --}}
<flux:modal name="upload-modal" wire:model.self="showUpload" class="w-[440px]">
    <form wire:submit="processUpload" class="flex flex-col">
        <div class="p-6 border-b border-line">
            <flux:heading size="lg" class="font-semibold">Upload Invoice PDF</flux:heading>
        </div>
        <div class="p-6 space-y-4">
            @if($uploadError)
                <div class="px-4 py-3 rounded bg-red-50 border border-red-200 text-sm text-danger">{{ $uploadError }}</div>
            @endif

            <flux:field>
                <flux:label>PDF File <span class="text-danger">*</span></flux:label>
                <flux:input wire:model="uploadFile" type="file" accept=".pdf" />
                <flux:error name="uploadFile" />
            </flux:field>

            <flux:field>
                <flux:label>Supplier (optional)</flux:label>
                <flux:select wire:model="uploadPartyId">
                    <option value="">Auto-detect from invoice</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}">{{ $supplier->business?->display_name }}</option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>Currency</flux:label>
                <flux:input wire:model="uploadCurrency" class="uppercase max-w-[120px]" maxlength="3" />
            </flux:field>
        </div>
        <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-line bg-surface-alt">
            <flux:button type="button" variant="ghost" wire:click="$set('showUpload', false)">Cancel</flux:button>
            <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                <span wire:loading.remove>Upload & Process</span>
                <span wire:loading>Uploading…</span>
            </flux:button>
        </div>
    </form>
</flux:modal>

{{-- ===== Detail Flyout ===== --}}
<flux:modal name="detail-flyout" flyout wire:model.self="showDetail" class="w-[720px]" @close="closeDetail">
    @if($detail)
    <div class="flex flex-col h-full">
        {{-- Header --}}
        <div class="p-6 border-b border-line">
            <div class="flex items-start justify-between">
                <div>
                    <div class="flex items-center gap-3">
                        <flux:heading size="lg" class="font-semibold">
                            {{ $detail->document_number ?? 'Draft Invoice' }}
                        </flux:heading>
                        @include('livewire.pages.purchase-invoices._status-badge', ['status' => $detail->status])
                    </div>
                    <p class="text-sm text-ink-soft mt-1">
                        {{ $detail->party?->business?->display_name ?? 'No supplier' }}
                        @if($detail->issue_date)
                            · {{ $detail->issue_date->format('d M Y') }}
                        @endif
                        @if($detail->reference)
                            · Ref: {{ $detail->reference }}
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    {{-- Edit header (before approved) --}}
                    @if(!$editingHeader && in_array($detail->status, ['received', 'reviewed']))
                        @can('update', $detail)
                            <flux:button wire:click="openEditHeader" size="xs" variant="ghost" icon="pencil">Edit</flux:button>
                        @endcan
                    @endif
                    {{-- Reprocess --}}
                    @if(in_array($detail->status, ['received', 'reviewed', 'rejected', 'disputed']))
                        @can('can-reprocess-invoices')
                            <flux:button wire:click="openReprocessConfirm" size="xs" variant="ghost" icon="arrow-path">Reprocess</flux:button>
                        @endcan
                    @endif
                    {{-- Delete --}}
                    @if($detail->status !== 'posted')
                        @can('delete', $detail)
                            <flux:button wire:click="openDeleteConfirm" size="xs" variant="ghost" class="text-danger">Delete</flux:button>
                        @endcan
                    @endif
                    <button wire:click="closeDetail" class="ml-2 text-ink-muted hover:text-ink">
                        <svg class="w-5 h-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    </button>
                </div>
            </div>

            {{-- Inline header edit form --}}
            @if($editingHeader)
                <div class="mt-4 pt-4 border-t border-line space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <flux:field>
                            <flux:label>Supplier</flux:label>
                            <flux:select wire:model="headerForm.party_id">
                                <option value="">— None —</option>
                                @foreach($suppliers as $supplier)
                                    <option value="{{ $supplier->id }}">{{ $supplier->business?->display_name }}</option>
                                @endforeach
                            </flux:select>
                        </flux:field>
                        <flux:field>
                            <flux:label>Reference</flux:label>
                            <flux:input wire:model="headerForm.reference" placeholder="Supplier invoice number" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Issue Date</flux:label>
                            <flux:input type="date" wire:model="headerForm.issue_date" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Due Date</flux:label>
                            <flux:input type="date" wire:model="headerForm.due_date" />
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
            {{-- Totals --}}
            <div class="grid grid-cols-3 divide-x divide-line border-b border-line">
                <div class="px-6 py-4">
                    <p class="text-xs text-ink-muted uppercase tracking-wide">Subtotal</p>
                    <p class="text-lg font-semibold text-ink mt-1">{{ $baseCurrencySymbol }}{{ number_format((float) $detail->subtotal, 2) }}</p>
                    @if($detail->is_foreign_currency && $detail->foreign_subtotal !== null)
                        <p class="text-xs text-ink-muted mt-0.5">{{ $detailCurrencySymbol }}{{ number_format((float) $detail->foreign_subtotal, 2) }}</p>
                    @endif
                </div>
                <div class="px-6 py-4">
                    <p class="text-xs text-ink-muted uppercase tracking-wide">Total</p>
                    <p class="text-lg font-semibold text-ink mt-1">{{ $baseCurrencySymbol }}{{ number_format((float) $detail->total, 2) }}</p>
                    @if($detail->is_foreign_currency && $detail->foreign_total !== null)
                        <p class="text-xs text-ink-muted mt-0.5">{{ $detailCurrencySymbol }}{{ number_format((float) $detail->foreign_total, 2) }}</p>
                    @endif
                </div>
                <div class="px-6 py-4">
                    <p class="text-xs text-ink-muted uppercase tracking-wide">Balance Due</p>
                    <p class="text-lg font-semibold mt-1 {{ (float) $detail->balance_due > 0 ? 'text-danger' : 'text-success' }}">
                        {{ $baseCurrencySymbol }}{{ number_format((float) $detail->balance_due, 2) }}
                    </p>
                    @if($detail->is_foreign_currency && $detail->foreign_balance_due !== null)
                        <p class="text-xs text-ink-muted mt-0.5">{{ $detailCurrencySymbol }}{{ number_format((float) $detail->foreign_balance_due, 2) }}</p>
                    @endif
                </div>
            </div>

            {{-- Exchange rate (foreign currency only) --}}
            @if($detail->is_foreign_currency)
                <div class="px-6 py-3 border-b border-line bg-surface-alt flex items-center gap-2 text-xs text-ink-muted">
                    <span>Exchange rate:</span>
                    <span class="font-medium text-ink">
                        {{ $detailCurrencySymbol }}1 = {{ $baseCurrencySymbol }}{{ number_format((float) $detail->exchange_rate, 4) }}
                    </span>
                    @if($detail->exchange_rate_date)
                        <span>· {{ $detail->exchange_rate_date->format('d M Y') }}</span>
                    @endif
                    @if($detail->exchange_rate_provisional)
                        <span class="px-1.5 py-0.5 rounded bg-yellow-100 text-yellow-700 font-medium">provisional</span>
                    @endif
                </div>
            @endif

            {{-- LLM confidence --}}
            @if($detail->llm_confidence !== null)
                <div class="px-6 py-3 border-b border-line bg-surface-alt flex items-center gap-2">
                    <span class="text-xs text-ink-muted">LLM confidence:</span>
                    <span @class([
                        'text-xs font-medium',
                        'text-success' => (float) $detail->llm_confidence >= 0.9,
                        'text-warning' => (float) $detail->llm_confidence >= 0.7 && (float) $detail->llm_confidence < 0.9,
                        'text-danger' => (float) $detail->llm_confidence < 0.7,
                    ])>
                        {{ number_format((float) $detail->llm_confidence * 100, 1) }}%
                    </span>
                </div>
            @endif

            {{-- Lines --}}
            <div class="px-6 py-4 border-b border-line">
                <h3 class="text-sm font-semibold text-ink mb-3">Lines</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs border-separate border-spacing-0">
                        <thead>
                            <tr class="text-ink-muted uppercase tracking-wide">
                                <th class="text-left py-2 pr-4 w-[38%]">Description</th>
                                <th class="text-left py-2 px-4">Account</th>
                                <th class="text-right py-2 px-4 whitespace-nowrap">Qty</th>
                                <th class="text-right py-2 px-4 whitespace-nowrap">Rate</th>
                                <th class="text-right py-2 px-4 whitespace-nowrap">Tax%</th>
                                <th class="text-right py-2 pl-4 whitespace-nowrap">Total</th>
                                @if(!in_array($detail->status, ['posted', 'rejected']))
                                    <th class="w-8"></th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($lines as $line)
                                @if($editingLineId === $line->id)
                                    <tr class="border-t border-line">
                                        <td class="py-2 pr-2">
                                            <input type="text" wire:model="editingLine.description" class="w-full border border-line rounded px-2 py-1 text-xs" />
                                        </td>
                                        <td class="py-2 px-2">
                                            <select wire:model="editingLine.account_id" class="w-full border border-line rounded px-2 py-1 text-xs">
                                                <option value="">—</option>
                                                @foreach($accounts as $account)
                                                    <option value="{{ $account->id }}">{{ $account->code }} {{ $account->name }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="py-2 px-2">
                                            <input type="number" wire:model="editingLine.quantity" step="0.0001" class="w-20 border border-line rounded px-2 py-1 text-xs text-right" />
                                        </td>
                                        <td class="py-2 px-2">
                                            <input type="number" wire:model="editingLine.unit_price" step="0.0001" class="w-24 border border-line rounded px-2 py-1 text-xs text-right" />
                                        </td>
                                        <td class="py-2 px-2">
                                            <input type="number" wire:model="editingLine.tax_rate" step="0.01" min="0" max="100" class="w-16 border border-line rounded px-2 py-1 text-xs text-right" />
                                        </td>
                                        <td class="py-2 pl-2 text-right font-medium text-ink tabular-nums whitespace-nowrap">
                                            {{ number_format((float) $line->line_total + (float) $line->tax_amount, 2) }}
                                        </td>
                                        <td class="py-2">
                                            <div class="flex gap-1">
                                                <button wire:click="saveLine" class="text-success hover:text-green-700">
                                                    <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                                </button>
                                                <button wire:click="cancelLine" class="text-ink-muted hover:text-ink">
                                                    <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @else
                                    <tr class="border-t border-line group">
                                        <td class="py-2.5 pr-4 text-ink">
                                            {{ $line->description ?? '—' }}
                                            @if($line->llmSuggestedAccount && !$line->account_id)
                                                <span class="text-ink-muted block text-xs">Suggested: {{ $line->llmSuggestedAccount->display_name }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2.5 px-4 text-ink-soft">
                                            {{ $line->account?->display_name ?? '—' }}
                                        </td>
                                        <td class="py-2.5 px-4 text-right text-ink-soft tabular-nums whitespace-nowrap">{{ $line->quantity }}</td>
                                        <td class="py-2.5 px-4 text-right text-ink-soft tabular-nums whitespace-nowrap">
                                            {{ $baseCurrencySymbol }}{{ number_format((float) $line->unit_price, 4) }}
                                            @if($detail->is_foreign_currency && $line->foreign_unit_price !== null)
                                                <span class="block text-ink-muted">{{ $detailCurrencySymbol }}{{ number_format((float) $line->foreign_unit_price, 4) }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2.5 px-4 text-right text-ink-soft tabular-nums whitespace-nowrap">
                                            {{ $line->tax_rate !== null ? number_format((float) $line->tax_rate, 2).'%' : '—' }}
                                        </td>
                                        <td class="py-2.5 pl-4 text-right font-medium text-ink tabular-nums whitespace-nowrap">
                                            {{ number_format((float) $line->line_total + (float) $line->tax_amount, 2) }}
                                        </td>
                                        @if(!in_array($detail->status, ['posted', 'rejected']))
                                            <td class="py-2">
                                                <button
                                                    wire:click="editLine('{{ $line->id }}')"
                                                    class="text-ink-muted hover:text-ink opacity-0 group-hover:opacity-100 transition-opacity"
                                                >
                                                    <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg>
                                                </button>
                                            </td>
                                        @endif
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Activity log --}}
            @if($activities->isNotEmpty())
                <div class="px-6 py-4 border-b border-line">
                    <h3 class="text-sm font-semibold text-ink mb-3">Activity</h3>
                    <div class="space-y-2">
                        @foreach($activities as $activity)
                            <div class="flex items-start gap-3 text-xs">
                                <span class="text-ink-muted whitespace-nowrap mt-0.5">{{ $activity->created_at->format('d M H:i') }}</span>
                                <div>
                                    <span class="font-medium text-ink">{{ $activity->user?->name ?? 'System' }}</span>
                                    <span class="text-ink-soft ml-1">{{ $activity->description }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- Status actions --}}
        @php
            $allowedActions = [
                'received' => ['review', 'reject', 'dispute'],
                'reviewed' => ['approve', 'reject', 'dispute'],
                'approved' => ['post', 'dispute'],
                'disputed' => ['review', 'reject'],
                'posted' => [],
                'rejected' => [],
            ][$detail->status] ?? [];
        @endphp

        @if(count($allowedActions) > 0)
            <div class="flex items-center gap-2 px-6 py-4 border-t border-line bg-surface-alt">
                @if(in_array('review', $allowedActions))
                    @can('can-review-invoices')
                        <flux:button wire:click="markReviewed" size="sm" variant="primary">Mark Reviewed</flux:button>
                    @endcan
                @endif
                @if(in_array('approve', $allowedActions))
                    @can('can-authorise-invoices')
                        <flux:button wire:click="approve" size="sm" variant="primary">Approve</flux:button>
                    @endcan
                @endif
                @if(in_array('post', $allowedActions))
                    @can('can-post-invoices')
                        <flux:button wire:click="post" size="sm" variant="primary">Post to GL</flux:button>
                    @endcan
                @endif
                <flux:spacer />
                @if(in_array('dispute', $allowedActions))
                    @can('can-review-invoices')
                        <flux:button wire:click="openDispute" size="sm" variant="ghost">Dispute</flux:button>
                    @endcan
                @endif
                @if(in_array('reject', $allowedActions))
                    @can('can-review-invoices')
                        <flux:button wire:click="openReject" size="sm" variant="ghost" class="text-danger">Reject</flux:button>
                    @endcan
                @endif
            </div>
        @endif
    </div>
    @endif
</flux:modal>

{{-- Reject reason modal --}}
<flux:modal name="reject-modal" wire:model.self="showRejectReason" class="w-[400px]">
    <form wire:submit="confirmReject" class="p-6 space-y-4">
        <flux:heading>Reject Invoice</flux:heading>
        <flux:field>
            <flux:label>Reason <span class="text-danger">*</span></flux:label>
            <flux:textarea wire:model="actionReason" rows="3" placeholder="Explain why this invoice is being rejected…" />
            <flux:error name="actionReason" />
        </flux:field>
        <div class="flex justify-end gap-3">
            <flux:button type="button" variant="ghost" wire:click="$set('showRejectReason', false)">Cancel</flux:button>
            <flux:button type="submit" variant="danger">Reject</flux:button>
        </div>
    </form>
</flux:modal>

{{-- Dispute reason modal --}}
<flux:modal name="dispute-modal" wire:model.self="showDisputeReason" class="w-[400px]">
    <form wire:submit="confirmDispute" class="p-6 space-y-4">
        <flux:heading>Dispute Invoice</flux:heading>
        <flux:field>
            <flux:label>Reason <span class="text-danger">*</span></flux:label>
            <flux:textarea wire:model="actionReason" rows="3" placeholder="Explain the dispute…" />
            <flux:error name="actionReason" />
        </flux:field>
        <div class="flex justify-end gap-3">
            <flux:button type="button" variant="ghost" wire:click="$set('showDisputeReason', false)">Cancel</flux:button>
            <flux:button type="submit" variant="outline">Dispute</flux:button>
        </div>
    </form>
</flux:modal>

{{-- Reprocess confirm modal --}}
<flux:modal name="reprocess-modal" wire:model.self="showReprocessConfirm" class="w-[400px]">
    <div class="p-6 space-y-4">
        <flux:heading>Reprocess Invoice</flux:heading>
        <p class="text-sm text-ink-soft">This will delete all existing lines and re-run the LLM extraction. Any manual edits to lines will be lost. Continue?</p>
        <div class="flex justify-end gap-3">
            <flux:button variant="ghost" wire:click="$set('showReprocessConfirm', false)">Cancel</flux:button>
            <flux:button variant="primary" wire:click="confirmReprocess">Reprocess</flux:button>
        </div>
    </div>
</flux:modal>

{{-- Delete confirm modal --}}
<flux:modal name="delete-invoice-modal" wire:model.self="showDeleteConfirm" class="w-[400px]">
    <div class="p-6 space-y-4">
        <flux:heading>Delete Invoice</flux:heading>
        <p class="text-sm text-ink-soft">This invoice will be permanently deleted. This action cannot be undone.</p>
        <div class="flex justify-end gap-3">
            <flux:button variant="ghost" wire:click="$set('showDeleteConfirm', false)">Cancel</flux:button>
            <flux:button variant="danger" wire:click="confirmDelete">Delete</flux:button>
        </div>
    </div>
</flux:modal>
</div>
