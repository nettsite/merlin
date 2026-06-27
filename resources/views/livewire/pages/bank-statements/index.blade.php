<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Jobs\ProcessBankStatementDocument;
use App\Modules\Core\Models\BankTemplate;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentLine;
use App\Modules\Core\Services\DocumentService;
use App\Modules\Core\Services\Pdf\MagikaService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

new #[Layout('components.layout.app')] class extends Component
{
    use WithPagination, WithFileUploads;

    public string $search = '';

    public string $statusFilter = '';

    // Upload
    public bool $showUpload = false;

    /** @var array<int, \Livewire\Features\SupportFileUploads\TemporaryUploadedFile> */
    public array $uploadFiles = [];

    public string $uploadContraAccountId = '';

    public string $uploadBankTemplateId = '';

    /** @var array<int, array{name: string, status: string, reference: string|null, error: string|null}> */
    public array $uploadResults = [];

    // Detail flyout
    public bool $showDetail = false;

    public ?string $detailId = null;

    // Reprocess modal
    public bool $showReprocess = false;

    public ?string $reprocessId = null;

    public string $reprocessHint = '';

    // Inline line editing
    public ?string $editingLineId = null;

    public string $editingLineAccountId = '';

    public string $editingLineLinkedDocumentId = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Document::class);
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
        $this->reset(['uploadFiles', 'uploadResults', 'uploadContraAccountId', 'uploadBankTemplateId']);
        $this->showUpload = true;
    }

    public function processUpload(): void
    {
        $this->authorize('create', Document::class);

        $this->validate([
            'uploadFiles'          => 'required|array|min:1',
            'uploadFiles.*'        => 'file|max:51200',
            'uploadContraAccountId' => 'required|uuid|exists:accounts,id',
        ]);

        if (Cache::has('anthropic:credit_exhausted')) {
            foreach ($this->uploadFiles as $file) {
                $this->uploadResults[] = [
                    'name'      => $file->getClientOriginalName(),
                    'status'    => 'error',
                    'reference' => null,
                    'error'     => 'Anthropic credit exhausted. Add credits to your Anthropic account before uploading.',
                ];
            }

            return;
        }

        $magika = app(MagikaService::class);

        foreach ($this->uploadFiles as $file) {
            $name = $file->getClientOriginalName();

            try {
                $magika->assertIsSupportedFormat($file->getRealPath());

                $hash = hash_file('sha256', $file->getRealPath());

                $existing = Media::where('collection_name', 'source_document')
                    ->where('custom_properties->sha256', $hash)
                    ->first();

                if ($existing !== null) {
                    /** @var Document $doc */
                    $doc = Document::findOrFail($existing->model_id);
                    $this->uploadResults[] = [
                        'name'      => $name,
                        'status'    => 'duplicate',
                        'reference' => $doc->reference ?? $doc->document_number,
                        'error'     => null,
                    ];

                    continue;
                }

                $templateId = $this->uploadBankTemplateId ?: null;

                $doc = Document::create([
                    'document_type'      => 'bank_statement',
                    'direction'          => 'inbound',
                    'status'             => 'received',
                    'contra_account_id'  => $this->uploadContraAccountId,
                    'bank_template_id'   => $templateId,
                    'requires_review'    => $templateId === null,
                    'source'             => 'upload',
                ]);

                $doc->addMedia($file->getRealPath())
                    ->usingFileName($file->getClientOriginalName())
                    ->withCustomProperties(['sha256' => $hash])
                    ->toMediaCollection('source_document');

                ProcessBankStatementDocument::dispatch($doc);

                $this->uploadResults[] = [
                    'name'      => $name,
                    'status'    => 'queued',
                    'reference' => null,
                    'error'     => null,
                ];
            } catch (\Throwable $e) {
                $this->uploadResults[] = [
                    'name'      => $name,
                    'status'    => 'error',
                    'reference' => null,
                    'error'     => $e->getMessage(),
                ];
            }
        }

        $this->reset(['uploadFiles', 'uploadBankTemplateId']);
    }

    public function resetUploadForMore(): void
    {
        $this->reset(['uploadFiles', 'uploadResults', 'uploadBankTemplateId']);
    }

    // -------------------------------------------------------------------------
    // Detail flyout
    // -------------------------------------------------------------------------

    public function openDetail(string $id): void
    {
        $this->authorize('view', Document::findOrFail($id));
        $this->detailId = $id;
        $this->editingLineId = null;
        $this->editingLineAccountId = '';
        $this->showDetail = true;
    }

    public function closeDetail(): void
    {
        $this->showDetail = false;
        $this->detailId = null;
        $this->editingLineId = null;
    }

    // -------------------------------------------------------------------------
    // Inline line account editing
    // -------------------------------------------------------------------------

    public function editLine(string $lineId): void
    {
        $line = DocumentLine::findOrFail($lineId);
        $this->authorize('update', $line->document);
        $this->editingLineId = $lineId;
        $this->editingLineAccountId = $line->account_id ?? '';
        $this->editingLineLinkedDocumentId = $line->linked_document_id ?? '';
    }

    public function saveLine(): void
    {
        $line = DocumentLine::findOrFail($this->editingLineId);
        $this->authorize('update', $line->document);

        $this->validate([
            'editingLineAccountId'       => 'nullable|uuid|exists:accounts,id',
            'editingLineLinkedDocumentId' => 'nullable|uuid|exists:documents,id',
        ]);

        $line->update([
            'account_id'         => $this->editingLineAccountId ?: null,
            'linked_document_id' => $this->editingLineLinkedDocumentId ?: null,
        ]);

        $this->editingLineId = null;
        $this->editingLineAccountId = '';
        $this->editingLineLinkedDocumentId = '';
    }

    public function cancelEditLine(): void
    {
        $this->editingLineId = null;
        $this->editingLineAccountId = '';
        $this->editingLineLinkedDocumentId = '';
    }

    // -------------------------------------------------------------------------
    // Status transitions
    // -------------------------------------------------------------------------

    public function markReviewed(string $id): void
    {
        $doc = Document::findOrFail($id);
        $this->authorize('update', $doc);
        /** @var \App\Modules\Core\Models\User $user */
        $user = Auth::user();
        app(DocumentService::class)->markAsReviewed($doc, $user);
    }

    public function post(string $id): void
    {
        $doc = Document::findOrFail($id);
        $this->authorize('update', $doc);
        /** @var \App\Modules\Core\Models\User $user */
        $user = Auth::user();
        app(DocumentService::class)->postBankStatement($doc, $user);

        if ($this->detailId === $id) {
            $this->closeDetail();
        }
    }

    public function openReprocess(string $id): void
    {
        $doc = Document::findOrFail($id);
        $this->authorize('update', $doc);

        if ($doc->status === 'posted') {
            $this->addError('reprocess', 'Cannot reprocess a posted statement.');

            return;
        }

        $this->reprocessId = $id;
        $this->reprocessHint = '';
        $this->showReprocess = true;
    }

    public function confirmReprocess(): void
    {
        $doc = Document::findOrFail($this->reprocessId);
        $this->authorize('update', $doc);

        $hint = trim($this->reprocessHint) ?: null;

        $doc->lines()->delete();
        $doc->update([
            'status' => 'received',
            'metadata' => array_merge($doc->metadata ?? [], array_filter(['user_hint' => $hint])),
        ]);
        $doc->activities()->create([
            'activity_type' => 'reprocess_queued',
            'description' => 'Statement queued for reprocessing.' . ($hint ? ' User hint provided.' : ''),
        ]);

        ProcessBankStatementDocument::dispatch($doc, $hint);

        $this->showReprocess = false;
        $this->reprocessId = null;
        $this->reprocessHint = '';
    }

    // -------------------------------------------------------------------------
    // Data
    // -------------------------------------------------------------------------

    public function with(): array
    {
        $statementTypes = ['bank_statement', 'credit_card_statement'];

        $rows = Document::whereIn('document_type', $statementTypes)
            ->when($this->search, function ($q) {
                $q->where(function ($q) {
                    $q->where('reference', 'like', "%{$this->search}%")
                        ->orWhere('document_number', 'like', "%{$this->search}%")
                        ->orWhereJsonContains('metadata->bank_name', $this->search);
                });
            })
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->with(['contraAccount', 'bankTemplate'])
            ->withCount('lines')
            ->orderByDesc('created_at')
            ->paginate(25);

        $detail = $this->detailId
            ? Document::with(['lines.account', 'lines.linkedDocument.party', 'contraAccount', 'bankTemplate', 'media'])
                ->find($this->detailId)
            : null;

        $outstandingInvoices = Document::salesInvoices()
            ->whereIn('status', ['sent', 'partially_paid'])
            ->with('party')
            ->orderBy('document_number')
            ->get();

        return [
            'rows'               => $rows,
            'detail'             => $detail,
            'detailPdfUrl'       => $detail?->getFirstMedia('source_document')?->getUrl(),
            'bankAccounts'       => Account::active()->postable()
                ->where(fn ($q) => $q->where('name', 'like', '%Bank%')
                    ->orWhere('name', 'like', '%Credit Card%')
                    ->orWhere('name', 'like', '%Cash%'))
                ->orderBy('code')->get(),
            'allAccounts'        => Account::active()->postable()->orderBy('code')->get(),
            'bankTemplates'      => BankTemplate::active()->orderBy('name')->get(),
            'outstandingInvoices' => $outstandingInvoices,
            'statuses'           => ['received', 'reviewed', 'posted'],
        ];
    }
}; ?>

