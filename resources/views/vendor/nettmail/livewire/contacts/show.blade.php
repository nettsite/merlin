<?php

use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use NettSite\NettMail\Facades\NettMail;
use NettSite\NettMail\Models\Contact;
use NettSite\NettMail\Models\Send;
use Livewire\Component;

new
#[Layout('nettmail::layouts.admin')]
#[Title('Contact')]
class extends Component
{
    public Contact $contact;

    public bool $erased = false;

    public function getMembershipsProperty(): Collection
    {
        return $this->contact->memberships()->with('list')->get();
    }

    /** @return Collection<int, Send> */
    public function getSendsProperty(): Collection
    {
        return Send::query()
            ->where('contact_id', $this->contact->id)
            ->with('campaign')
            ->latest('sent_at')
            ->limit(20)
            ->get();
    }

    public function erase(): void
    {
        NettMail::eraseContact($this->contact->email);

        $this->contact->refresh();
        $this->erased = true;
    }
};
?>

<div>
    <h2>Contact</h2>

    @if ($erased)
        <div class="nettmail-card" style="border-color: #16a34a;">
            This contact has been anonymised.
        </div>
    @endif

    <div class="nettmail-card">
        <div class="nettmail-field">
            <label>Email</label>
            {{ $contact->email }}
        </div>
        <div class="nettmail-field">
            <label>Name</label>
            {{ trim(($contact->first_name ?? '').' '.($contact->last_name ?? '')) ?: '—' }}
        </div>
        <div class="nettmail-field">
            <label>Phone</label>
            {{ $contact->phone ?? '—' }}
        </div>
    </div>

    <div class="nettmail-card">
        <h3>Suppression state</h3>

        @if ($contact->bounce_type !== null)
            <p>
                <span class="nettmail-badge">{{ $contact->bounce_type->value }} bounce</span>
                since {{ $contact->bounced_at?->toDayDateTimeString() ?? '—' }}
                @if ($contact->bounce_type->value === 'soft')
                    ({{ $contact->consecutive_soft_bounces }} consecutive)
                @endif
            </p>
        @endif

        @if ($contact->global_unsubscribed_at !== null)
            <p>
                <span class="nettmail-badge">globally unsubscribed</span>
                since {{ $contact->global_unsubscribed_at->toDayDateTimeString() }}
            </p>
        @endif

        @if ($contact->bounce_type === null && $contact->global_unsubscribed_at === null)
            <p><span class="nettmail-badge">active</span></p>
        @endif

        @unless ($erased)
            <button type="button" class="nettmail-btn nettmail-btn-danger" wire:click="erase" wire:confirm="Anonymise this contact's personal data? This cannot be undone.">
                Erase contact (POPIA right-to-erasure)
            </button>
        @endunless
    </div>

    <div class="nettmail-card">
        <h3>List memberships</h3>

        <table class="nettmail-table">
            <thead>
                <tr>
                    <th>List</th>
                    <th>Status</th>
                    <th>Tags</th>
                    <th>Subscribed at</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->memberships as $membership)
                    <tr>
                        <td>{{ $membership->list?->name }}</td>
                        <td><span class="nettmail-badge">{{ $membership->status->value }}</span></td>
                        <td>{{ implode(', ', $membership->tags ?? []) ?: '—' }}</td>
                        <td>{{ $membership->subscribed_at?->toDayDateTimeString() ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">No list memberships.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="nettmail-card">
        <h3>Send history</h3>

        <table class="nettmail-table">
            <thead>
                <tr>
                    <th>Campaign</th>
                    <th>Status</th>
                    <th>Sent at</th>
                    <th>Opened</th>
                    <th>Clicked</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->sends as $send)
                    <tr>
                        <td>{{ $send->campaign?->name ?? 'Transactional' }}</td>
                        <td><span class="nettmail-badge">{{ $send->status }}</span></td>
                        <td>{{ $send->sent_at?->toDayDateTimeString() ?? '—' }}</td>
                        <td>{{ $send->opened_at?->toDayDateTimeString() ?? '—' }}</td>
                        <td>{{ $send->clicked_at?->toDayDateTimeString() ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5">No sends yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
