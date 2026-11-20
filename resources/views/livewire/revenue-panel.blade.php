<div>
    @if($canView)
        <div class="bg-surface-alt overflow-hidden rounded-xl border border-line">
            <div class="grid grid-cols-2 gap-4 border-b border-line p-6">
                <div>
                    <h3 class="text-sm font-semibold text-ink">Month to date</h3>
                    <p class="mt-2 text-3xl font-semibold tracking-tight text-ink tabular-nums">
                        {{ $currencySymbol }}{{ number_format($mtd, 2) }}
                    </p>
                    <p class="mt-1 text-sm text-ink-muted">{{ $monthLabel }}</p>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-ink">Year to date</h3>
                    <p class="mt-2 text-3xl font-semibold tracking-tight text-ink tabular-nums">
                        {{ $currencySymbol }}{{ number_format($ytd, 2) }}
                    </p>
                    <p class="mt-1 text-sm text-ink-muted">Financial year {{ $fyLabel }}</p>
                </div>
            </div>

            <div class="p-6">
                <h3 class="text-sm font-semibold text-ink">Revenue, last 12 months</h3>
                <div class="mt-4 flex h-40 items-end gap-2">
                    @foreach($months as $month)
                        <div class="flex h-full flex-1 flex-col justify-end" title="{{ $month['title'] }}: {{ $currencySymbol }}{{ number_format($month['amount'], 2) }}">
                            <div class="w-full rounded-t bg-accent" style="height: {{ round($month['pct'], 1) }}%; min-height: 2px"></div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-2 flex gap-2">
                    @foreach($months as $month)
                        <div class="flex-1 text-center text-xs text-ink-muted">{{ $month['label'] }}</div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>
