<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\FinancialYearService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('components.layout.app')] class extends Component
{
    use WithPagination;

    public string $accountId = '';

    public string $sortBy = 'issue_date';
    public string $sortDir = 'desc';

    public string $dateFrom = '';
    public string $dateTo = '';
    public string $contraAccountId = '';

    public function mount(string $id): void
    {
        $account = Account::findOrFail($id);
        $this->authorize('view', $account);
        $this->accountId = $id;

        $fyService = app(FinancialYearService::class);
        [$fyStart, $fyEnd] = $fyService->yearBounds($fyService->currentYearLabel());
        $this->dateFrom = $fyStart->format('Y-m-d');
        $this->dateTo = $fyEnd->format('Y-m-d');
    }

    public function updated($property): void
    {
        if (in_array($property, ['dateFrom', 'dateTo', 'contraAccountId'], true)) {
            $this->resetPage();
        }
    }

    public function sort(string $column): void
    {
        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }

        $this->resetPage();
    }

    /**
     * Builds the transaction register for the current account directly from
     * the postings view — one query in place of the old 6-way UNION across
     * raw document headers/lines. Pass $filterByContra = false to compute
     * the set of contra accounts actually present in the (date-filtered)
     * dataset, so the filter dropdown never offers an option that would
     * return zero rows.
     *
     * "Contra account" only has a single answer when a document's postings
     * touch exactly one other account (the common case: a payment, or a
     * purchase invoice with one expense account against its AP total). A
     * document touching more accounts (e.g. a sales invoice spanning
     * several income accounts, or one that also has a VAT leg) has no
     * single contra, shown as "Various" — same as the old header rows.
     * Grouped by document_id, not a per-row id (the view has none) — every
     * document_id is one accounting event, the same grouping the deleted
     * journal_entries row for that document used to represent.
     */
    private function buildTransactionQuery(bool $filterByContra): \Illuminate\Database\Query\Builder
    {
        $partyName = 'COALESCE(businesses.legal_name, CONCAT(persons.first_name, \' \', persons.last_name))';

        // Correlated subquery: the one other account touched by this
        // document's postings, or NULL when there isn't exactly one.
        $contraSubquery = 'select p3.account_id from postings p3
            where p3.document_id = postings.document_id
                and p3.account_id != postings.account_id
                and (
                    select count(distinct p2.account_id) from postings p2
                    where p2.document_id = postings.document_id
                ) = 2
            limit 1';

        return DB::table('postings')
            ->leftJoin('documents', 'documents.id', '=', 'postings.document_id')
            ->leftJoin('businesses', 'businesses.id', '=', 'documents.party_id')
            ->leftJoin('persons', 'persons.id', '=', 'documents.party_id')
            ->leftJoin('accounts as contra_accounts', function ($join) use ($contraSubquery) {
                $join->on('contra_accounts.id', '=', DB::raw("({$contraSubquery})"));
            })
            ->where('postings.account_id', $this->accountId)
            ->when($this->dateFrom, fn ($q) => $q->whereDate('postings.entry_date', '>=', $this->dateFrom))
            ->when($this->dateTo, fn ($q) => $q->whereDate('postings.entry_date', '<=', $this->dateTo))
            ->when($filterByContra && $this->contraAccountId, fn ($q) => $q->whereRaw("({$contraSubquery}) = ?", [$this->contraAccountId]))
            ->selectRaw("
                postings.source,
                postings.entry_date as issue_date,
                documents.id as document_id,
                documents.document_number,
                documents.document_type,
                documents.status as document_status,
                {$partyName} as party_name,
                postings.description as description,
                (case when postings.debit > 0 then postings.debit else postings.credit end) as amount,
                contra_accounts.id as contra_account_id,
                contra_accounts.name as contra_account_name
            ");
    }

    public function with(): array
    {
        $account = Account::with('group.type')->findOrFail($this->accountId);

        // Only ever offer contra accounts actually present in the current (date-filtered)
        // dataset — never the full chart of accounts. Computed without the contra filter
        // itself applied, so selecting one option doesn't hide its siblings.
        $availableContraAccountIds = DB::query()
            ->fromSub($this->buildTransactionQuery(filterByContra: false), 'contra_candidates')
            ->whereNotNull('contra_account_id')
            ->distinct()
            ->pluck('contra_account_id');
        $contraAccounts = Account::whereIn('id', $availableContraAccountIds)->orderBy('code')->get(['id', 'code', 'name']);

        $sortColumn = match ($this->sortBy) {
            'document_number' => 'document_number',
            'type' => 'document_type',
            'amount' => 'amount',
            'contra_account' => 'contra_account_name',
            default => 'issue_date',
        };

        $lines = $this->buildTransactionQuery(filterByContra: true)
            ->orderBy($sortColumn, $this->sortDir)
            ->paginate(25);

        return compact('account', 'lines', 'contraAccounts');
    }
}; ?>

