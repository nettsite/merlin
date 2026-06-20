<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Nettsite\NettMail\Core\Domain\Campaigns\CampaignStatus;
use NettSite\NettMail\Models\Campaign;
use NettSite\NettMail\Models\MailingList;
use NettSite\NettMail\Models\Segment;
use NettSite\NettMail\Models\Template;

new
#[Layout('nettmail::layouts.admin')]
#[Title('Campaigns')]
class extends Component
{
    public bool $showForm = false;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|max:255')]
    public string $subject = '';

    #[Validate('required|uuid|exists:nettmail_lists,id')]
    public string $listId = '';

    #[Validate('nullable|uuid|exists:nettmail_segments,id')]
    public string $segmentId = '';

    #[Validate('required|uuid|exists:nettmail_templates,id')]
    public string $templateId = '';

    public function create(): void
    {
        $this->validate();

        $campaign = Campaign::query()->create([
            'list_id' => $this->listId,
            'segment_id' => $this->segmentId ?: null,
            'template_id' => $this->templateId,
            'name' => $this->name,
            'subject' => $this->subject,
            'status' => CampaignStatus::Draft,
            'track_opens' => true,
            'track_clicks' => true,
        ]);

        $this->reset(['name', 'subject', 'listId', 'segmentId', 'templateId', 'showForm']);

        $this->redirectRoute('nettmail.campaigns.show', $campaign);
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Campaign> */
    public function getCampaignsProperty(): \Illuminate\Database\Eloquent\Collection
    {
        return Campaign::query()->with('list')->latest()->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, MailingList> */
    public function getListsProperty(): \Illuminate\Database\Eloquent\Collection
    {
        return MailingList::query()->orderBy('name')->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Segment> */
    public function getSegmentsProperty(): \Illuminate\Database\Eloquent\Collection
    {
        if ($this->listId === '') {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return Segment::query()->where('list_id', $this->listId)->orderBy('name')->get();
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Template> */
    public function getTemplatesProperty(): \Illuminate\Database\Eloquent\Collection
    {
        return Template::query()->orderBy('name')->get();
    }
};
?>

<div>
    <h2>Campaigns</h2>

    <div class="nettmail-card">
        @if ($showForm)
            <form wire:submit="create">
                <div class="nettmail-field">
                    <label>Name</label>
                    <input type="text" class="nettmail-input" wire:model="name">
                    @error('name') <div class="nettmail-error">{{ $message }}</div> @enderror
                </div>
                <div class="nettmail-field">
                    <label>Subject</label>
                    <input type="text" class="nettmail-input" wire:model="subject">
                    @error('subject') <div class="nettmail-error">{{ $message }}</div> @enderror
                </div>
                <div class="nettmail-field">
                    <label>List</label>
                    <select class="nettmail-select" wire:model.live="listId">
                        <option value="">Select a list</option>
                        @foreach ($this->lists as $list)
                            <option value="{{ $list->id }}">{{ $list->name }}</option>
                        @endforeach
                    </select>
                    @error('listId') <div class="nettmail-error">{{ $message }}</div> @enderror
                </div>
                <div class="nettmail-field">
                    <label>Segment (optional)</label>
                    <select class="nettmail-select" wire:model="segmentId">
                        <option value="">All subscribed members</option>
                        @foreach ($this->segments as $segment)
                            <option value="{{ $segment->id }}">{{ $segment->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="nettmail-field">
                    <label>Template</label>
                    <select class="nettmail-select" wire:model="templateId">
                        <option value="">Select a template</option>
                        @foreach ($this->templates as $template)
                            <option value="{{ $template->id }}">{{ $template->name }}</option>
                        @endforeach
                    </select>
                    @error('templateId') <div class="nettmail-error">{{ $message }}</div> @enderror
                </div>
                <button type="submit" class="nettmail-btn">Create campaign</button>
                <button type="button" class="nettmail-btn nettmail-btn-secondary" wire:click="$set('showForm', false)">Cancel</button>
            </form>
        @else
            <button type="button" class="nettmail-btn" wire:click="$set('showForm', true)">New campaign</button>
        @endif
    </div>

    <div class="nettmail-card">
        <table class="nettmail-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>List</th>
                    <th>Status</th>
                    <th>Scheduled at</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->campaigns as $campaign)
                    <tr>
                        <td><a href="{{ route('nettmail.campaigns.show', $campaign) }}">{{ $campaign->name }}</a></td>
                        <td>{{ $campaign->list->name }}</td>
                        <td><span class="nettmail-badge">{{ $campaign->status->value }}</span></td>
                        <td>{{ $campaign->scheduled_at?->toDayDateTimeString() ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">No campaigns yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
