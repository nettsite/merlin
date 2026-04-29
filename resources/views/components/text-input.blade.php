@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-line focus:border-accent focus:ring-accent rounded-lg shadow-sm']) }}>
