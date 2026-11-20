<div>
    @if($canView)
        <div class="bg-surface-alt overflow-hidden rounded-xl border border-line">
            <div class="flex items-start justify-between gap-4 border-b border-line p-6">
                <div>
                    <h3 class="text-sm font-semibold text-ink">Unpaid invoices</h3>
                    <p class="mt-2 text-3xl font-semibold tracking-tight text-ink tabular-nums">
                        {{ $currencySymbol }}{{ number_format($total, 2) }}
                    </p>
                    <p class="mt-1 text-sm text-ink-muted">
                        {{ $count }} {{ \Illuminate\Support\Str::plural('invoice', $count) }} outstanding
                    </p>
                </div>
                @if($count > 0)
                    <flux:button href="{{ route('sales-invoices.index', ['status' => 'unpaid']) }}" wire:navigate size="sm" variant="ghost">
                        View all
                    </flux:button>
                @endif
            </div>

            @if($rows->isEmpty())
                <p class="px-6 py-8 text-sm text-ink-muted">No unpaid invoices.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-ink-muted">
                                <th class="px-6 py-3">Client</th>
                                <th class="px-4 py-3">Invoice</th>
                                <th class="px-4 py-3">Due</th>
                                <th class="py-3 pl-4 pr-6 text-right">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $invoice)
                                <tr class="border-t border-line">
                                    <td class="px-6 py-3 font-medium text-ink">
                                        {{ $invoice->party?->business?->display_name ?? '—' }}
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs text-ink">
                                        {{ $invoice->document_number }}
                                    </td>
                                    <td class="px-4 py-3 text-ink-soft">
                                        @if($invoice->due_date)
                                            <span @class(['font-medium text-danger' => $invoice->due_date->lt(today())])>
                                                {{ $invoice->due_date->format('d M Y') }}
                                            </span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="py-3 pl-4 pr-6 text-right font-medium text-ink tabular-nums">
                                        {{ $currencySymbol }}{{ number_format((float) $invoice->balance_due, 2) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if($count > $rows->count())
                    <p class="border-t border-line px-6 py-3 text-sm text-ink-muted">
                        and {{ $count - $rows->count() }} more
                    </p>
                @endif
            @endif
        </div>
    @endif
</div>
