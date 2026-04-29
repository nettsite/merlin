@props([
    'height'   => 32,
    'wordmark' => true,
])
@php
    $color   = '#1A1A1A';
    $accent  = '#C8772E';
    $fontSize = round($height * 0.7);
@endphp
<span style="display:inline-flex;align-items:center;gap:10px;flex-shrink:0;line-height:1;">
    <svg viewBox="0 0 32 32" width="{{ $height }}" height="{{ $height }}" aria-hidden="true">
        <rect x="2" y="2" width="28" height="28" rx="7" fill="{{ $color }}" />
        <path d="M16 7 L17.6 14.4 L25 16 L17.6 17.6 L16 25 L14.4 17.6 L7 16 L14.4 14.4 Z" fill="{{ $accent }}" />
    </svg>
    @if($wordmark)
        <span style="font-family:'Inter',system-ui,sans-serif;font-weight:700;letter-spacing:-0.025em;line-height:1;font-size:{{ $fontSize }}px;color:{{ $color }};">Merlin</span>
    @endif
</span>
