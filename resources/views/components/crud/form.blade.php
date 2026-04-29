@props(['title', 'editing' => false])

<flux:modal name="crud-form" flyout wire:model.self="showForm" class="w-[420px]">
    <form wire:submit="save" class="flex flex-col h-full">
        <div class="p-6 border-b border-line">
            <flux:heading size="lg" class="font-semibold">
                {{ $editing ? 'Edit ' . $title : 'New ' . $title }}
            </flux:heading>
        </div>

        <div class="flex-1 p-6 space-y-4 overflow-y-auto">
            {{ $slot }}
        </div>

        <div class="flex items-center justify-end gap-3 px-6 py-4 border-t border-line bg-surface-alt">
            <flux:button type="button" variant="ghost" wire:click="cancelForm">Cancel</flux:button>
            <flux:button type="submit" variant="primary">
                {{ $editing ? 'Save changes' : 'Create ' . $title }}
            </flux:button>
        </div>
    </form>
</flux:modal>
