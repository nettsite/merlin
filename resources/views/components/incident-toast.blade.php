<div
    x-data="{ toasts: [] }"
    x-on:incident-toast.window="
        const t = { id: Date.now() + Math.random(), title: $event.detail.title, message: $event.detail.message };
        toasts.push(t);
        setTimeout(() => toasts = toasts.filter(x => x.id !== t.id), 8000);
    "
    class="fixed top-4 right-4 z-50 w-80 space-y-2"
>
    <template x-for="toast in toasts" :key="toast.id">
        <div x-transition class="rounded-lg border border-amber-300/40 bg-amber-50 shadow-lg p-4">
            <p class="text-sm font-semibold text-ink" x-text="toast.title"></p>
            <p class="mt-1 text-xs text-ink-muted" x-text="toast.message"></p>
        </div>
    </template>
</div>
