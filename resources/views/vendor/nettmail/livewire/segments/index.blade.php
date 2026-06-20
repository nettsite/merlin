<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use NettSite\NettMail\Models\MailingList;
use NettSite\NettMail\Models\Segment;

new
#[Layout('nettmail::layouts.admin')]
#[Title('Segments')]
class extends Component
{
    public bool $showForm = false;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|uuid|exists:nettmail_lists,id')]
    public string $listId = '';

    public function create(): void
    {
        $this->validate();

        $segment = Segment::query()->create([
            'list_id' => $this->listId,
            'name' => $this->name,
            'conditions' => [],
        ]);

        $this->reset(['name', 'listId', 'showForm']);

        $this->redirectRoute('nettmail.segments.show', $segment);
    }

    public function delete(string $id): void
    {
        Segment::query()->findOrFail($id)->delete();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Segment> */
    public function getSegmentsProperty(): \Illuminate\Database\Eloquent\Collection
    {
        return Segment::query()->with('list')->orderBy('name')->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, MailingList> */
    public function getListsProperty(): \Illuminate\Database\Eloquent\Collection
    {
        return MailingList::query()->orderBy('name')->get();
    }
};
?>

<div>
    <h2>Segments</h2>

    <div class="nettmail-card">
        @if ($showForm)
            <form wire:submit="create">
                <div class="nettmail-field">
                    <label>Name</label>
                    <input type="text" class="nettmail-input" wire:model="name">
                    @error('name') <div class="nettmail-error">{{ $message }}</div> @enderror
                </div>
                <div class="nettmail-field">
                    <label>List</label>
                    <select class="nettmail-select" wire:model="listId">
                        <option value="">Select a list</option>
                        @foreach ($this->lists as $list)
                            <option value="{{ $list->id }}">{{ $list->name }}</option>
                        @endforeach
                    </select>
                    @error('listId') <div class="nettmail-error">{{ $message }}</div> @enderror
                </div>
                <button type="submit" class="nettmail-btn">Create segment</button>
                <button type="button" class="nettmail-btn nettmail-btn-secondary" wire:click="$set('showForm', false)">Cancel</button>
            </form>
        @else
            <button type="button" class="nettmail-btn" wire:click="$set('showForm', true)">New segment</button>
        @endif
    </div>

    <div class="nettmail-card">
        <table class="nettmail-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>List</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->segments as $segment)
                    <tr>
                        <td><a href="{{ route('nettmail.segments.show', $segment) }}">{{ $segment->name }}</a></td>
                        <td>{{ $segment->list->name }}</td>
                        <td>
                            <button type="button" class="nettmail-btn nettmail-btn-danger" wire:click="delete('{{ $segment->id }}')" wire:confirm="Delete this segment?">Delete</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3">No segments yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
