<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-ink border border-transparent rounded-md font-semibold text-sm text-white hover:bg-ink-soft active:bg-ink focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
