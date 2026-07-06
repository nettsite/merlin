<div wire:poll.20s>
    @foreach($incidents as $incident)
        @php
            $link = match ($incident->type) {
                'unposted_invoices' => route('purchase-invoices.index'),
                default => null,
            };
        @endphp
        <div class="flex items-start gap-4 rounded-xl border border-amber-300/40 bg-amber-50 p-6">
            <flux:icon.exclamation-triangle class="mt-0.5 size-6 shrink-0 text-warning" />
            <div class="flex-1">
                <h3 class="font-semibold text-ink">{{ $incident->title }}</h3>
                <p class="mt-1 text-sm text-ink">{{ $incident->message }}</p>
                <p class="mt-2 text-xs text-ink-muted">
                    Since {{ $incident->triggered_at->diffForHumans() }}
                    ({{ $incident->triggered_at->format('d M Y H:i') }})
                </p>
            </div>
            @if($link)
                <flux:button href="{{ $link }}" wire:navigate size="sm" variant="primary">View</flux:button>
            @endif
        </div>
    @endforeach
</div>
