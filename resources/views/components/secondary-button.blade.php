<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-white border border-line rounded-md font-semibold text-sm text-ink hover:bg-surface-alt focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
