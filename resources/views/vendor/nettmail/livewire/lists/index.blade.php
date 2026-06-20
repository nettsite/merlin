<?php

use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use NettSite\NettMail\Models\MailingList;

new
#[Layout('nettmail::layouts.admin')]
#[Title('Lists')]
class extends Component
{
    public bool $showForm = false;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string')]
    public string $description = '';

    #[Validate('boolean')]
    public bool $doubleOptin = false;

    public function create(): void
    {
        $this->validate();

        MailingList::query()->create([
            'name' => $this->name,
            'slug' => Str::slug($this->name).'-'.Str::lower(Str::random(6)),
            'description' => $this->description ?: null,
            'double_optin' => $this->doubleOptin,
        ]);

        $this->reset(['name', 'description', 'doubleOptin', 'showForm']);
    }

    public function delete(string $id): void
    {
        $list = MailingList::query()->findOrFail($id);
        $list->members()->delete();
        $list->delete();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, MailingList> */
    public function getListsProperty(): \Illuminate\Database\Eloquent\Collection
    {
        return MailingList::query()->withCount('members')->orderBy('name')->get();
    }
};
?>

<div>
    <h2>Lists</h2>

    <div class="nettmail-card">
        @if ($showForm)
            <form wire:submit="create">
                <div class="nettmail-field">
                    <label>Name</label>
                    <input type="text" class="nettmail-input" wire:model="name">
                    @error('name') <div class="nettmail-error">{{ $message }}</div> @enderror
                </div>
                <div class="nettmail-field">
                    <label>Description</label>
                    <textarea class="nettmail-textarea" wire:model="description"></textarea>
                </div>
                <div class="nettmail-field">
                    <label><input type="checkbox" wire:model="doubleOptin"> Require double opt-in</label>
                </div>
                <button type="submit" class="nettmail-btn">Create list</button>
                <button type="button" class="nettmail-btn nettmail-btn-secondary" wire:click="$set('showForm', false)">Cancel</button>
            </form>
        @else
            <button type="button" class="nettmail-btn" wire:click="$set('showForm', true)">New list</button>
        @endif
    </div>

    <div class="nettmail-card">
        <table class="nettmail-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Members</th>
                    <th>Double opt-in</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->lists as $list)
                    <tr>
                        <td><a href="{{ route('nettmail.lists.show', $list) }}">{{ $list->name }}</a></td>
                        <td>{{ $list->members_count }}</td>
                        <td>{{ $list->double_optin ? 'Yes' : 'No' }}</td>
                        <td>
                            <button type="button" class="nettmail-btn nettmail-btn-danger" wire:click="delete('{{ $list->id }}')" wire:confirm="Delete this list? Memberships will be removed.">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">No lists yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
