<div class="flex items-center gap-1 px-6 border-b border-line overflow-x-auto">
    @php
        $links = [
            ['route' => 'reports.expenses-by-account', 'label' => 'By Account'],
            ['route' => 'reports.expenses-by-supplier', 'label' => 'By Supplier'],
            ['route' => 'reports.llm-performance', 'label' => 'LLM Performance'],
        ];
    @endphp
    @foreach($links as $link)
        <a
            href="{{ route($link['route']) }}"
            wire:navigate
            @class([
                'px-3 py-2.5 text-sm font-medium border-b-2 whitespace-nowrap transition-colors',
                'border-primary text-primary' => request()->routeIs($link['route']),
                'border-transparent text-ink-soft hover:text-ink' => !request()->routeIs($link['route']),
            ])
        >{{ $link['label'] }}</a>
    @endforeach
</div>
