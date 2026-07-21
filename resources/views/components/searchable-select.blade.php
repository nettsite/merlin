@props([
    'model',
    'options' => [],
    'placeholder' => '— None —',
    'searchPlaceholder' => 'Search…',
    'nullable' => true,
])

<div
    x-data="{
        open: false,
        query: '',
        options: @js($options),
        get filtered() {
            if (this.query === '') return this.options;
            const q = this.query.toLowerCase();
            return this.options.filter(o => o.label.toLowerCase().includes(q));
        },
        get selectedLabel() {
            const current = this.options.find(o => o.value === this.$wire.{{ $model }});
            return current ? current.label : '';
        },
        choose(value) {
            this.$wire.{{ $model }} = value;
            this.query = '';
            this.open = false;
        },
    }"
    x-on:click.outside="open = false"
    x-on:keydown.escape="open = false"
    class="relative"
    {{ $attributes }}
>
    <button
        type="button"
        x-on:click="open = !open; if (open) $nextTick(() => $refs.search.focus())"
        class="w-full flex items-center justify-between border border-zinc-200 border-b-zinc-300/80 dark:border-white/10 bg-white dark:bg-white/10 rounded-lg h-10 px-3 text-sm text-left shadow-xs"
    >
        <span x-text="selectedLabel || '{{ $placeholder }}'" :class="{ 'text-zinc-400': !selectedLabel }"></span>
        <svg class="w-4 h-4 text-zinc-400 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.24a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd"/></svg>
    </button>

    <div
        x-show="open"
        x-transition
        class="absolute z-20 mt-1 w-full bg-white dark:bg-zinc-800 border border-line rounded-lg shadow-lg max-h-64 overflow-hidden flex flex-col"
        style="display: none;"
    >
        <input
            type="text"
            x-ref="search"
            x-model="query"
            placeholder="{{ $searchPlaceholder }}"
            class="m-2 px-2 py-1.5 text-sm border border-line rounded-md focus:outline-none"
        />
        <div class="overflow-y-auto">
            @if($nullable)
                <button type="button" x-on:click="choose('')" class="w-full text-left px-3 py-1.5 text-sm text-ink-muted hover:bg-zinc-50 dark:hover:bg-white/5">{{ $placeholder }}</button>
            @endif
            <template x-for="option in filtered" :key="option.value">
                <button
                    type="button"
                    x-on:click="choose(option.value)"
                    x-text="option.label"
                    class="w-full text-left px-3 py-1.5 text-sm hover:bg-zinc-50 dark:hover:bg-white/5"
                ></button>
            </template>
            <div x-show="filtered.length === 0" class="px-3 py-2 text-sm text-ink-muted">No matches</div>
        </div>
    </div>
</div>
