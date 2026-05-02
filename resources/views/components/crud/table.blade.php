@props(['title', 'description' => null])

<div>
    {{-- Page header --}}
    <div class="flex items-start justify-between px-6 py-5 border-b border-line">
        <div>
            <h1 class="text-[17px] font-semibold tracking-tight text-ink">{{ $title }}</h1>
            @if($description)
                <p class="mt-0.5 text-sm text-ink-muted">{{ $description }}</p>
            @endif
        </div>
        @if(isset($actions))
            <div class="flex items-center gap-3 shrink-0 ml-4">{{ $actions }}</div>
        @endif
    </div>

    {{-- Search --}}
    <div class="px-6 py-3 border-b border-line bg-surface-alt">
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="Search..."
            size="sm"
            icon="magnifying-glass"
            class="max-w-xs"
        />
    </div>

    {{-- Optional filter/toolbar slot --}}
    @if(isset($filters))
        {{ $filters }}
    @endif

    {{-- Table --}}
    <div class="overflow-x-auto">
        {{ $slot }}
    </div>

    {{-- Pagination --}}
    @if(isset($pagination))
        <div class="px-6 py-4 border-t border-line">{{ $pagination }}</div>
    @endif
</div>
