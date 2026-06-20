<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Nettsite\NettMail\Core\Domain\Contacts\BounceType;
use NettSite\NettMail\Models\Contact;
use NettSite\NettMail\Models\Send;

new
#[Layout('nettmail::layouts.admin')]
#[Title('Dashboard')]
class extends Component
{
    /** @return \Illuminate\Database\Eloquent\Collection<int, Send> */
    public function getRecentSendsProperty(): \Illuminate\Database\Eloquent\Collection
    {
        return Send::query()
            ->with(['contact', 'campaign'])
            ->latest('sent_at')
            ->limit(10)
            ->get();
    }

    public function getHardBounceCountProperty(): int
    {
        return Contact::query()->where('bounce_type', BounceType::Hard)->count();
    }

    public function getSoftBounceCountProperty(): int
    {
        return Contact::query()->where('bounce_type', BounceType::Soft)->count();
    }

    public function getComplaintCountProperty(): int
    {
        return Contact::query()->where('bounce_type', BounceType::Complaint)->count();
    }

    public function getSentTodayCountProperty(): int
    {
        return Send::query()->whereDate('sent_at', today())->count();
    }
};
?>

<div>
    <h2>Dashboard</h2>

    <div class="nettmail-card">
        <div class="nettmail-stats">
            <div class="nettmail-stat">
                <div class="value">{{ $this->sentTodayCount }}</div>
                <div class="label">Sent today</div>
            </div>
            <div class="nettmail-stat">
                <div class="value">{{ $this->hardBounceCount }}</div>
                <div class="label">Hard bounces</div>
            </div>
            <div class="nettmail-stat">
                <div class="value">{{ $this->softBounceCount }}</div>
                <div class="label">Soft bounces</div>
            </div>
            <div class="nettmail-stat">
                <div class="value">{{ $this->complaintCount }}</div>
                <div class="label">Complaints</div>
            </div>
        </div>
    </div>

    <div class="nettmail-card">
        <h3>Recent sends</h3>

        <table class="nettmail-table">
            <thead>
                <tr>
                    <th>Recipient</th>
                    <th>Campaign</th>
                    <th>Status</th>
                    <th>Sent at</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->recentSends as $send)
                    <tr>
                        <td>{{ $send->contact?->email }}</td>
                        <td>{{ $send->campaign?->name ?? '—' }}</td>
                        <td><span class="nettmail-badge">{{ $send->status }}</span></td>
                        <td>{{ $send->sent_at?->diffForHumans() ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4">No sends yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
