<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\AccountBalanceRollup;
use App\Modules\Accounting\Services\FinancialYearService;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentLine;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layout.app')] class extends Component
{
    public string $dateFrom = '';

    public string $dateTo = '';

    public function mount(): void
    {
        $fyService = app(FinancialYearService::class);
        [$fyStart] = $fyService->yearBounds($fyService->currentYearLabel());
        $this->dateFrom = $fyStart->format('Y-m-d');
        $this->dateTo = now()->format('Y-m-d');
    }

    public function with(): array
    {
        $settings = app(CurrencySettings::class);

        // Build movement accumulator for a given date window.
        // $from = null means no lower bound (cumulative balance mode).
        $buildAcc = function (?string $from, ?string $to): array {
            $acc = [];

            $add = function (string $id, float $debit, float $credit) use (&$acc): void {
                if (! array_key_exists($id, $acc)) {
                    $acc[$id] = ['debit' => 0.0, 'credit' => 0.0];
                }
                $acc[$id]['debit'] += $debit;
                $acc[$id]['credit'] += $credit;
            };

            DocumentLine::query()
                ->join('documents as d', 'd.id', '=', 'document_lines.document_id')
                ->where('d.document_type', 'sales_invoice')
                ->whereNotIn('d.status', ['draft', 'voided'])
                ->whereNull('d.deleted_at')
                ->whereNotNull('document_lines.account_id')
                ->when($from, fn ($q) => $q->whereDate('d.issue_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('d.issue_date', '<=', $to))
                ->selectRaw('document_lines.account_id, SUM(document_lines.line_total) as total')
                ->groupBy('document_lines.account_id')
                ->get()
                ->each(fn ($r) => $add($r->account_id, 0.0, (float) $r->total));

            DocumentLine::query()
                ->join('documents as d', 'd.id', '=', 'document_lines.document_id')
                ->where('d.document_type', 'purchase_invoice')
                ->whereIn('d.status', ['posted', 'partially_paid', 'paid'])
                ->whereNull('d.deleted_at')
                ->whereNotNull('document_lines.account_id')
                ->when($from, fn ($q) => $q->whereDate('d.issue_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('d.issue_date', '<=', $to))
                ->selectRaw('document_lines.account_id, SUM(document_lines.line_total) as total')
                ->groupBy('document_lines.account_id')
                ->get()
                ->each(fn ($r) => $add($r->account_id, (float) $r->total, 0.0));

            Document::query()
                ->where('document_type', 'sales_invoice')
                ->whereNotIn('status', ['draft', 'voided'])
                ->whereNull('deleted_at')
                ->whereNotNull('receivable_account_id')
                ->when($from, fn ($q) => $q->whereDate('issue_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('issue_date', '<=', $to))
                ->selectRaw('receivable_account_id, SUM(total) as total')
                ->groupBy('receivable_account_id')
                ->get()
                ->each(fn ($r) => $add($r->receivable_account_id, (float) $r->total, 0.0));

            // Purchase invoices → credit AP
            Document::query()
                ->where('document_type', 'purchase_invoice')
                ->whereIn('status', ['posted', 'partially_paid', 'paid'])
                ->whereNull('deleted_at')
                ->whereNotNull('payable_account_id')
                ->when($from, fn ($q) => $q->whereDate('issue_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('issue_date', '<=', $to))
                ->selectRaw('payable_account_id, SUM(total) as total')
                ->groupBy('payable_account_id')
                ->get()
                ->each(fn ($r) => $add($r->payable_account_id, 0.0, (float) $r->total));

            // Inbound payments (receivable settlements) → credit AR, debit bank
            Document::query()
                ->where('document_type', 'payment')
                ->where('direction', 'inbound')
                ->whereNull('deleted_at')
                ->when($from, fn ($q) => $q->whereDate('issue_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('issue_date', '<=', $to))
                ->get()
                ->each(function ($p) use ($add) {
                    if ($p->receivable_account_id !== null) {
                        $add($p->receivable_account_id, 0.0, (float) $p->total);
                    }
                    if ($p->contra_account_id !== null) {
                        $add($p->contra_account_id, (float) $p->total, 0.0);
                    }
                });

            // Outbound payments (payable settlements) → debit AP, credit bank
            Document::query()
                ->where('document_type', 'payment')
                ->where('direction', 'outbound')
                ->whereNull('deleted_at')
                ->when($from, fn ($q) => $q->whereDate('issue_date', '>=', $from))
                ->when($to, fn ($q) => $q->whereDate('issue_date', '<=', $to))
                ->get()
                ->each(function ($p) use ($add) {
                    if ($p->payable_account_id !== null) {
                        $add($p->payable_account_id, (float) $p->total, 0.0);
                    }
                    if ($p->contra_account_id !== null) {
                        $add($p->contra_account_id, 0.0, (float) $p->total);
                    }
                });

            return AccountBalanceRollup::rollupToRoots($acc);
        };

        $movAcc = $buildAcc($this->dateFrom ?: null, $this->dateTo ?: null);
        $balAcc = $buildAcc(null, $this->dateTo ?: null);

        $allIds = array_unique(array_merge(array_keys($movAcc), array_keys($balAcc)));

        if (empty($allIds)) {
            return [
                'rows' => collect(),
                'totMovDebit' => 0.0,
                'totMovCredit' => 0.0,
                'totBalDebit' => 0.0,
                'totBalCredit' => 0.0,
                'currency' => $settings->base_currency,
            ];
        }

        $rows = Account::query()
            ->join('account_groups', 'account_groups.id', '=', 'accounts.account_group_id')
            ->join('account_types', 'account_types.id', '=', 'account_groups.account_type_id')
            ->selectRaw('
                accounts.id,
                accounts.code,
                accounts.name as account_name,
                account_groups.name as group_name,
                account_types.name as type_name,
                account_types.sort_order as type_sort
            ')
            ->whereNull('accounts.deleted_at')
            ->whereIn('accounts.id', $allIds)
            ->orderBy('account_types.sort_order')
            ->orderBy('account_groups.sort_order')
            ->orderBy('accounts.sort_order')
            ->orderBy('accounts.code')
            ->get()
            ->map(function ($account) use ($movAcc, $balAcc) {
                $mov = $movAcc[$account->id] ?? ['debit' => 0.0, 'credit' => 0.0];
                $bal = $balAcc[$account->id] ?? ['debit' => 0.0, 'credit' => 0.0];
                $netBal = $bal['debit'] - $bal['credit'];

                $account->mov_debit = $mov['debit'];
                $account->mov_credit = $mov['credit'];
                $account->bal_debit = $netBal > 0 ? $netBal : 0.0;
                $account->bal_credit = $netBal < 0 ? abs($netBal) : 0.0;

                return $account;
            })
            ->groupBy('type_name');

        $totMovDebit = collect($movAcc)->sum('debit');
        $totMovCredit = collect($movAcc)->sum('credit');
        $totBalDebit = $rows->flatten()->sum('bal_debit');
        $totBalCredit = $rows->flatten()->sum('bal_credit');

        return [
            'rows' => $rows,
            'totMovDebit' => $totMovDebit,
            'totMovCredit' => $totMovCredit,
            'totBalDebit' => $totBalDebit,
            'totBalCredit' => $totBalCredit,
            'currency' => $settings->base_currency,
        ];
    }
}; ?>

<div>
@include('livewire.pages.reports._subnav')
<div class="flex items-start justify-between px-6 py-5 border-b border-line">
    <div>
        <h1 class="text-[17px] font-semibold tracking-tight text-ink">Trial Balance</h1>
        <p class="mt-0.5 text-sm text-ink-muted">Period movements and cumulative balances as at the To date</p>
    </div>
    <div class="flex items-center gap-3">
        <flux:input wire:model.live="dateFrom" type="date" size="sm" label="From" />
        <flux:input wire:model.live="dateTo" type="date" size="sm" label="To" />
    </div>
</div>

<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-surface-alt border-b border-line">
                <th class="px-4 py-2 text-left w-24" rowspan="2"></th>
                <th class="px-4 py-2 text-left" rowspan="2"></th>
                <th colspan="2" class="px-4 py-2 text-center text-xs font-semibold uppercase tracking-widest text-ink-muted border-b border-line">Movements</th>
                <th colspan="2" class="px-4 py-2 text-center text-xs font-semibold uppercase tracking-widest text-ink-muted border-b border-line">Balance</th>
            </tr>
            <tr class="bg-surface-alt border-b border-line">
                <th class="px-4 py-2 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Debit</th>
                <th class="px-4 py-2 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Credit</th>
                <th class="px-4 py-2 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Debit</th>
                <th class="px-4 py-2 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Credit</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $typeName => $accounts)
                <tr class="bg-surface-alt border-t border-line">
                    <td colspan="6" class="px-4 py-2 text-xs font-semibold uppercase tracking-widest text-ink-muted">{{ $typeName }}</td>
                </tr>
                @foreach($accounts as $account)
                    <tr class="border-t border-line hover:bg-surface-alt">
                        <td class="px-4 py-2.5 font-mono text-xs text-ink-muted">{{ $account->code }}</td>
                        <td class="px-4 py-2.5 text-ink">{{ $account->account_name }}</td>
                        <td class="px-4 py-2.5 text-right tabular-nums {{ $account->mov_debit > 0 ? 'text-ink' : 'text-ink-soft' }}">
                            {{ $account->mov_debit > 0 ? number_format($account->mov_debit, 2) : '—' }}
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums {{ $account->mov_credit > 0 ? 'text-ink' : 'text-ink-soft' }}">
                            {{ $account->mov_credit > 0 ? number_format($account->mov_credit, 2) : '—' }}
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums {{ $account->bal_debit > 0 ? 'text-ink font-medium' : 'text-ink-soft' }}">
                            {{ $account->bal_debit > 0 ? number_format($account->bal_debit, 2) : '—' }}
                        </td>
                        <td class="px-4 py-2.5 text-right tabular-nums {{ $account->bal_credit > 0 ? 'text-ink font-medium' : 'text-ink-soft' }}">
                            {{ $account->bal_credit > 0 ? number_format($account->bal_credit, 2) : '—' }}
                        </td>
                    </tr>
                @endforeach
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-12 text-center">
                        <p class="font-medium text-ink">No data for this period.</p>
                    </td>
                </tr>
            @endforelse
        </tbody>
        @if($rows->isNotEmpty())
            <tfoot>
                <tr class="border-t-2 border-line bg-surface-alt font-semibold">
                    <td colspan="2" class="px-4 py-3 text-ink">Total</td>
                    <td class="px-4 py-3 text-right text-ink tabular-nums">{{ number_format($totMovDebit, 2) }}</td>
                    <td class="px-4 py-3 text-right text-ink tabular-nums">{{ number_format($totMovCredit, 2) }}</td>
                    <td class="px-4 py-3 text-right text-ink tabular-nums">{{ number_format($totBalDebit, 2) }}</td>
                    <td class="px-4 py-3 text-right text-ink tabular-nums">{{ number_format($totBalCredit, 2) }}</td>
                </tr>
                @if(abs($totMovDebit - $totMovCredit) > 0.01 || abs($totBalDebit - $totBalCredit) > 0.01)
                    <tr>
                        <td colspan="6" class="px-4 py-2 text-center text-xs text-warning">
                            @if(abs($totMovDebit - $totMovCredit) > 0.01)
                                Movements out of balance by {{ number_format(abs($totMovDebit - $totMovCredit), 2) }}.
                            @endif
                            @if(abs($totBalDebit - $totBalCredit) > 0.01)
                                Balance out of balance by {{ number_format(abs($totBalDebit - $totBalCredit), 2) }}.
                            @endif
                        </td>
                    </tr>
                @endif
            </tfoot>
        @endif
    </table>
</div>
</div>
