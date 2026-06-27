<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-ink leading-tight">
            {{ __('Dashboard') }}
        </h2>
    </x-slot>

    @php $creditAlert = \Illuminate\Support\Facades\Cache::get('anthropic:credit_exhausted'); @endphp

    <div class="py-12">
        <div class="max-w-7xl mx-auto space-y-6 sm:px-6 lg:px-8">

            @if($creditAlert)
                <div class="flex items-start gap-4 rounded-xl border border-danger/30 bg-danger/5 p-6">
                    <flux:icon.exclamation-triangle class="mt-0.5 size-6 shrink-0 text-danger" />
                    <div>
                        <h3 class="font-semibold text-danger">Anthropic Credit Exhausted</h3>
                        <p class="mt-1 text-sm text-ink">
                            AI processing has been paused because the Anthropic credit balance is too low.
                            Add credits to your Anthropic account to resume invoice and bank statement extraction.
                        </p>
                        @if($creditAlert['detected_at'] ?? null)
                            <p class="mt-2 text-xs text-ink-muted">
                                Detected {{ \Carbon\Carbon::parse($creditAlert['detected_at'])->diffForHumans() }}
                                ({{ \Carbon\Carbon::parse($creditAlert['detected_at'])->format('d M Y H:i') }})
                            </p>
                        @endif
                    </div>
                </div>
            @endif

            <div class="bg-surface-alt overflow-hidden rounded-xl border border-line">
                <div class="p-6 text-ink">
                    {{ __("You're logged in!") }}
                </div>
            </div>

        </div>
    </div>
</x-app-layout>
