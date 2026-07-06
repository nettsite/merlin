<div wire:poll.20s="checkIncidents">
    <flux:dropdown position="bottom" align="end">
        <flux:button variant="ghost" square class="relative" aria-label="Notifications">
            <flux:icon.bell class="size-5" />
            @if($incidents->isNotEmpty())
                <span class="absolute -top-0.5 -right-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-danger text-[10px] font-semibold text-white">
                    {{ $incidents->count() }}
                </span>
            @endif
        </flux:button>
        <flux:menu class="w-80">
            @forelse($incidents as $incident)
                @php
                    $link = match ($incident->type) {
                        'unposted_invoices' => route('purchase-invoices.index'),
                        default => null,
                    };
                @endphp
                <div class="px-3 py-2 border-b border-line last:border-b-0">
                    <p class="text-sm font-medium text-ink">{{ $incident->title }}</p>
                    <p class="text-xs text-ink-muted mt-0.5">{{ $incident->message }}</p>
                    <div class="flex items-center justify-between mt-1">
                        <span class="text-[11px] text-ink-muted">{{ $incident->triggered_at->diffForHumans() }}</span>
                        @if($link)
                            <a href="{{ $link }}" wire:navigate class="text-[11px] font-medium text-primary hover:underline">View</a>
                        @endif
                    </div>
                </div>
            @empty
                <div class="px-3 py-2 text-sm text-ink-muted">No active notifications.</div>
            @endforelse
        </flux:menu>
    </flux:dropdown>
</div>
