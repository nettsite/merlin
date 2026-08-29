<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\AccountBalanceRollup;
use App\Modules\Core\Settings\CurrencySettings;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layout.app')] class extends Component
{
    public string $asAt = '';

    public function mount(): void
    {
        $this->asAt = now()->format('Y-m-d');
    }

    public function with(): array
    {
        $settings = app(CurrencySettings::class);
        $asAt = $this->asAt ?: null;

        // Cumulative balance from the journal: one grouped query in place of
        // the six document-header accumulator queries this used to run.
        // Reversals (e.g. a voided invoice) are just further journal entries,
        // so they net out automatically with no status filtering here.
        $rows = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->when($asAt, fn ($q) => $q->whereDate('journal_entries.entry_date', '<=', $asAt))
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) as debit, SUM(journal_lines.credit) as credit')
            ->groupBy('journal_lines.account_id')
            ->get();

        $acc = [];

        foreach ($rows as $row) {
            $acc[$row->account_id] = ['debit' => (float) $row->debit, 'credit' => (float) $row->credit];
        }

        $acc = AccountBalanceRollup::rollupToRoots($acc);

        // Load accounts and compute net balances
        $accounts = empty($acc) ? collect() : Account::query()
            ->join('account_groups', 'account_groups.id', '=', 'accounts.account_group_id')
            ->join('account_types', 'account_types.id', '=', 'account_groups.account_type_id')
            ->selectRaw('
                accounts.id,
                accounts.code,
                accounts.name as account_name,
                accounts.sort_order as account_sort,
                account_groups.name as group_name,
                account_groups.sort_order as group_sort,
                account_types.code as type_code,
                account_types.normal_balance
            ')
            ->whereNull('accounts.deleted_at')
            ->whereIn('accounts.id', array_keys($acc))
            ->orderBy('account_groups.sort_order')
            ->orderBy('accounts.sort_order')
            ->orderBy('accounts.code')
            ->get()
            ->map(function ($a) use ($acc) {
                $m = $acc[$a->id];
                // Net balance in the account's natural direction
                $a->balance = $a->normal_balance === 'debit'
                    ? $m['debit'] - $m['credit']   // assets/expenses: positive = balance
                    : $m['credit'] - $m['debit'];  // liabilities/equity/income: positive = balance

                return $a;
            });

        $assets = $accounts->where('type_code', '1')->groupBy('group_name');
        $liabilities = $accounts->where('type_code', '2')->groupBy('group_name');
        $equity = $accounts->where('type_code', '3')->groupBy('group_name');

        // Net income = cumulative income credits - expense debits up to asAt
        $totalIncome = $accounts->where('type_code', '4')->sum('balance');
        $totalExpenses = $accounts->where('type_code', '5')->sum('balance');
        $netIncome = $totalIncome - $totalExpenses;

        $totalAssets = $assets->flatten()->sum('balance');
        $totalLiabilities = $liabilities->flatten()->sum('balance');
        $totalEquity = $equity->flatten()->sum('balance') + $netIncome;

        return [
            'assets' => $assets,
            'liabilities' => $liabilities,
            'equity' => $equity,
            'netIncome' => $netIncome,
            'totalAssets' => $totalAssets,
            'totalLiabilities' => $totalLiabilities,
            'totalEquity' => $totalEquity,
            'currency' => $settings->base_currency,
        ];
    }
}; ?>

<div>
@include('livewire.pages.reports._subnav')
<div class="flex items-start justify-between px-6 py-5 border-b border-line">
    <div>
        <h1 class="text-[17px] font-semibold tracking-tight text-ink">Balance Sheet</h1>
        <p class="mt-0.5 text-sm text-ink-muted">Cumulative financial position as at the selected date</p>
    </div>
    <div class="flex items-center gap-3">
        <flux:input wire:model.live="asAt" type="date" size="sm" label="As at" />
    </div>
</div>

