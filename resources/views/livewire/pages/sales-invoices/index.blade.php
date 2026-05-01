<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Models\PaymentTerm;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Models\DocumentLine;
use App\Modules\Purchasing\Services\DocumentService;
use App\Modules\Purchasing\Services\ExchangeRateService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layout.app')] class extends Component
{
    use WithPagination;

    // List filters
    public string $search = '';

    public string $statusFilter = '';

    // Create modal
    public bool $showCreateModal = false;

    /** @var array<string, mixed> */
    public array $createForm = [
        'party_id' => '',
        'issue_date' => '',
        'payment_term_id' => '',
        'reference' => '',
        'notes' => '',
    ];

    // Detail flyout
    public bool $showDetail = false;

    public ?string $detailId = null;

    // Header editing
    public bool $editingHeader = false;

    /** @var array<string, mixed> */
    public array $headerForm = [];

    // Existing line editing
    /** @var array<string, mixed> */
    public array $editingLine = [];

    public ?string $editingLineId = null;

    // Add new line
    public bool $showAddLine = false;

    /** @var array<string, mixed> */
    public array $newLine = [];

    // Action confirms
    public bool $showSendModal = false;

    /** @var array<int, array{name: string, email: string, selected: bool}> */
    public array $sendRecipients = [];

    public string $sendError = '';

    public bool $showVoidConfirm = false;

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
            'party_id' => '',
            'issue_date' => now()->toDateString(),
            'payment_term_id' => '',
            'reference' => '',
            'notes' => '',
        ];
        $this->showCreateModal = true;
    }

    public function createInvoice(): void
    {
        $this->authorize('create', Document::class);

        $this->validate([
            'createForm.party_id' => 'required|exists:parties,id',
            'createForm.issue_date' => 'required|date',
            'createForm.payment_term_id' => 'nullable|exists:payment_terms,id',
            'createForm.reference' => 'nullable|string|max:255',
            'createForm.notes' => 'nullable|string|max:2000',
        ]);

        $client = Party::findOrFail($this->createForm['party_id']);

        $doc = app(BillingService::class)->createDraft($client, [
            'issue_date' => $this->createForm['issue_date'],
            'payment_term_id' => $this->createForm['payment_term_id'] ?: null,
            'reference' => $this->createForm['reference'] ?: null,
            'notes' => $this->createForm['notes'] ?: null,
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
            'party_id' => $doc->party_id ?? '',
            'reference' => $doc->reference ?? '',
            'issue_date' => $doc->issue_date?->format('Y-m-d') ?? '',
            'due_date' => $doc->due_date?->format('Y-m-d') ?? '',
            'payment_term_id' => $doc->payment_term_id ?? '',
            'notes' => $doc->notes ?? '',
        ];
        $this->editingHeader = true;
    }

    public function saveHeader(): void
    {
        $doc = Document::findOrFail($this->detailId);
        $this->authorize('update', $doc);

        $this->validate([
            'headerForm.party_id' => 'required|exists:parties,id',
            'headerForm.reference' => 'nullable|string|max:255',
            'headerForm.issue_date' => 'required|date',
            'headerForm.due_date' => 'nullable|date',
            'headerForm.payment_term_id' => 'nullable|exists:payment_terms,id',
            'headerForm.notes' => 'nullable|string|max:2000',
        ]);

        $doc->update([
            'party_id' => $this->headerForm['party_id'],
            'reference' => $this->headerForm['reference'] ?: null,
            'issue_date' => $this->headerForm['issue_date'],
            'due_date' => $this->headerForm['due_date'] ?: null,
            'payment_term_id' => $this->headerForm['payment_term_id'] ?: null,
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
    // Line editing (existing lines)
    // -------------------------------------------------------------------------

    public function editLine(string $lineId): void
    {
        $line = DocumentLine::findOrFail($lineId);
        $this->showAddLine = false;
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
    // Add new line
    // -------------------------------------------------------------------------

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
            'quantity' => '1',
            'unit_price' => '',
            'tax_rate' => '15',
        ];
    }

    public function saveNewLine(): void
    {
        $doc = Document::findOrFail($this->detailId);
        $this->authorize('update', $doc);

        $this->validate([
            'newLine.description' => 'nullable|string|max:1000',
            'newLine.account_id' => 'nullable|exists:accounts,id',
            'newLine.quantity' => 'required|numeric|min:0',
            'newLine.unit_price' => 'required|numeric',
            'newLine.tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $nextNumber = $doc->lines()->max('line_number') + 1;

        $doc->lines()->create([
            'line_number' => $nextNumber,
            'type' => 'service',
            'description' => $this->newLine['description'] ?: null,
            'account_id' => $this->newLine['account_id'] ?: null,
            'quantity' => (float) $this->newLine['quantity'],
            'unit_price' => (float) $this->newLine['unit_price'],
            'discount_percent' => 0,
            'discount_amount' => 0,
            'tax_rate' => $this->newLine['tax_rate'] !== '' ? (float) $this->newLine['tax_rate'] : null,
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

    // -------------------------------------------------------------------------
    // Status actions
    // -------------------------------------------------------------------------

    public function openSendModal(): void
    {
        $this->authorize('can-send-sales-invoices');
        $doc = Document::findOrFail($this->detailId);

        $recipients = app(BillingService::class)->resolveRecipients($doc);

        $this->sendRecipients = array_map(
            fn ($r) => ['name' => $r['name'], 'email' => $r['email'], 'selected' => true],
            $recipients,
        );
        $this->sendError = '';
        $this->showSendModal = true;
    }

    public function confirmSend(): void
    {
        $this->authorize('can-send-sales-invoices');
        $doc = Document::findOrFail($this->detailId);

        $emails = array_column(
            array_filter($this->sendRecipients, fn ($r) => $r['selected']),
            'email',
        );

        try {
            app(BillingService::class)->sendInvoice($doc, Auth::user(), $emails);
        } catch (\RuntimeException $e) {
            $this->sendError = $e->getMessage();

            return;
        }

        $this->showSendModal = false;
        $this->sendRecipients = [];
    }

    public function downloadPdf(): mixed
    {
        $doc = Document::findOrFail($this->detailId);
        $this->authorize('view', $doc);

        app(BillingService::class)->generatePdf($doc);
        $doc->refresh();

        $media = $doc->getFirstMedia('invoice_pdf');

        if ($media === null) {
            session()->flash('error', 'Could not generate PDF.');

            return null;
        }

        return response()->download($media->getPath(), 'invoice-'.$doc->document_number.'.pdf');
    }

    public function void(): void
    {
        $this->authorize('can-void-sales-invoices');
        $doc = Document::findOrFail($this->detailId);
        app(DocumentService::class)->voidDocument($doc, Auth::user());
        $this->showVoidConfirm = false;
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
        session()->flash('success', 'Invoice deleted.');
    }

    public function with(): array
    {
        $rows = Document::salesInvoices()
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
        $accounts = collect();
        $paymentTerms = collect();

        if ($this->showDetail && $this->detailId) {
            $detail = Document::with(['party.business', 'paymentTerm'])->findOrFail($this->detailId);
            $lines = $detail->lines()->with('account')->get();
            $accounts = Account::postable()->income()->active()->orderBy('code')->get(['id', 'code', 'name']);
            $paymentTerms = PaymentTerm::orderBy('name')->get(['id', 'name']);
        }

        $currencySymbol = ExchangeRateService::currencySymbol(
            app(CurrencySettings::class)->base_currency
        );

        return [
            'rows' => $rows,
            'statusCounts' => Document::salesInvoices()
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
            'clients' => Party::clients()->with('business')->get(),
            'paymentTerms' => PaymentTerm::orderBy('name')->get(['id', 'name']),
            'detail' => $detail,
            'lines' => $lines,
            'accounts' => $accounts,
            'flyoutPaymentTerms' => $paymentTerms,
            'currencySymbol' => $currencySymbol,
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

{{-- Page header --}}
<div class="flex items-start justify-between px-6 py-5 border-b border-line">
    <div>
        <h1 class="text-[17px] font-semibold tracking-tight text-ink">Sales Invoices</h1>
        <p class="mt-0.5 text-sm text-ink-muted">Client invoices and payment status</p>
    </div>
    @can('create', \App\Modules\Purchasing\Models\Document::class)
        <flux:button wire:click="openCreate" icon="plus" size="sm" variant="primary">
            New Invoice
        </flux:button>
    @endcan
</div>

{{-- Status tabs --}}
<div class="flex items-center gap-1 px-6 pt-4 border-b border-line overflow-x-auto">
    @php
        $tabs = [
            '' => 'All',
            'draft' => 'Draft',
            'sent' => 'Sent',
            'voided' => 'Voided',
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

{{-- Table --}}
<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Number</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Client</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Issue Date</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Due Date</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Total</th>
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
                    <td class="px-4 py-3 text-ink-soft text-xs">
                        @if($invoice->due_date)
                            <span @class([
                                'text-danger font-medium' => $invoice->due_date->isPast() && $invoice->status === 'sent',
                            ])>
                                {{ $invoice->due_date->format('d M Y') }}
                            </span>
                        @else
                            —
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right font-medium text-ink tabular-nums">
                        {{ $currencySymbol }}{{ number_format((float) $invoice->total, 2) }}
                    </td>
                    <td class="px-4 py-3">
                        @php
                            $badgeClass = match($invoice->status) {
                                'draft' => 'bg-surface-alt text-ink-muted',
                                'sent' => 'bg-blue-50 text-blue-700',
                                'voided' => 'bg-red-50 text-danger',
                                default => 'bg-surface-alt text-ink-muted',
                            };
                        @endphp
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $badgeClass }}">
                            {{ ucfirst($invoice->status) }}
                        </span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-12 text-center">
                        <p class="font-medium text-ink">No sales invoices yet.</p>
                        @can('create', \App\Modules\Purchasing\Models\Document::class)
                            <p class="mt-1 text-sm text-ink-muted">Create your first invoice to get started.</p>
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
<flux:modal name="create-modal" wire:model.self="showCreateModal" class="w-[480px]">
    <form wire:submit="createInvoice" class="flex flex-col">
        <div class="p-6 border-b border-line">
            <flux:heading size="lg" class="font-semibold">New Sales Invoice</flux:heading>
        </div>
        <div class="p-6 space-y-4">
            <flux:field>
                <flux:label>Client <span class="text-danger">*</span></flux:label>
                <flux:select wire:model="createForm.party_id">
                    <option value="">— Select client —</option>
                    @foreach($clients as $client)
                        <option value="{{ $client->id }}">{{ $client->business?->display_name }}</option>
                    @endforeach
                </flux:select>
                <flux:error name="createForm.party_id" />
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Issue Date <span class="text-danger">*</span></flux:label>
                    <flux:input type="date" wire:model="createForm.issue_date" />
                    <flux:error name="createForm.issue_date" />
                </flux:field>

                <flux:field>
                    <flux:label>Payment Terms</flux:label>
                    <flux:select wire:model="createForm.payment_term_id">
                        <option value="">— Use client default —</option>
                        @foreach($paymentTerms as $term)
                            <option value="{{ $term->id }}">{{ $term->name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="createForm.payment_term_id" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>Reference</flux:label>
                <flux:input wire:model="createForm.reference" placeholder="Your reference number" />
                <flux:error name="createForm.reference" />
            </flux:field>

            <flux:field>
                <flux:label>Notes</flux:label>
                <flux:textarea wire:model="createForm.notes" rows="2" placeholder="Internal notes…" />
                <flux:error name="createForm.notes" />
            </flux:field>
        </div>
        <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-line bg-surface-alt">
            <flux:button type="button" variant="ghost" wire:click="$set('showCreateModal', false)">Cancel</flux:button>
            <flux:button type="submit" variant="primary">Create Invoice</flux:button>
        </div>
    </form>
</flux:modal>

{{-- ===== Detail Flyout ===== --}}
<flux:modal name="detail-flyout" flyout wire:model.self="showDetail" class="w-[760px]" @close="closeDetail">
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
                        @php
                            $badgeClass = match($detail->status) {
                                'draft' => 'bg-surface-alt text-ink-muted',
                                'sent' => 'bg-blue-50 text-blue-700',
                                'voided' => 'bg-red-50 text-danger',
                                default => 'bg-surface-alt text-ink-muted',
                            };
                        @endphp
                        <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $badgeClass }}">
                            {{ ucfirst($detail->status) }}
                        </span>
                    </div>
                    <p class="text-sm text-ink-soft mt-1">
                        {{ $detail->party?->business?->display_name ?? 'No client' }}
                        @if($detail->issue_date)
                            · {{ $detail->issue_date->format('d M Y') }}
                        @endif
                        @if($detail->reference)
                            · Ref: {{ $detail->reference }}
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    {{-- Edit header (draft only) --}}
                    @if(!$editingHeader && $detail->status === 'draft')
                        @can('update', $detail)
                            <flux:button wire:click="openEditHeader" size="xs" variant="ghost" icon="pencil">Edit</flux:button>
                        @endcan
                    @endif

                    {{-- PDF Download --}}
                    <flux:button wire:click="downloadPdf" size="xs" variant="ghost" icon="arrow-down-tray">PDF</flux:button>

                    {{-- Send --}}
                    @if($detail->status === 'draft')
                        @can('can-send-sales-invoices')
                            <flux:button wire:click="openSendModal" size="xs" variant="ghost" icon="paper-airplane">Send</flux:button>
                        @endcan
                    @endif

                    {{-- Void --}}
                    @if(in_array($detail->status, ['draft', 'sent']))
                        @can('can-void-sales-invoices')
                            <flux:button wire:click="$set('showVoidConfirm', true)" size="xs" variant="ghost" class="text-danger">Void</flux:button>
                        @endcan
                    @endif

                    {{-- Delete (draft only) --}}
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

            {{-- Inline header edit form --}}
            @if($editingHeader)
                <div class="mt-4 pt-4 border-t border-line space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <flux:field>
                            <flux:label>Client <span class="text-danger">*</span></flux:label>
                            <flux:select wire:model="headerForm.party_id">
                                <option value="">— None —</option>
                                @foreach($clients as $client)
                                    <option value="{{ $client->id }}">{{ $client->business?->display_name }}</option>
                                @endforeach
                            </flux:select>
                        </flux:field>
                        <flux:field>
                            <flux:label>Reference</flux:label>
                            <flux:input wire:model="headerForm.reference" placeholder="Reference number" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Issue Date <span class="text-danger">*</span></flux:label>
                            <flux:input type="date" wire:model="headerForm.issue_date" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Due Date</flux:label>
                            <flux:input type="date" wire:model="headerForm.due_date" />
                        </flux:field>
                        <flux:field>
                            <flux:label>Payment Terms</flux:label>
                            <flux:select wire:model="headerForm.payment_term_id">
                                <option value="">— None —</option>
                                @foreach($flyoutPaymentTerms as $term)
                                    <option value="{{ $term->id }}">{{ $term->name }}</option>
                                @endforeach
                            </flux:select>
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
                    <p class="text-lg font-semibold text-ink mt-1">{{ $currencySymbol }}{{ number_format((float) $detail->subtotal, 2) }}</p>
                </div>
                <div class="px-6 py-4">
                    <p class="text-xs text-ink-muted uppercase tracking-wide">Tax</p>
                    <p class="text-lg font-semibold text-ink mt-1">{{ $currencySymbol }}{{ number_format((float) $detail->tax_total, 2) }}</p>
                </div>
                <div class="px-6 py-4">
                    <p class="text-xs text-ink-muted uppercase tracking-wide">Total</p>
                    <p class="text-lg font-semibold text-ink mt-1">{{ $currencySymbol }}{{ number_format((float) $detail->total, 2) }}</p>
                </div>
            </div>

            {{-- Due date info --}}
            @if($detail->due_date)
                <div class="px-6 py-3 border-b border-line bg-surface-alt flex items-center gap-2 text-xs text-ink-muted">
                    <span>Due:</span>
                    <span @class([
                        'font-medium',
                        'text-danger' => $detail->due_date->isPast() && $detail->status === 'sent',
                        'text-ink' => !($detail->due_date->isPast() && $detail->status === 'sent'),
                    ])>
                        {{ $detail->due_date->format('d M Y') }}
                    </span>
                    @if($detail->due_date->isPast() && $detail->status === 'sent')
                        <span class="px-1.5 py-0.5 rounded bg-red-100 text-danger font-medium">overdue</span>
                    @endif
                </div>
            @endif

            {{-- Lines --}}
            <div class="px-6 py-4 border-b border-line">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-ink">Lines</h3>
                    @if($detail->status === 'draft' && !$showAddLine)
                        @can('update', $detail)
                            <flux:button wire:click="openAddLine" size="xs" variant="ghost" icon="plus">Add Line</flux:button>
                        @endcan
                    @endif
                </div>
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
                                @if($detail->status === 'draft')
                                    <th class="w-12"></th>
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
                                        @if($detail->status === 'draft')
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
                                        @endif
                                    </tr>
                                @else
                                    <tr class="border-t border-line group">
                                        <td class="py-2.5 pr-4 text-ink">{{ $line->description ?? '—' }}</td>
                                        <td class="py-2.5 px-4 text-ink-soft">{{ $line->account?->display_name ?? '—' }}</td>
                                        <td class="py-2.5 px-4 text-right text-ink-soft tabular-nums whitespace-nowrap">{{ $line->quantity }}</td>
                                        <td class="py-2.5 px-4 text-right text-ink-soft tabular-nums whitespace-nowrap">{{ $currencySymbol }}{{ number_format((float) $line->unit_price, 4) }}</td>
                                        <td class="py-2.5 px-4 text-right text-ink-soft tabular-nums whitespace-nowrap">
                                            {{ $line->tax_rate !== null ? number_format((float) $line->tax_rate, 2).'%' : '—' }}
                                        </td>
                                        <td class="py-2.5 pl-4 text-right font-medium text-ink tabular-nums whitespace-nowrap">
                                            {{ number_format((float) $line->line_total + (float) $line->tax_amount, 2) }}
                                        </td>
                                        @if($detail->status === 'draft')
                                            <td class="py-2">
                                                <div class="flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                                    <button wire:click="editLine('{{ $line->id }}')" class="text-ink-muted hover:text-ink">
                                                        <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/></svg>
                                                    </button>
                                                    <button
                                                        wire:click="deleteLine('{{ $line->id }}')"
                                                        wire:confirm="Delete this line?"
                                                        class="text-ink-muted hover:text-danger"
                                                    >
                                                        <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                                    </button>
                                                </div>
                                            </td>
                                        @endif
                                    </tr>
                                @endif
                            @endforeach

                            {{-- Add line form --}}
                            @if($showAddLine)
                                <tr class="border-t border-line bg-surface-alt">
                                    <td class="py-2 pr-2">
                                        <input type="text" wire:model="newLine.description" placeholder="Description" class="w-full border border-line rounded px-2 py-1 text-xs" autofocus />
                                    </td>
                                    <td class="py-2 px-2">
                                        <select wire:model="newLine.account_id" class="w-full border border-line rounded px-2 py-1 text-xs">
                                            <option value="">—</option>
                                            @foreach($accounts as $account)
                                                <option value="{{ $account->id }}">{{ $account->code }} {{ $account->name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td class="py-2 px-2">
                                        <input type="number" wire:model="newLine.quantity" step="0.0001" class="w-20 border border-line rounded px-2 py-1 text-xs text-right" />
                                    </td>
                                    <td class="py-2 px-2">
                                        <input type="number" wire:model="newLine.unit_price" step="0.0001" placeholder="0.00" class="w-24 border border-line rounded px-2 py-1 text-xs text-right" />
                                    </td>
                                    <td class="py-2 px-2">
                                        <input type="number" wire:model="newLine.tax_rate" step="0.01" min="0" max="100" class="w-16 border border-line rounded px-2 py-1 text-xs text-right" />
                                    </td>
                                    <td class="py-2 pl-2 text-right text-ink-muted text-xs whitespace-nowrap">—</td>
                                    <td class="py-2">
                                        <div class="flex gap-1">
                                            <button wire:click="saveNewLine" class="text-success hover:text-green-700">
                                                <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/></svg>
                                            </button>
                                            <button wire:click="cancelAddLine" class="text-ink-muted hover:text-ink">
                                                <svg class="w-4 h-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            @endif

                            @if($lines->isEmpty() && !$showAddLine)
                                <tr>
                                    <td colspan="7" class="py-6 text-center text-xs text-ink-muted">No lines yet.</td>
                                </tr>
                            @endif
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Notes --}}
            @if($detail->notes)
                <div class="px-6 py-4 border-b border-line">
                    <p class="text-xs font-medium text-ink-muted uppercase tracking-wide mb-1">Notes</p>
                    <p class="text-sm text-ink">{{ $detail->notes }}</p>
                </div>
            @endif
        </div>
    </div>
    @endif
</flux:modal>

{{-- ===== Send Invoice Modal ===== --}}
<flux:modal name="send-invoice" wire:model.self="showSendModal" class="w-[480px]">
    <div class="p-6">
        <flux:heading size="lg" class="font-semibold mb-1">Send Invoice</flux:heading>
        <p class="text-sm text-ink-soft mb-5">
            A PDF will be generated and emailed to the selected recipients. The invoice will be marked as sent.
        </p>

        @if($sendError)
            <div class="mb-4 px-4 py-3 rounded bg-red-50 border border-red-200 text-sm text-danger">
                {{ $sendError }}
            </div>
        @endif

        @if(count($sendRecipients) > 0)
            <div class="mb-5">
                <p class="text-xs font-semibold text-ink-muted uppercase tracking-wide mb-2">Recipients</p>
                <div class="space-y-2">
                    @foreach($sendRecipients as $i => $recipient)
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input
                                type="checkbox"
                                wire:model="sendRecipients.{{ $i }}.selected"
                                class="rounded border-line text-primary focus:ring-primary"
                            />
                            <span class="text-sm">
                                <span class="font-medium">{{ $recipient['name'] }}</span>
                                <span class="text-ink-muted ml-1">{{ $recipient['email'] }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>
            </div>
        @else
            <div class="mb-5 px-4 py-3 rounded bg-surface-alt border border-line text-sm text-ink-muted">
                No contacts with "receives invoices" enabled found. Add contacts to the client with that flag set.
            </div>
        @endif

        <div class="flex gap-3 justify-end">
            <flux:button variant="ghost" wire:click="$set('showSendModal', false)">Cancel</flux:button>
            <flux:button
                variant="primary"
                wire:click="confirmSend"
                :disabled="count(array_filter($sendRecipients, fn($r) => $r['selected'])) === 0"
            >
                Send Invoice
            </flux:button>
        </div>
    </div>
</flux:modal>

{{-- ===== Void Confirm ===== --}}
<flux:modal name="void-confirm" wire:model.self="showVoidConfirm" class="w-[400px]">
    <div class="p-6">
        <flux:heading size="lg" class="font-semibold mb-2">Void Invoice?</flux:heading>
        <p class="text-sm text-ink-soft mb-6">This will permanently void the invoice. This cannot be undone.</p>
        <div class="flex gap-3 justify-end">
            <flux:button variant="ghost" wire:click="$set('showVoidConfirm', false)">Cancel</flux:button>
            <flux:button variant="danger" wire:click="void">Void Invoice</flux:button>
        </div>
    </div>
</flux:modal>

{{-- ===== Delete Confirm ===== --}}
<flux:modal name="delete-confirm" wire:model.self="showDeleteConfirm" class="w-[400px]">
    <div class="p-6">
        <flux:heading size="lg" class="font-semibold mb-2">Delete Invoice?</flux:heading>
        <p class="text-sm text-ink-soft mb-6">This will permanently delete the draft invoice and all its lines.</p>
        <div class="flex gap-3 justify-end">
            <flux:button variant="ghost" wire:click="$set('showDeleteConfirm', false)">Cancel</flux:button>
            <flux:button variant="danger" wire:click="confirmDelete">Delete</flux:button>
        </div>
    </div>
</flux:modal>

</div>
