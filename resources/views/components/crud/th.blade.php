@props([
    'column' => null,
    'sortBy' => '',
    'sortDir' => 'asc',
    'right' => false,
])

@php
    $isActive = $column && $sortBy === $column;
@endphp

<th
    @if($column) wire:click="sort('{{ $column }}')" @endif
    @class([
        'px-4 py-2.5 text-[10.5px] font-semibold uppercase tracking-[0.06em] text-ink-muted bg-surface-alt whitespace-nowrap select-none',
        'cursor-pointer hover:text-ink transition-colors' => $column,
        'text-right' => $right,
        'text-left' => !$right,
    ])
>
    <span class="inline-flex items-center gap-1">
        {{ $slot }}
        @if($column)
            <span @class(['text-accent' => $isActive, 'text-line' => !$isActive])>
                @if($isActive && $sortDir === 'desc')
                    {{-- Arrow up --}}
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="size-3">
                        <path fill-rule="evenodd" d="M8 14a.75.75 0 0 1-.75-.75V4.56L4.03 7.78a.75.75 0 0 1-1.06-1.06l4.5-4.5a.75.75 0 0 1 1.06 0l4.5 4.5a.75.75 0 0 1-1.06 1.06L8.75 4.56v8.69A.75.75 0 0 1 8 14Z" clip-rule="evenodd" />
                    </svg>
                @else
                    {{-- Arrow down --}}
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" fill="currentColor" class="size-3">
                        <path fill-rule="evenodd" d="M8 2a.75.75 0 0 1 .75.75v8.69l3.22-3.22a.75.75 0 1 1 1.06 1.06l-4.5 4.5a.75.75 0 0 1-1.06 0l-4.5-4.5a.75.75 0 1 1 1.06-1.06L7.25 11.44V2.75A.75.75 0 0 1 8 2Z" clip-rule="evenodd" />
                    </svg>
                @endif
            </span>
        @endif
    </span>
</th>
