@props(['title', 'editing' => false])

<flux:modal name="crud-form" wire:model.self="showForm" class="w-full max-w-2xl">
    <form wire:submit="save" class="flex flex-col gap-4">
        <flux:heading size="lg" class="font-semibold">
            {{ $editing ? 'Edit ' . $title : 'New ' . $title }}
        </flux:heading>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            {{ $slot }}
        </div>

        <div class="flex items-center justify-end gap-3 pt-2">
            <flux:button type="button" variant="ghost" wire:click="cancelForm">Cancel</flux:button>
            <flux:button type="submit" variant="primary">
                {{ $editing ? 'Save changes' : 'Create ' . $title }}
            </flux:button>
        </div>
    </form>
</flux:modal>