<div>
    {{-- Toolbar --}}
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search statements…" icon="magnifying-glass" size="sm" class="w-64" />
            <flux:select wire:model.live="statusFilter" size="sm" class="w-40">
                <option value="">All statuses</option>
                <option value="received">Received</option>
                <option value="reviewed">Reviewed</option>
                <option value="posted">Posted</option>
            </flux:select>
        </div>
        @can('create', \App\Modules\Core\Models\Document::class)
            <flux:button wire:click="openUpload" icon="arrow-up-tray" size="sm" variant="primary">
                Upload Statement
            </flux:button>
        @endcan
    </div>

    {{-- Table --}}
    <div class="bg-surface rounded-lg border border-line overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-surface-alt border-b border-line">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Reference</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Bank Account</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Period</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Transactions</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Net</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Status</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $stmt)
                    <tr class="border-t border-line hover:bg-surface-alt group cursor-pointer" wire:click="openDetail('{{ $stmt->id }}')">
                        <td class="px-4 py-3 font-medium text-ink">
                            {{ $stmt->reference ?? '—' }}
                            @if($stmt->metadata['extraction_failed'] ?? false)
                                <span class="ml-1 text-xs text-danger">(failed)</span>
                            @endif
                            @if($stmt->requires_review && $stmt->status === 'received')
                                <span class="ml-1 text-xs text-warning">(review required)</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-ink-soft">
                            {{ $stmt->contraAccount?->display_name ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-ink-soft tabular-nums text-xs">
                            @if($stmt->metadata['period_from'] ?? null)
                                {{ $stmt->metadata['period_from'] }} to {{ $stmt->metadata['period_to'] ?? '?' }}
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums text-ink-soft">
                            {{ $stmt->lines_count }}
                        </td>
                        <td class="px-4 py-3 text-right tabular-nums font-medium {{ (float)$stmt->total >= 0 ? 'text-success' : 'text-danger' }}">
                            {{ number_format((float)$stmt->total, 2) }}
                        </td>
                        <td class="px-4 py-3">
                            <span @class([
                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                'bg-warning/10 text-warning-600' => $stmt->status === 'received',
                                'bg-info/10 text-info-600' => $stmt->status === 'reviewed',
                                'bg-success/10 text-success-600' => $stmt->status === 'posted',
                            ])>
                                {{ ucfirst($stmt->status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right" wire:click.stop="">
                            <div class="flex items-center justify-end gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
                                @if(in_array($stmt->status, ['received', 'reviewed']))
                                    @can('update', $stmt)
                                        @if($stmt->status === 'received')
                                            <flux:button wire:click="markReviewed('{{ $stmt->id }}')" size="sm" variant="ghost" icon="check">
                                                Review
                                            </flux:button>
                                        @endif
                                        <flux:button
                                            wire:click="post('{{ $stmt->id }}')"
                                            wire:confirm="Post this statement to the general ledger? This cannot be undone."
                                            size="sm" variant="ghost" icon="check-badge"
                                        >
                                            Post
                                        </flux:button>
                                    @endcan
                                @endif
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center">
                            <p class="font-medium text-ink">No bank statements yet.</p>
                            <p class="mt-1 text-sm text-ink-muted">Upload a PDF bank statement to get started.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="px-4 py-3 border-t border-line">
            {{ $rows->links() }}
        </div>
    </div>

    {{-- Upload modal --}}
    <flux:modal wire:model="showUpload" class="w-full max-w-lg">
        <flux:heading size="lg">Upload Bank Statement</flux:heading>
        <flux:subheading>Upload one or more PDF bank statements. Each will be queued for extraction.</flux:subheading>

        @if(count($uploadResults) > 0)
            <div class="mt-4 space-y-1">
                @foreach($uploadResults as $result)
                    <div @class([
                        'flex items-center gap-2 rounded px-3 py-2 text-sm',
                        'bg-success/10 text-success-700' => $result['status'] === 'queued',
                        'bg-warning/10 text-warning-700' => $result['status'] === 'duplicate',
                        'bg-danger/10 text-danger-700' => $result['status'] === 'error',
                    ])>
                        @if($result['status'] === 'queued')
                            <flux:icon.check-circle class="size-4 shrink-0" />
                            <span>{{ $result['name'] }} — queued for extraction</span>
                        @elseif($result['status'] === 'duplicate')
                            <flux:icon.arrow-path class="size-4 shrink-0" />
                            <span>{{ $result['name'] }} — already uploaded ({{ $result['reference'] }})</span>
                        @else
                            <flux:icon.exclamation-circle class="size-4 shrink-0" />
                            <span>{{ $result['name'] }} — {{ $result['error'] }}</span>
                        @endif
                    </div>
                @endforeach
            </div>

            <div class="mt-4 flex gap-2">
                <flux:button wire:click="resetUploadForMore" variant="ghost" size="sm">Upload More</flux:button>
                <flux:button wire:click="$set('showUpload', false)" variant="primary" size="sm">Done</flux:button>
            </div>
        @else
            <div class="mt-4 space-y-4">
                <flux:field>
                    <flux:label>Bank Account <span class="text-danger">*</span></flux:label>
                    <flux:select wire:model="uploadContraAccountId">
                        <option value="">Select bank account…</option>
                        @foreach($bankAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->display_name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:description>The GL account for this bank/credit card.</flux:description>
                    <flux:error name="uploadContraAccountId" />
                </flux:field>

                @if(count($bankTemplates) > 0)
                    <flux:field>
                        <flux:label>Bank Template</flux:label>
                        <flux:select wire:model="uploadBankTemplateId">
                            <option value="">None (manual review required)</option>
                            @foreach($bankTemplates as $template)
                                <option value="{{ $template->id }}">{{ $template->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:description>A saved layout template improves extraction accuracy.</flux:description>
                    </flux:field>
                @endif

                <flux:field>
                    <flux:label>Statement PDF(s) <span class="text-danger">*</span></flux:label>
                    <flux:input wire:model="uploadFiles" type="file" accept=".pdf" multiple />
                    <flux:error name="uploadFiles" />
                    <flux:error name="uploadFiles.*" />
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:button wire:click="$set('showUpload', false)" variant="ghost">Cancel</flux:button>
                    <flux:button wire:click="processUpload" variant="primary" icon="arrow-up-tray">Upload</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- Reprocess modal --}}
    <flux:modal wire:model="showReprocess" class="w-full max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">Reprocess Statement</flux:heading>
            <flux:text>Existing lines will be deleted and re-extracted by the LLM. Optionally add hints to guide the extraction.</flux:text>
            <flux:field>
                <flux:label>Hints for the LLM <flux:badge size="sm" variant="ghost">optional</flux:badge></flux:label>
                <flux:textarea
                    wire:model="reprocessHint"
                    placeholder="e.g. The R3880 payments from Target are monthly retainer fees. Invoice R6478 was issued on 2026-01-22 and covers the January payment."
                    rows="4"
                />
                <flux:description>Plain language notes about specific transactions, clients, or how to interpret ambiguous entries.</flux:description>
            </flux:field>
            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showReprocess', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="confirmReprocess" variant="primary" icon="arrow-path">Reprocess</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Detail flyout --}}
    <flux:modal wire:model="showDetail" class="w-full max-w-4xl" variant="flyout">
        @if($detail)
            <div class="space-y-6">
                {{-- Header --}}
                <div class="flex items-start justify-between">
                    <div>
                        <flux:heading size="lg">
                            {{ $detail->reference ?? 'Bank Statement' }}
                        </flux:heading>
                        <p class="text-sm text-ink-soft mt-1">
                            {{ $detail->contraAccount?->display_name ?? '—' }}
                            @if($detail->metadata['period_from'] ?? null)
                                &bull; {{ $detail->metadata['period_from'] }} to {{ $detail->metadata['period_to'] ?? '?' }}
                            @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <span @class([
                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                            'bg-warning/10 text-warning-600' => $detail->status === 'received',
                            'bg-info/10 text-info-600' => $detail->status === 'reviewed',
                            'bg-success/10 text-success-600' => $detail->status === 'posted',
                        ])>
                            {{ ucfirst($detail->status) }}
                        </span>

                        @if($detailPdfUrl)
                            <flux:button href="{{ $detailPdfUrl }}" target="_blank" size="sm" variant="ghost" icon="document-text">
                                View PDF
                            </flux:button>
                        @endif

                        @can('update', $detail)
                            @if($detail->status !== 'posted')
                                <flux:button
                                    wire:click="openReprocess('{{ $detail->id }}')"
                                    size="sm" variant="ghost" icon="arrow-path"
                                >
                                    Reprocess
                                </flux:button>
                            @endif
                        @endcan

                        @if(in_array($detail->status, ['received', 'reviewed']))
                            @can('update', $detail)
                                @if($detail->status === 'received')
                                    <flux:button wire:click="markReviewed('{{ $detail->id }}')" size="sm" variant="ghost" icon="check">
                                        Mark Reviewed
                                    </flux:button>
                                @endif
                                <flux:button
                                    wire:click="post('{{ $detail->id }}')"
                                    wire:confirm="Post this statement to the general ledger? Lines without an account will be posted uncoded. This cannot be undone."
                                    size="sm" variant="primary" icon="check-badge"
                                >
                                    Post
                                </flux:button>
                            @endcan
                        @endif
                    </div>
                </div>

                {{-- Balances --}}
                @if($detail->metadata['opening_balance'] ?? null)
                    <div class="flex gap-6 text-sm">
                        <div>
                            <span class="text-ink-muted">Opening</span>
                            <span class="ml-2 font-medium tabular-nums">{{ number_format((float)$detail->metadata['opening_balance'], 2) }}</span>
                        </div>
                        <div>
                            <span class="text-ink-muted">Closing</span>
                            <span class="ml-2 font-medium tabular-nums">{{ number_format((float)$detail->metadata['closing_balance'], 2) }}</span>
                        </div>
                        @if($detail->metadata['balance_reconciled'] ?? false)
                            <div class="flex items-center gap-1 text-success text-xs">
                                <flux:icon.check-circle class="size-3.5" />
                                Balanced
                            </div>
                        @else
                            <div class="flex items-center gap-1 text-warning text-xs">
                                <flux:icon.exclamation-triangle class="size-3.5" />
                                Balance mismatch
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Transactions table --}}
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-surface-alt">
                            <tr>
                                <th class="px-3 py-2 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Date</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Description</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Debit</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Credit</th>
                                <th class="px-3 py-2 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Balance</th>
                                <th class="px-3 py-2 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Allocation</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $isCredit = fn($line) => (float)$line->unit_price >= 0; @endphp
                            @forelse($detail->lines as $line)
                                <tr class="border-t border-line hover:bg-surface-alt group">
                                    <td class="px-3 py-2 tabular-nums text-ink-soft text-xs whitespace-nowrap">
                                        {{ $line->metadata['transaction_date'] ?? '—' }}
                                    </td>
                                    <td class="px-3 py-2 text-ink max-w-xs">
                                        <div>{{ $line->description }}</div>
                                        @if($line->metadata['invoice_match_reason'] ?? null)
                                            <div class="text-xs text-ink-muted mt-0.5">{{ $line->metadata['invoice_match_reason'] }}</div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums text-danger">
                                        @if((float)$line->unit_price < 0)
                                            {{ number_format(abs((float)$line->unit_price), 2) }}
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums text-success">
                                        @if((float)$line->unit_price >= 0)
                                            {{ number_format((float)$line->unit_price, 2) }}
                                        @endif
                                    </td>
                                    <td class="px-3 py-2 text-right tabular-nums text-ink-soft text-xs">
                                        {{ $line->metadata['running_balance'] !== null ? number_format((float)$line->metadata['running_balance'], 2) : '—' }}
                                    </td>
                                    <td class="px-3 py-2 min-w-48">
                                        @if($detail->status !== 'posted')
                                            @if($editingLineId === $line->id)
                                                <div class="space-y-1">
                                                    @if($isCredit($line))
                                                        {{-- Credit: allocate to invoice --}}
                                                        <flux:select wire:model="editingLineLinkedDocumentId" size="sm">
                                                            <option value="">— No invoice —</option>
                                                            @foreach($outstandingInvoices as $inv)
                                                                <option value="{{ $inv->id }}">
                                                                    {{ $inv->document_number }} · {{ $inv->party?->displayName }} · {{ number_format((float)$inv->balance_due, 2) }}
                                                                </option>
                                                            @endforeach
                                                        </flux:select>
                                                        <flux:select wire:model="editingLineAccountId" size="sm">
                                                            <option value="">— GL account —</option>
                                                            @foreach($allAccounts as $account)
                                                                <option value="{{ $account->id }}">{{ $account->display_name }}</option>
                                                            @endforeach
                                                        </flux:select>
                                                    @else
                                                        {{-- Debit: GL account only --}}
                                                        <flux:select wire:model="editingLineAccountId" size="sm">
                                                            <option value="">— Unallocated —</option>
                                                            @foreach($allAccounts as $account)
                                                                <option value="{{ $account->id }}">{{ $account->display_name }}</option>
                                                            @endforeach
                                                        </flux:select>
                                                    @endif
                                                    <div class="flex gap-1">
                                                        <flux:button wire:click="saveLine" size="sm" variant="primary" icon="check" />
                                                        <flux:button wire:click="cancelEditLine" size="sm" variant="ghost" icon="x-mark" />
                                                    </div>
                                                </div>
                                            @else
                                                <button wire:click="editLine('{{ $line->id }}')" class="text-left w-full">
                                                    @if($isCredit($line) && $line->linkedDocument)
                                                        <span class="text-xs font-medium text-ink">{{ $line->linkedDocument->document_number }}</span>
                                                        <span class="text-xs text-ink-muted ml-1">{{ $line->linkedDocument->party?->displayName }}</span>
                                                    @elseif($line->account)
                                                        <span class="text-xs text-ink-soft">{{ $line->account->display_name }}</span>
                                                    @else
                                                        <span class="text-xs text-ink-muted italic">Click to allocate…</span>
                                                    @endif
                                                </button>
                                            @endif
                                        @else
                                            {{-- Posted: read-only --}}
                                            @if($isCredit($line) && $line->linkedDocument)
                                                <span class="text-xs font-medium text-ink">{{ $line->linkedDocument->document_number }}</span>
                                                <span class="text-xs text-ink-muted ml-1">{{ $line->linkedDocument->party?->displayName }}</span>
                                            @else
                                                <span class="text-xs text-ink-soft">{{ $line->account?->display_name ?? '—' }}</span>
                                            @endif
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="px-3 py-8 text-center text-ink-muted text-sm">
                                        No transactions extracted yet.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
