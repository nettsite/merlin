<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use NettSite\NettMail\Models\Template;

new
#[Layout('nettmail::layouts.admin')]
#[Title('Templates')]
class extends Component
{
    public bool $showForm = false;

    public bool $showArchived = false;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|in:transactional,broadcast')]
    public string $type = 'broadcast';

    public function create(): void
    {
        $this->validate();

        $template = Template::query()->create([
            'name' => $this->name,
            'type' => $this->type,
            'subject' => '',
            'design' => [],
            'html' => $this->type === 'broadcast' ? '<p>{{unsubscribe_url}}</p>' : '',
            'plain_text' => $this->type === 'broadcast' ? 'Unsubscribe: {{unsubscribe_url}}' : '',
        ]);

        $this->reset(['name', 'type', 'showForm']);

        $this->redirectRoute('nettmail.templates.show', $template);
    }

    public function duplicate(string $id): void
    {
        $template = Template::query()->findOrFail($id);

        Template::query()->create([
            'name' => "{$template->name} (copy)",
            'type' => $template->type,
            'subject' => $template->subject,
            'design' => $template->design,
            'html' => $template->html,
            'plain_text' => $template->plain_text,
        ]);
    }

    public function archive(string $id): void
    {
        Template::query()->findOrFail($id)->update(['archived_at' => now()]);
    }

    public function unarchive(string $id): void
    {
        Template::query()->findOrFail($id)->update(['archived_at' => null]);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Template> */
    public function getTemplatesProperty(): \Illuminate\Database\Eloquent\Collection
    {
        return Template::query()
            ->when(! $this->showArchived, fn ($query) => $query->whereNull('archived_at'))
            ->when($this->showArchived, fn ($query) => $query->whereNotNull('archived_at'))
            ->orderBy('name')
            ->get();
    }
};
?>

<div>
    <h2>Templates</h2>

    <div class="nettmail-card">
        @if ($showForm)
            <form wire:submit="create">
                <div class="nettmail-field">
                    <label>Name</label>
                    <input type="text" class="nettmail-input" wire:model="name">
                    @error('name') <div class="nettmail-error">{{ $message }}</div> @enderror
                </div>
                <div class="nettmail-field">
                    <label>Type</label>
                    <select class="nettmail-select" wire:model="type">
                        <option value="broadcast">Broadcast</option>
                        <option value="transactional">Transactional</option>
                    </select>
                </div>
                <button type="submit" class="nettmail-btn">Create template</button>
                <button type="button" class="nettmail-btn nettmail-btn-secondary" wire:click="$set('showForm', false)">Cancel</button>
            </form>
        @else
            <button type="button" class="nettmail-btn" wire:click="$set('showForm', true)">New template</button>
            <button type="button" class="nettmail-btn nettmail-btn-secondary" wire:click="$toggle('showArchived')">
                {{ $showArchived ? 'Show active' : 'Show archived' }}
            </button>
        @endif
    </div>

    <div class="nettmail-card">
        <table class="nettmail-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Subject</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->templates as $template)
                    <tr>
                        <td><a href="{{ route('nettmail.templates.show', $template) }}">{{ $template->name }}</a></td>
                        <td><span class="nettmail-badge">{{ $template->type->value }}</span></td>
                        <td>{{ $template->subject }}</td>
                        <td>
                            <button type="button" class="nettmail-btn nettmail-btn-secondary" wire:click="duplicate('{{ $template->id }}')">Duplicate</button>
                            @if ($template->archived_at === null)
                                <button type="button" class="nettmail-btn nettmail-btn-danger" wire:click="archive('{{ $template->id }}')">Archive</button>
                            @else
                                <button type="button" class="nettmail-btn" wire:click="unarchive('{{ $template->id }}')">Unarchive</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">No templates yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
