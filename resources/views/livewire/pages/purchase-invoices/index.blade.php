<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentLine;
use App\Modules\Purchasing\Services\DocumentService;
use App\Modules\Purchasing\Services\ExchangeRateService;
use App\Modules\Purchasing\Services\PaymentNotificationMatcher;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('components.layout.app')] class extends Component
{
    use WithPagination, WithFileUploads;

    // List filters
    public string $search = '';

    public string $statusFilter = '';

    public string $supplierFilter = '';

    public string $paymentFilter = '';

    // Upload modal
    public bool $showUpload = false;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploadFiles = [];

    public bool $uploadDone = false;

    public int $uploadedCount = 0;

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

    // Payment recording
    public bool $showPaymentModal = false;

    /** @var array<string, mixed> */
    public array $paymentForm = [
        'amount' => '',
        'date' => '',
        'reference' => '',
        'finalise_rate' => false,
        'contra_account_id' => '',
    ];

    // Bulk selection
    /** @var array<int, string> */
    public array $selectedIds = [];

    // Header editing
    public bool $editingHeader = false;

    /** @var array<string, mixed> */
    public array $headerForm = [];

    public function mount(): void
    {
        $this->authorize('viewAny', Document::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        $this->selectedIds = [];
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
        $this->selectedIds = [];
    }

    public function updatedSupplierFilter(): void
    {
        $this->resetPage();
        $this->selectedIds = [];
    }

    public function updatedPaymentFilter(): void
    {
        $this->resetPage();
        $this->selectedIds = [];
    }

    // -------------------------------------------------------------------------
    // Upload
    // -------------------------------------------------------------------------

    public function openUpload(): void
    {
        $this->authorize('create', Document::class);
        $this->reset(['uploadFiles']);
        $this->uploadDone = false;
        $this->uploadedCount = 0;
        $this->showUpload = true;
    }

    public function resetForMore(): void
    {
        $this->authorize('create', Document::class);
        $this->reset(['uploadFiles']);
        $this->uploadDone = false;
        $this->uploadedCount = 0;
    }

    public function processUpload(): void
    {
        $this->authorize('create', Document::class);

        // Size only — MIME is not validated here because folder uploads include
        // unsupported files (.DS_Store etc.) that we silently skip in the loop.
        $this->validate([
            'uploadFiles'   => 'required|array|min:1',
            'uploadFiles.*' => 'file|max:51200',
        ]);

        // Uploaded files are dropped into the same watch folder invoices:watch
        // polls — a single ingestion path for every invoice regardless of
        // source, no party/currency hint (content-driven, exactly like a
        // manually dropped file).
        $folder = (string) config('documents.watch.folder', storage_path('app/invoice-watch'));

        // A relative INVOICE_WATCH_DIR must resolve against the project root,
        // not this request's working directory (which for a web request is
        // typically the public/ document root, not the project root).
        if (! str_starts_with($folder, '/')) {
            $folder = base_path($folder);
        }

        $folder = rtrim($folder, '/');

        if (! is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        // Expand ZIPs and collect all file paths to drop into the watch folder.
        $paths = [];
        $extractDirs = [];

        foreach ($this->uploadFiles as $file) {
            $ext = strtolower($file->getClientOriginalExtension());

            if ($ext === 'zip') {
                $zip = new \ZipArchive();

                if ($zip->open($file->getRealPath()) === true) {
                    // Reject Zip Slip: entry names with ../ or absolute paths
                    // would escape the target directory on extractTo().
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $entryName = $zip->getNameIndex($i);
                        if ($entryName !== false && (str_contains($entryName, '..') || str_starts_with($entryName, '/'))) {
                            $zip->close();
                            $this->addError('uploadFiles', 'ZIP contains unsafe file paths and was rejected.');
                            return;
                        }
                    }

                    $dir = sys_get_temp_dir().'/merlin-zip-'.uniqid();
                    mkdir($dir, 0700, true);
                    $zip->extractTo($dir);
                    $zip->close();
                    $extractDirs[] = $dir;

                    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)) as $entry) {
                        if ($entry->isFile() && in_array(strtolower($entry->getExtension()), ['pdf', 'docx', 'xlsx', 'csv'])) {
                            $paths[] = ['name' => $entry->getFilename(), 'path' => $entry->getPathname()];
                        }
                    }
                }
            } elseif (in_array($ext, ['pdf', 'docx', 'xlsx', 'csv'])) {
                $paths[] = ['name' => $file->getClientOriginalName(), 'path' => $file->getRealPath()];
            }
            // Unsupported types (e.g. .DS_Store from folder upload) are silently skipped.
        }

        if (count($paths) > 50) {
            $this->addError('uploadFiles', 'Maximum 50 files per batch. This batch contains '.count($paths).' files.');

            return;
        }

        foreach ($paths as $entry) {
            $destination = $folder.'/'.$entry['name'];

            if (file_exists($destination)) {
                // Avoid clobbering an existing dropped/unprocessed file with the same name.
                $stem = pathinfo($entry['name'], PATHINFO_FILENAME);
                $ext = pathinfo($entry['name'], PATHINFO_EXTENSION);
                $destination = $folder.'/'.$stem.'-'.uniqid().'.'.$ext;
            }

            copy($entry['path'], $destination);
        }

        foreach ($extractDirs as $dir) {
            app(\Illuminate\Filesystem\Filesystem::class)->deleteDirectory($dir);
        }

        $this->uploadedCount = count($paths);
        $this->uploadDone = true;
        $this->reset(['uploadFiles']);
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
    // Payment notification linking
    // -------------------------------------------------------------------------

    public function confirmSuggestedMatch(string $paymentNotificationId): void
    {
        $paymentNotification = Document::where('document_type', 'payment_notification')->findOrFail($paymentNotificationId);
        $invoiceId = $paymentNotification->metadata['suggested_invoice_id'] ?? null;

        abort_unless($invoiceId, 404);

        $invoice = Document::purchaseInvoices()->findOrFail($invoiceId);
        $this->authorize('update', $invoice);

        app(PaymentNotificationMatcher::class)->merge(
            $invoice,
            $paymentNotification,
            (float) ($paymentNotification->metadata['match_confidence'] ?? 0),
            (string) ($paymentNotification->metadata['match_reason'] ?? ''),
        );

        session()->flash('success', 'Payment notification matched to invoice.');
    }

    public function dismissSuggestedMatch(string $paymentNotificationId): void
    {
        $paymentNotification = Document::where('document_type', 'payment_notification')->findOrFail($paymentNotificationId);
        $this->authorize('update', $paymentNotification);

        $metadata = $paymentNotification->metadata ?? [];
        unset($metadata['suggested_invoice_id'], $metadata['match_confidence'], $metadata['match_reason']);
        $paymentNotification->update(['metadata' => $metadata ?: null]);
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

    // -------------------------------------------------------------------------
    // Payment recording
    // -------------------------------------------------------------------------

    public function openPaymentModal(): void
    {
        $this->authorize('can-record-payments');
        $doc = Document::findOrFail($this->detailId);

        $this->paymentForm = [
            'amount' => number_format((float) $doc->balance_due, 2, '.', ''),
            'date' => now()->toDateString(),
            'reference' => '',
            // Default to finalising when the FX rate is still provisional —
            // the actual amount paid fixes the true cost.
            'finalise_rate' => (bool) ($doc->is_foreign_currency && $doc->exchange_rate_provisional),
            'contra_account_id' => app(\App\Modules\Billing\Settings\BillingSettings::class)->default_contra_account_id ?? '',
        ];
        $this->showPaymentModal = true;
    }

    public function submitPayment(): void
    {
        $this->validate([
            'paymentForm.amount' => 'required|numeric|min:0.01',
            'paymentForm.date' => 'required|date',
            'paymentForm.reference' => 'nullable|string|max:255',
            'paymentForm.finalise_rate' => 'boolean',
            'paymentForm.contra_account_id' => 'nullable|uuid|exists:accounts,id',
        ]);

        $this->authorize('can-record-payments');
        $doc = Document::findOrFail($this->detailId);

        try {
            app(DocumentService::class)->recordPurchasePayment($doc, [
                'amount' => (float) $this->paymentForm['amount'],
                'date' => $this->paymentForm['date'],
                'reference' => $this->paymentForm['reference'] ?: null,
                'finalise_rate' => (bool) $this->paymentForm['finalise_rate'],
                'contra_account_id' => $this->paymentForm['contra_account_id'] ?: null,
            ], Auth::user());
        } catch (\InvalidArgumentException $e) {
            $this->addError('paymentForm.amount', $e->getMessage());

            return;
        }

        $this->showPaymentModal = false;
    }

    // -------------------------------------------------------------------------
    // Quick row actions
    // -------------------------------------------------------------------------

    public function quickMarkReviewed(string $id): void
    {
        $this->authorize('can-review-invoices');
        $doc = Document::findOrFail($id);

        if (! in_array($doc->status, ['received', 'disputed'])) {
            return;
        }

        app(DocumentService::class)->markAsReviewed($doc, Auth::user());
    }

    public function quickApprove(string $id): void
    {
        $this->authorize('can-authorise-invoices');
        $doc = Document::findOrFail($id);

        if (in_array($doc->status, ['approved', 'posted', 'partially_paid', 'paid', 'rejected'])) {
            return;
        }

        app(DocumentService::class)->approve($doc, Auth::user());
    }

    public function quickPost(string $id): void
    {
        $this->authorize('can-post-invoices');
        $doc = Document::findOrFail($id);

        if (in_array($doc->status, ['posted', 'partially_paid', 'paid', 'rejected'])) {
            return;
        }

        app(DocumentService::class)->post($doc, Auth::user());
    }

    // -------------------------------------------------------------------------
    // Bulk actions
    // -------------------------------------------------------------------------

    public function bulkMarkReviewed(): void
    {
        $this->authorize('can-review-invoices');
        $svc = app(DocumentService::class);
        $count = 0;

        foreach (Document::purchaseInvoices()->whereIn('id', $this->selectedIds)->get() as $doc) {
            try {
                $svc->markAsReviewed($doc, Auth::user());
                $count++;
            } catch (\Throwable) {
            }
        }

        $this->selectedIds = [];
        session()->flash('success', "{$count} invoice(s) marked as reviewed.");
    }

    public function bulkApprove(): void
    {
        $this->authorize('can-authorise-invoices');
        $svc = app(DocumentService::class);
        $count = 0;

        foreach (Document::purchaseInvoices()->whereIn('id', $this->selectedIds)->get() as $doc) {
            try {
                $svc->approve($doc, Auth::user());
                $count++;
            } catch (\Throwable) {
            }
        }

        $this->selectedIds = [];
        session()->flash('success', "{$count} invoice(s) approved.");
    }

    public function bulkPost(): void
    {
        $this->authorize('can-post-invoices');
        $svc = app(DocumentService::class);
        $count = 0;

        foreach (Document::purchaseInvoices()->whereIn('id', $this->selectedIds)->get() as $doc) {
            try {
                $svc->post($doc, Auth::user());
                $count++;
            } catch (\Throwable) {
            }
        }

        $this->selectedIds = [];
        session()->flash('success', "{$count} invoice(s) posted.");
    }

    public function with(): array
    {
        $rows = Document::purchaseInvoices()
            ->with(['party.business', 'lines.account', 'media'])
            ->when($this->search, fn ($q) => $q->where(function ($q): void {
                $q->where('document_number', 'like', "%{$this->search}%")
                    ->orWhere('reference', 'like', "%{$this->search}%")
                    ->orWhereHas('party.business', fn ($b) => $b->where('trading_name', 'like', "%{$this->search}%")
                        ->orWhere('legal_name', 'like', "%{$this->search}%")
                    );
            }))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->supplierFilter, fn ($q) => $q->where('party_id', $this->supplierFilter))
            ->when($this->paymentFilter, fn ($q) => match ($this->paymentFilter) {
                'unpaid' => $q->where('status', 'posted')->where('balance_due', '>', 0),
                'partially_paid' => $q->where('status', 'partially_paid'),
                'paid' => $q->where('status', 'paid'),
                default => $q,
            })
            ->latest('issue_date')
            ->latest('created_at')
            ->paginate(25);

        // Only suppliers that actually have purchase invoices — never the full
        // supplier list, so the filter can't offer an option with zero results.
        $supplierFilterOptions = Party::query()
            ->whereIn('id', Document::purchaseInvoices()->whereNotNull('party_id')->distinct()->pluck('party_id'))
            ->with('business')
            ->get()
            ->sortBy(fn (Party $party) => $party->displayName);

        $detail = null;
        $lines = collect();
        $activities = collect();
        $accounts = collect();

        $suggestedPaymentNotification = null;

        if ($this->showDetail && $this->detailId) {
            $detail = Document::with(['party.business', 'payableAccount', 'media'])->findOrFail($this->detailId);
            $lines = $detail->lines()->with(['account', 'llmSuggestedAccount'])->get();
            $activities = $detail->activities()->with('user')->get();
            $accounts = Account::postable()->active()->orderBy('code')->get(['id', 'code', 'name']);

            $suggestedPaymentNotification = Document::where('document_type', 'payment_notification')
                ->where('status', 'received')
                ->where('metadata->suggested_invoice_id', $detail->id)
                ->first();
        }

        // The supplier dropdown only renders inside the header edit form — skip
        // the query on every other interaction.
        $needsSuppliers = $this->showDetail && $this->editingHeader;

        return [
            'rows' => $rows,
            'statusCounts' => Document::purchaseInvoices()
                ->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status'),
            'suppliers' => $needsSuppliers ? Party::suppliers()->with('business')->get() : collect(),
            'supplierFilterOptions' => $supplierFilterOptions,
            'detail' => $detail,
            'lines' => $lines,
            'activities' => $activities,
            'accounts' => $accounts,
            'suggestedPaymentNotification' => $suggestedPaymentNotification,
            'baseCurrency' => app(CurrencySettings::class)->base_currency,
            'baseCurrencySymbol' => ExchangeRateService::currencySymbol(app(CurrencySettings::class)->base_currency),
            'detailCurrencySymbol' => $detail ? ExchangeRateService::currencySymbol($detail->currency) : '',
            'canPost' => Auth::user()->can('can-post-invoices'),
            'canApprove' => Auth::user()->can('can-authorise-invoices'),
            'canReview' => Auth::user()->can('can-review-invoices'),
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
    @can('create', \App\Modules\Core\Models\Document::class)
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
            'queued' => 'Queued',
            'received' => 'Received',
            'reviewed' => 'Reviewed',
            'approved' => 'Approved',
            'posted' => 'Posted',
            'partially_paid' => 'Part Paid',
            'paid' => 'Paid',
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
<div class="px-6 py-3 border-b border-line bg-surface-alt flex items-center gap-3">
    <flux:input
        wire:model.live.debounce.300ms="search"
        placeholder="Search invoices…"
        size="sm"
        icon="magnifying-glass"
        class="max-w-xs"
    />
    <flux:select wire:model.live="supplierFilter" size="sm" class="max-w-xs">
        <option value="">All suppliers</option>
        @foreach($supplierFilterOptions as $supplier)
            <option value="{{ $supplier->id }}">{{ $supplier->displayName }}</option>
        @endforeach
    </flux:select>
    <flux:select wire:model.live="paymentFilter" size="sm" class="max-w-xs">
        <option value="">All payment states</option>
        <option value="unpaid">Unpaid</option>
        <option value="partially_paid">Part Paid</option>
        <option value="paid">Paid</option>
    </flux:select>
</div>

{{-- Bulk action bar --}}
@if(count($selectedIds) > 0)
    <div class="px-6 py-2.5 bg-primary/5 border-b border-line flex items-center gap-3 flex-wrap">
        <span class="text-sm font-medium text-ink">{{ count($selectedIds) }} selected</span>
        @if($canReview)
            <flux:button wire:click="bulkMarkReviewed" size="sm" variant="ghost">Mark Reviewed</flux:button>
        @endif
        @if($canApprove)
            <flux:button wire:click="bulkApprove" size="sm" variant="ghost">Approve</flux:button>
        @endif
        @if($canPost)
            <flux:button wire:click="bulkPost" size="sm" variant="primary">Post</flux:button>
        @endif
        <flux:button wire:click="$set('selectedIds', [])" size="sm" variant="ghost" class="ml-auto">Clear selection</flux:button>
    </div>
@endif

{{-- Invoice table --}}
<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr>
                <th class="px-4 py-3 w-10">
                    <input
                        type="checkbox"
                        x-data
                        :checked="{{ $rows->pluck('id')->toJson() }}.length > 0 && {{ $rows->pluck('id')->toJson() }}.every(id => $wire.selectedIds.includes(id))"
                        @change="$wire.set('selectedIds', $event.target.checked ? {{ $rows->pluck('id')->toJson() }} : [])"
                        class="rounded border-line"
                    />
                </th>
                <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Number</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Supplier</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Date</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Total</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Balance Due</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Accounts</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Status</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Payment</th>
                <th class="px-4 py-3 w-32"></th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $invoice)
                @php
                    $accountNames = $invoice->lines->pluck('account.name')->filter()->unique()->values();
                @endphp
                <tr
                    wire:click="openDetail('{{ $invoice->id }}')"
                    class="border-t border-line hover:bg-surface-alt cursor-pointer"
                >
                    <td class="px-4 py-3 w-10" @click.stop>
                        <input
                            type="checkbox"
                            wire:model.live="selectedIds"
                            value="{{ $invoice->id }}"
                            class="rounded border-line"
                        />
                    </td>
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
                    <td class="px-4 py-3 text-xs text-ink-soft max-w-[180px]">
                        @if($accountNames->isEmpty())
                            <span class="text-ink-muted">—</span>
                        @else
                            {{ $accountNames->take(2)->implode(', ') }}@if($accountNames->count() > 2)<span class="text-ink-muted">, +{{ $accountNames->count() - 2 }} more</span>@endif
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @include('livewire.pages.purchase-invoices._status-badge', ['status' => $invoice->status])
                        @if($invoice->metadata['extraction_failed'] ?? false)
                            <span class="ml-1 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-50 text-danger" title="Automatic extraction failed — open the invoice and use Reprocess to retry.">
                                Extraction failed
                            </span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        @include('livewire.pages.purchase-invoices._payment-badge', ['status' => $invoice->status, 'balanceDue' => $invoice->balance_due])
                    </td>
                    <td class="px-4 py-3 w-32 text-right" @click.stop>
                        <div class="flex items-center justify-end gap-1.5">
                            @if($canPost && !in_array($invoice->status, ['posted', 'partially_paid', 'paid', 'rejected']))
                                <flux:button wire:click="quickPost('{{ $invoice->id }}')" size="xs" variant="primary">Post</flux:button>
                            @elseif($canApprove && !in_array($invoice->status, ['approved', 'posted', 'partially_paid', 'paid', 'rejected']))
                                <flux:button wire:click="quickApprove('{{ $invoice->id }}')" size="xs">Approve</flux:button>
                            @elseif($canReview && in_array($invoice->status, ['received', 'disputed']))
                                <flux:button wire:click="quickMarkReviewed('{{ $invoice->id }}')" size="xs">Review</flux:button>
                            @endif
                            @php $sourceMedia = $invoice->getFirstMedia('source_document'); @endphp
                            @if($sourceMedia)
                                <a
                                    href="{{ route('documents.media', $sourceMedia) }}"
                                    target="_blank"
                                    rel="noopener"
                                    title="View original invoice"
                                    class="inline-flex items-center justify-center rounded p-1 text-ink-muted hover:text-ink hover:bg-surface-alt transition-colors"
                                >
                                    <flux:icon.document-text class="size-4" />
                                </a>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="10" class="px-4 py-12 text-center">
                        <p class="font-medium text-ink">No invoices found.</p>
                        @if(!$statusFilter && !$search && !$supplierFilter && !$paymentFilter)
                            <p class="mt-1 text-sm text-ink-muted">Upload an invoice to get started.</p>
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
<flux:modal name="upload-modal" wire:model.self="showUpload" class="w-[600px]">
    @if($uploadDone)
        {{-- ===== Confirmation view ===== --}}
        <div class="p-6 border-b border-line">
            <flux:heading size="lg" class="font-semibold">Upload Complete</flux:heading>
        </div>
        <div class="p-6">
            <div class="px-4 py-3 rounded bg-green-50 border border-green-200 text-sm text-success">
                {{ $uploadedCount }} file{{ $uploadedCount !== 1 ? 's' : '' }} queued for processing.
            </div>
        </div>
        <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-line bg-surface-alt">
            <flux:button type="button" variant="ghost" wire:click="resetForMore">Upload More</flux:button>
            <flux:button type="button" variant="primary" wire:click="$set('showUpload', false)">Done</flux:button>
        </div>
    @else
        {{-- ===== Upload form ===== --}}
        <form wire:submit="processUpload" class="flex flex-col">
            <div class="p-6 border-b border-line">
                <flux:heading size="lg" class="font-semibold">Upload Invoices</flux:heading>
            </div>
            <div class="p-6 space-y-4">
                <flux:error name="uploadFiles" />

                {{-- Drop zone --}}
                <div
                    x-data="{ names: [], dragging: false, progress: 0 }"
                    x-on:dragover.prevent="dragging = true"
                    x-on:dragleave.prevent="dragging = false"
                    x-on:drop.prevent="
                        dragging = false;
                        const files = $event.dataTransfer.files;
                        names = Array.from(files).map(f => f.name);
                        $wire.uploadMultiple('uploadFiles', files);
                    "
                    x-on:livewire-upload-start.window="progress = 1"
                    x-on:livewire-upload-progress.window="progress = $event.detail.progress"
                    x-on:livewire-upload-finish.window="progress = 0"
                    x-on:livewire-upload-error.window="progress = 0"
                    :class="dragging ? 'border-primary bg-primary/5' : 'border-line bg-surface-alt hover:border-ink-muted'"
                    class="rounded-lg border-2 border-dashed p-8 text-center transition-colors"
                >
                    <flux:icon.arrow-up-tray class="size-8 mx-auto text-ink-muted mb-3" />
                    <p class="text-sm font-medium text-ink">Drop invoices here</p>
                    <p class="text-xs text-ink-muted mt-1 mb-4">PDF, DOCX, XLSX, CSV or ZIP · up to 50 files</p>

                    <div class="flex items-center justify-center gap-3 flex-wrap">
                        <label class="cursor-pointer">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded border border-line bg-white text-xs font-medium text-ink hover:bg-surface-alt transition-colors">
                                <flux:icon.document-text class="size-3.5" />
                                Browse files
                            </span>
                            <input
                                wire:model="uploadFiles"
                                type="file"
                                accept=".pdf,.docx,.xlsx,.csv,.zip"
                                multiple
                                class="sr-only"
                                x-on:change="names = Array.from($event.target.files).map(f => f.name)"
                            />
                        </label>
                        <label class="cursor-pointer">
                            <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded border border-line bg-white text-xs font-medium text-ink hover:bg-surface-alt transition-colors">
                                <flux:icon.folder-open class="size-3.5" />
                                Browse folder
                            </span>
                            <input
                                wire:model="uploadFiles"
                                type="file"
                                webkitdirectory
                                multiple
                                class="sr-only"
                                x-on:change="names = Array.from($event.target.files).map(f => f.name)"
                            />
                        </label>
                    </div>

                    {{-- Upload XHR progress --}}
                    <div x-show="progress > 0" x-cloak class="mt-4 text-xs text-ink-muted">
                        Uploading… <span x-text="progress"></span>%
                    </div>

                    {{-- Selected file list --}}
                    <template x-if="names.length > 0">
                        <div class="mt-4 text-left text-xs text-ink-muted space-y-1 max-h-[96px] overflow-y-auto">
                            <template x-for="(n, i) in names.slice(0, 5)" :key="i">
                                <div x-text="n" class="truncate"></div>
                            </template>
                            <template x-if="names.length > 5">
                                <div>+ <span x-text="names.length - 5"></span> more</div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
            <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-line bg-surface-alt">
                <flux:button type="button" variant="ghost" wire:click="$set('showUpload', false)">Cancel</flux:button>
                <flux:button
                    type="submit"
                    variant="primary"
                    wire:loading.attr="disabled"
                    wire:target="uploadFiles,processUpload"
                >
                    <span wire:loading.remove wire:target="processUpload">Upload & Process</span>
                    <span wire:loading wire:target="processUpload">Processing…</span>
                </flux:button>
            </div>
        </form>
    @endif
</flux:modal>

{{-- ===== Detail Flyout ===== --}}
<flux:modal name="detail-flyout" flyout wire:model.self="showDetail" class="w-[720px]" @close="closeDetail">
    @if($detail)
    <div class="flex flex-col h-full">
        {{-- Header --}}
        <div class="p-6 border-b border-line">
            @if($detail->metadata['extraction_failed'] ?? false)
                <div class="mb-4 px-4 py-3 rounded bg-red-50 border border-red-200 text-sm text-danger">
                    Automatic extraction failed — the invoice has no extracted data.
                    @can('can-reprocess-invoices')
                        Use <strong>Reprocess</strong> below to retry.
                    @endcan
                </div>
            @endif

            @if($suggestedPaymentNotification)
                <div class="mb-4 px-4 py-3 rounded bg-amber-50 border border-amber-200 text-sm">
                    <span class="text-ink">
                        Possible payment match ({{ number_format(((float) ($suggestedPaymentNotification->metadata['match_confidence'] ?? 0)) * 100, 0) }}%):
                        {{ $suggestedPaymentNotification->metadata['payee_name'] ?? 'payment notification' }}
                        — {{ $suggestedPaymentNotification->currency }} {{ number_format((float) $suggestedPaymentNotification->total, 2) }}
                    </span>
                    <span class="text-ink-muted block mt-0.5">{{ $suggestedPaymentNotification->metadata['match_reason'] ?? '' }}</span>
                    @can('update', $detail)
                        <div class="mt-2 flex gap-2">
                            <flux:button wire:click="confirmSuggestedMatch('{{ $suggestedPaymentNotification->id }}')" size="xs" variant="primary">Confirm</flux:button>
                            <flux:button wire:click="dismissSuggestedMatch('{{ $suggestedPaymentNotification->id }}')" size="xs" variant="ghost">Dismiss</flux:button>
                        </div>
                    @endcan
                </div>
            @endif
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

            {{-- Files --}}
            @php
                $sourceMedia = $detail->getFirstMedia('source_document');
                $attachmentMedia = $detail->getMedia('attachments');
            @endphp
            @if($sourceMedia || $attachmentMedia->isNotEmpty())
                <div class="px-6 py-4 border-b border-line">
                    <h3 class="text-sm font-semibold text-ink mb-3">Files</h3>
                    <div class="space-y-1.5">
                        @if($sourceMedia)
                            <a
                                href="{{ route('documents.media', $sourceMedia) }}"
                                target="_blank"
                                rel="noopener"
                                class="flex items-center gap-2 text-xs text-ink-soft hover:text-ink"
                            >
                                <flux:icon.document-text class="size-4 text-ink-muted" />
                                <span>{{ $sourceMedia->file_name }}</span>
                                <span class="text-ink-muted">— Original</span>
                            </a>
                        @endif
                        @foreach($attachmentMedia as $media)
                            <a
                                href="{{ route('documents.media', $media) }}"
                                target="_blank"
                                rel="noopener"
                                class="flex items-center gap-2 text-xs text-ink-soft hover:text-ink"
                            >
                                <flux:icon.paper-clip class="size-4 text-ink-muted" />
                                <span>{{ $media->file_name }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

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
                'posted' => ['payment'],
                'partially_paid' => ['payment'],
                'paid' => [],
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
                @if(in_array('payment', $allowedActions) && (float) $detail->balance_due > 0)
                    @can('can-record-payments')
                        <flux:button wire:click="openPaymentModal" size="sm" variant="primary">Record Payment</flux:button>
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

{{-- Payment modal --}}
<flux:modal name="payment-modal" wire:model.self="showPaymentModal" class="w-[420px]">
    <form wire:submit="submitPayment" class="p-6 space-y-4">
        <flux:heading>Record Payment</flux:heading>
        @if($detail)
            <p class="text-sm text-ink-soft">
                Balance due: {{ $detailCurrencySymbol }}{{ number_format((float) ($detail->is_foreign_currency ? $detail->foreign_balance_due : $detail->balance_due), 2) }}
                @if($detail->is_foreign_currency)
                    ({{ $baseCurrencySymbol }}{{ number_format((float) $detail->balance_due, 2) }})
                @endif
            </p>
        @endif
        <flux:field>
            <flux:label>Amount ({{ $baseCurrency }}) <span class="text-danger">*</span></flux:label>
            <flux:input type="number" wire:model="paymentForm.amount" step="0.01" min="0.01" placeholder="0.00" />
            <flux:error name="paymentForm.amount" />
        </flux:field>
        <flux:field>
            <flux:label>Date <span class="text-danger">*</span></flux:label>
            <flux:input type="date" wire:model="paymentForm.date" />
            <flux:error name="paymentForm.date" />
        </flux:field>
        <flux:field>
            <flux:label>Reference</flux:label>
            <flux:input wire:model="paymentForm.reference" placeholder="Bank reference, EFT number, etc." />
            <flux:error name="paymentForm.reference" />
        </flux:field>
        @if($detail && $detail->is_foreign_currency && $detail->exchange_rate_provisional)
            <flux:field variant="inline">
                <flux:checkbox wire:model="paymentForm.finalise_rate" />
                <flux:label>Finalise exchange rate from this payment (full settlement)</flux:label>
            </flux:field>
        @endif
        <div class="flex justify-end gap-3">
            <flux:button type="button" variant="ghost" wire:click="$set('showPaymentModal', false)">Cancel</flux:button>
            <flux:button type="submit" variant="primary">Record Payment</flux:button>
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
