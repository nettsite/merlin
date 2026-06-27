<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\DocumentLine;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layout.app')] class extends Component
{
    use WithPagination;

    public string $accountId = '';

    public string $sortBy = 'issue_date';
    public string $sortDir = 'desc';

    public function mount(string $id): void
    {
        $account = Account::findOrFail($id);
        $this->authorize('view', $account);
        $this->accountId = $id;
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

    public function with(): array
    {
        $account = Account::with('group.type')->findOrFail($this->accountId);

        $sortColumn = match ($this->sortBy) {
            'document_number' => 'documents.document_number',
            'type' => 'documents.document_type',
            'amount' => 'document_lines.line_total',
            default => 'documents.issue_date',
        };

        $lines = DocumentLine::query()
            ->join('documents', 'documents.id', '=', 'document_lines.document_id')
            ->leftJoin('businesses', 'businesses.id', '=', 'documents.party_id')
            ->leftJoin('persons', 'persons.id', '=', 'documents.party_id')
            ->selectRaw('document_lines.*, documents.issue_date, documents.document_number, documents.document_type, documents.status as document_status, COALESCE(businesses.legal_name, CONCAT(persons.first_name, " ", persons.last_name)) as party_name')
            ->where('document_lines.account_id', $this->accountId)
            ->whereNull('document_lines.deleted_at')
            ->orderBy($sortColumn, $this->sortDir)
            ->paginate(25);

        return compact('account', 'lines');
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
        <div class="border-b border-line px-6 py-4">
            <h2 class="text-sm font-semibold text-ink">Transactions</h2>
            <p class="mt-0.5 text-xs text-ink-muted">All document lines posted to this account</p>
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
                            <td class="px-4 py-3 text-right tabular-nums font-medium text-ink">
                                {{ number_format((float) $line->line_total, 2) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center text-sm text-ink-muted">
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
