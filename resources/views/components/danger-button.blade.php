<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-danger border border-transparent rounded-md font-semibold text-sm text-white hover:bg-red-700 active:bg-red-800 focus:outline-none focus:ring-2 focus:ring-danger focus:ring-offset-2 disabled:opacity-25 transition ease-in-out duration-150']) }}>
    {{ $slot }}
</button>
