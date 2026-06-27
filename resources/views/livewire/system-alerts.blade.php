<div wire:poll.30000ms>
    @if($creditAlert)
        <div class="flex items-center gap-3 border-b border-danger/20 bg-danger/5 px-4 py-3">
            <flux:icon.exclamation-triangle class="size-5 shrink-0 text-danger" />
            <div class="flex-1 text-sm">
                <span class="font-semibold text-danger">Anthropic credit exhausted.</span>
                <span class="ml-1 text-danger-700">AI processing has been paused. Add credits to your Anthropic account to resume.</span>
                @if($creditAlert['detected_at'] ?? null)
                    <span class="ml-2 text-xs text-ink-muted">Detected {{ \Carbon\Carbon::parse($creditAlert['detected_at'])->diffForHumans() }}</span>
                @endif
            </div>
            <flux:button wire:click="dismissCredit" size="sm" variant="ghost" icon="x-mark" />
        </div>
    @endif
</div>