<div>
    {{-- Header --}}
    <div class="border-b border-line px-6 py-5">
        <a href="{{ route('accounts.index') }}" wire:navigate class="mb-3 flex items-center gap-1 text-sm text-ink-muted hover:text-ink">
            <flux:icon.arrow-left class="size-4" />
            Accounts
        </a>
        <div class="flex items-start justify-between gap-4">
            <div class="flex items-center gap-3">
                <span class="font-mono text-sm text-ink-muted">{{ $account->code }}</span>
                <h1 class="text-[17px] font-semibold tracking-tight text-ink">{{ $account->name }}</h1>
                <span @class([
                    'inline-flex items-center px-2 py-0.5 rounded text-xs font-medium',
                    'bg-green-50 text-success' => $account->is_active,
                    'bg-surface-alt text-ink-muted' => !$account->is_active,
                ])>
                    {{ $account->is_active ? 'Active' : 'Inactive' }}
                </span>
            </div>
        </div>
    </div>

    {{-- Account meta --}}
    <div class="grid grid-cols-2 gap-x-8 gap-y-4 border-b border-line px-6 py-5 sm:grid-cols-4">
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-ink-muted">Group</p>
            <p class="mt-1 text-sm text-ink">{{ $account->group?->name ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-ink-muted">Type</p>
            <p class="mt-1 text-sm text-ink">{{ $account->group?->type?->name ?? '—' }}</p>
        </div>
        <div>
            <p class="text-xs font-medium uppercase tracking-wide text-ink-muted">Direct Posting</p>
            <p class="mt-1 text-sm text-ink">{{ $account->allow_direct_posting ? 'Allowed' : 'No' }}</p>
        </div>
        @if($account->description)
            <div class="col-span-2 sm:col-span-4">
                <p class="text-xs font-medium uppercase tracking-wide text-ink-muted">Description</p>
                <p class="mt-1 text-sm text-ink">{{ $account->description }}</p>
            </div>
        @endif
    </div>

    {{-- Transactions --}}
    <div>
        <div class="flex flex-wrap items-end justify-between gap-4 border-b border-line px-6 py-4">
            <div>
                <h2 class="text-sm font-semibold text-ink">Transactions</h2>
                <p class="mt-0.5 text-xs text-ink-muted">All line items and document totals posted to this account</p>
            </div>
            <div class="flex items-end gap-3">
                <flux:input wire:model.live="dateFrom" type="date" size="sm" label="From" />
                <flux:input wire:model.live="dateTo" type="date" size="sm" label="To" />
                <flux:field>
                    <flux:label>Contra account</flux:label>
                    <flux:select wire:model.live="contraAccountId" size="sm" class="w-48">
                        <option value="">All accounts</option>
                        @foreach($contraAccounts as $contraAccount)
                            <option value="{{ $contraAccount->id }}">{{ $contraAccount->code }} - {{ $contraAccount->name }}</option>
                        @endforeach
                    </flux:select>
                </flux:field>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr>
                        <th wire:click="sort('issue_date')" class="cursor-pointer px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-ink-muted hover:text-ink">
                            Date
                            @if($sortBy === 'issue_date')
                                <flux:icon.{{ $sortDir === 'asc' ? 'chevron-up' : 'chevron-down' }} class="inline size-3" />
                            @endif
                        </th>
                        <th wire:click="sort('document_number')" class="cursor-pointer px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-ink-muted hover:text-ink">
                            Document
                            @if($sortBy === 'document_number')
                                <flux:icon.{{ $sortDir === 'asc' ? 'chevron-up' : 'chevron-down' }} class="inline size-3" />
                            @endif
                        </th>
                        <th wire:click="sort('type')" class="cursor-pointer px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-ink-muted hover:text-ink">
                            Type
                            @if($sortBy === 'type')
                                <flux:icon.{{ $sortDir === 'asc' ? 'chevron-up' : 'chevron-down' }} class="inline size-3" />
                            @endif
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-ink-muted">Party</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-ink-muted">Description</th>
                        <th wire:click="sort('contra_account')" class="cursor-pointer px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-ink-muted hover:text-ink">
                            Contra Account
                            @if($sortBy === 'contra_account')
                                <flux:icon.{{ $sortDir === 'asc' ? 'chevron-up' : 'chevron-down' }} class="inline size-3" />
                            @endif
                        </th>
                        <th wire:click="sort('amount')" class="cursor-pointer px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-ink-muted hover:text-ink">
                            Amount
                            @if($sortBy === 'amount')
                                <flux:icon.{{ $sortDir === 'asc' ? 'chevron-up' : 'chevron-down' }} class="inline size-3" />
                            @endif
                        </th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($lines as $line)
                        <tr class="border-t border-line hover:bg-surface-alt">
                            <td class="px-4 py-3 tabular-nums text-ink-soft">
                                {{ $line->issue_date ? \Carbon\Carbon::parse($line->issue_date)->format('d M Y') : '—' }}
                            </td>
                            <td class="px-4 py-3 font-medium text-ink">
                                {{ $line->document_number ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-ink-soft">
                                {{ ucwords(str_replace('_', ' ', $line->document_type ?? '')) }}
                            </td>
                            <td class="px-4 py-3 text-ink-soft">
                                {{ $line->party_name ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-ink-soft">
                                {{ $line->description ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-ink-soft">
                                {{ $line->contra_account_name ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-right tabular-nums font-medium text-ink">
                                {{ number_format((float) $line->amount, 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-12 text-center text-sm text-ink-muted">
                                No transactions found for this account.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($lines->hasPages())
            <div class="border-t border-line px-6 py-4">
                {{ $lines->links() }}
            </div>
        @endif
    </div>
</div>
