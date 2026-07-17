@php
    $label = match(true) {
        $status === 'posted' && (float) $balanceDue > 0 => 'Unpaid',
        $status === 'partially_paid' => 'Part Paid',
        $status === 'paid' => 'Paid',
        default => null,
    };

    $classes = match($label) {
        'Unpaid' => 'bg-red-50 text-danger',
        'Part Paid' => 'bg-amber-50 text-amber-700',
        'Paid' => 'bg-emerald-50 text-emerald-800',
        default => null,
    };
@endphp
@if($label)
    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $classes }}">{{ $label }}</span>
@else
    <span class="text-ink-muted text-xs">—</span>
@endif