<div class="max-w-2xl mx-auto px-6 py-6 space-y-6">

    {{-- Assets --}}
    <div>
        <h2 class="text-xs font-semibold uppercase tracking-widest text-ink-muted mb-1">Assets</h2>
        <div class="border border-line rounded-lg overflow-hidden">
            @forelse($assets as $groupName => $accounts)
                @if($assets->count() > 1)
                    <div class="px-4 py-2 bg-surface-alt text-xs font-medium text-ink-muted border-b border-line">{{ $groupName }}</div>
                @endif
                @foreach($accounts as $account)
                    <div class="flex items-center justify-between px-4 py-2.5 border-b border-line last:border-b-0 hover:bg-surface-alt">
                        <span class="text-sm text-ink">
                            <span class="font-mono text-xs text-ink-muted mr-2">{{ $account->code }}</span>{{ $account->account_name }}
                        </span>
                        <span class="text-sm tabular-nums text-ink">{{ number_format($account->balance, 2) }}</span>
                    </div>
                @endforeach
            @empty
                <div class="px-4 py-8 text-center text-sm text-ink-muted">No asset balances.</div>
            @endforelse
        </div>
        <div class="flex justify-between px-4 py-2.5 font-semibold text-sm text-ink">
            <span>Total Assets</span>
            <span class="tabular-nums">{{ number_format($totalAssets, 2) }}</span>
        </div>
    </div>

    {{-- Liabilities --}}
    <div>
        <h2 class="text-xs font-semibold uppercase tracking-widest text-ink-muted mb-1">Liabilities</h2>
        <div class="border border-line rounded-lg overflow-hidden">
            @forelse($liabilities as $groupName => $accounts)
                @if($liabilities->count() > 1)
                    <div class="px-4 py-2 bg-surface-alt text-xs font-medium text-ink-muted border-b border-line">{{ $groupName }}</div>
                @endif
                @foreach($accounts as $account)
                    <div class="flex items-center justify-between px-4 py-2.5 border-b border-line last:border-b-0 hover:bg-surface-alt">
                        <span class="text-sm text-ink">
                            <span class="font-mono text-xs text-ink-muted mr-2">{{ $account->code }}</span>{{ $account->account_name }}
                        </span>
                        <span class="text-sm tabular-nums text-ink">{{ number_format($account->balance, 2) }}</span>
                    </div>
                @endforeach
            @empty
                <div class="px-4 py-8 text-center text-sm text-ink-muted">No liability balances.</div>
            @endforelse
        </div>
        <div class="flex justify-between px-4 py-2.5 font-semibold text-sm text-ink">
            <span>Total Liabilities</span>
            <span class="tabular-nums">{{ number_format($totalLiabilities, 2) }}</span>
        </div>
    </div>

    {{-- Equity --}}
    <div>
        <h2 class="text-xs font-semibold uppercase tracking-widest text-ink-muted mb-1">Equity</h2>
        <div class="border border-line rounded-lg overflow-hidden">
            @foreach($equity as $groupName => $accounts)
                @foreach($accounts as $account)
                    <div class="flex items-center justify-between px-4 py-2.5 border-b border-line hover:bg-surface-alt">
                        <span class="text-sm text-ink">
                            <span class="font-mono text-xs text-ink-muted mr-2">{{ $account->code }}</span>{{ $account->account_name }}
                        </span>
                        <span class="text-sm tabular-nums text-ink">{{ number_format($account->balance, 2) }}</span>
                    </div>
                @endforeach
            @endforeach
            <div class="flex items-center justify-between px-4 py-2.5 border-b border-line last:border-b-0 hover:bg-surface-alt">
                <span class="text-sm text-ink">Net Income</span>
                <span @class([
                    'text-sm tabular-nums font-medium',
                    'text-success' => $netIncome >= 0,
                    'text-danger' => $netIncome < 0,
                ])>{{ number_format($netIncome, 2) }}</span>
            </div>
        </div>
        <div class="flex justify-between px-4 py-2.5 font-semibold text-sm text-ink">
            <span>Total Equity</span>
            <span class="tabular-nums">{{ number_format($totalEquity, 2) }}</span>
        </div>
    </div>

    {{-- Check --}}
    <div class="border-t-2 border-line pt-4">
        <div class="flex justify-between px-4 py-3 bg-surface-alt rounded-lg">
            <span class="font-bold text-ink">Total Liabilities + Equity</span>
            <span class="font-bold text-lg tabular-nums text-ink">{{ number_format($totalLiabilities + $totalEquity, 2) }}</span>
        </div>
        @if(abs($totalAssets - ($totalLiabilities + $totalEquity)) > 0.01)
            <p class="mt-2 text-center text-xs text-warning">
                Out of balance by {{ number_format(abs($totalAssets - ($totalLiabilities + $totalEquity)), 2) }}
            </p>
        @endif
    </div>

</div>
</div>
