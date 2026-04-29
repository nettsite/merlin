@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-accent text-start text-base font-medium text-accent-ink bg-accent-soft focus:outline-none transition duration-150 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-ink-soft hover:text-ink hover:bg-surface-alt hover:border-line focus:outline-none focus:text-ink focus:bg-surface-alt focus:border-line transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
