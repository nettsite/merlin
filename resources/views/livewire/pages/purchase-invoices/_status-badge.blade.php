@php
    $classes = match($status) {
        'received'  => 'bg-blue-50 text-blue-700',
        'reviewed'  => 'bg-yellow-50 text-yellow-700',
        'approved'  => 'bg-green-50 text-green-700',
        'posted'    => 'bg-emerald-50 text-emerald-800',
        'disputed'  => 'bg-orange-50 text-orange-700',
        'rejected'  => 'bg-red-50 text-danger',
        default     => 'bg-surface-alt text-ink-muted',
    };
@endphp
<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $classes }}">
    {{ ucfirst($status) }}
</span>
