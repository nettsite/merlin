<?php

use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Nettsite\NettMail\Core\Domain\Campaigns\CampaignStatus;
use Nettsite\NettMail\Core\Domain\Webhooks\EventType;
use NettSite\NettMail\Campaigns\CampaignLifecycle;
use NettSite\NettMail\Campaigns\CampaignReportCsvExporter;
use NettSite\NettMail\Jobs\ProcessCampaignSend;
use NettSite\NettMail\Models\Campaign;
use NettSite\NettMail\Models\CampaignLink;
use NettSite\NettMail\Models\Event;
use NettSite\NettMail\Models\Segment;

new
#[Layout('nettmail::layouts.admin')]
#[Title('Campaign')]
class extends Component
{
    public Campaign $campaign;

    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|string|max:255')]
    public string $subject = '';

    #[Validate('nullable|uuid|exists:nettmail_segments,id')]
    public string $segmentId = '';

    #[Validate('boolean')]
    public bool $trackOpens = true;

    #[Validate('boolean')]
    public bool $trackClicks = true;

    #[Validate('required|date')]
    public string $scheduledAt = '';

    public function mount(): void
    {
        $this->name = $this->campaign->name;
        $this->subject = $this->campaign->subject;
        $this->segmentId = $this->campaign->segment_id ?? '';
        $this->trackOpens = $this->campaign->track_opens;
        $this->trackClicks = $this->campaign->track_clicks;
        $this->scheduledAt = $this->campaign->scheduled_at?->format('Y-m-d\TH:i') ?? '';
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'subject' => 'required|string|max:255',
            'segmentId' => 'nullable|uuid|exists:nettmail_segments,id',
            'trackOpens' => 'boolean',
            'trackClicks' => 'boolean',
        ]);

        $this->campaign->update([
            'name' => $this->name,
            'subject' => $this->subject,
            'segment_id' => $this->segmentId ?: null,
            'track_opens' => $this->trackOpens,
            'track_clicks' => $this->trackClicks,
        ]);

        session()->flash('nettmail-status', 'Campaign saved.');
    }

    public function schedule(): void
    {
        $this->validate(['scheduledAt' => 'required|date']);

        $this->campaign->scheduled_at = Carbon::parse($this->scheduledAt);
        $this->campaign->save();
        $this->campaign->transitionTo(CampaignStatus::Scheduled);

        session()->flash('nettmail-status', 'Campaign scheduled.');
    }

    public function unschedule(): void
    {
        $this->campaign->transitionTo(CampaignStatus::Draft);
        $this->campaign->scheduled_at = null;
        $this->campaign->save();

        session()->flash('nettmail-status', 'Campaign moved back to draft.');
    }

    public function sendNow(): void
    {
        $this->campaign->transitionTo(CampaignStatus::Sending);

        ProcessCampaignSend::dispatch($this->campaign->id);

        session()->flash('nettmail-status', 'Campaign send started.');
    }

    public function pause(): void
    {
        app(CampaignLifecycle::class)->pause($this->campaign);

        session()->flash('nettmail-status', 'Campaign paused.');
    }

    public function resume(): void
    {
        app(CampaignLifecycle::class)->resume($this->campaign);

        session()->flash('nettmail-status', 'Campaign resumed.');
    }

    public function exportReport(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $csv = (new CampaignReportCsvExporter)->export($this->campaign);

        return response()->streamDownload(
            fn () => print ($csv),
            "{$this->campaign->name}-report.csv",
        );
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Segment> */
    public function getSegmentsProperty(): \Illuminate\Database\Eloquent\Collection
    {
        return Segment::query()->where('list_id', $this->campaign->list_id)->orderBy('name')->get();
    }

    /**
     * @return array{
     *     total: int,
     *     delivered: int,
     *     deliveryRate: float,
     *     uniqueOpens: int,
     *     totalOpens: int,
     *     openRate: float,
     *     uniqueClicks: int,
     *     totalClicks: int,
     *     clickRate: float,
     *     bounced: int,
     *     bounceRate: float,
     * }
     */
    public function getStatsProperty(): array
    {
        $sends = $this->campaign->sends();

        $total = $sends->count();
        $delivered = (clone $sends)->whereNotNull('delivered_at')->count();
        $uniqueOpens = (clone $sends)->whereNotNull('opened_at')->count();
        $uniqueClicks = (clone $sends)->whereNotNull('clicked_at')->count();
        $bounced = (clone $sends)->whereNotNull('bounced_at')->count();

        $sendIds = (clone $sends)->pluck('id');

        $totalOpens = Event::query()->whereIn('send_id', $sendIds)->where('type', EventType::Opened->value)->count();
        $totalClicks = Event::query()->whereIn('send_id', $sendIds)->where('type', EventType::Clicked->value)->count();

        $denominator = $delivered > 0 ? $delivered : $total;

        return [
            'total' => $total,
            'delivered' => $delivered,
            'deliveryRate' => $total > 0 ? $delivered / $total : 0.0,
            'uniqueOpens' => $uniqueOpens,
            'totalOpens' => $totalOpens,
            'openRate' => $denominator > 0 ? $uniqueOpens / $denominator : 0.0,
            'uniqueClicks' => $uniqueClicks,
            'totalClicks' => $totalClicks,
            'clickRate' => $denominator > 0 ? $uniqueClicks / $denominator : 0.0,
            'bounced' => $bounced,
            'bounceRate' => $total > 0 ? $bounced / $total : 0.0,
        ];
    }

    /** @return array<int, array{url: string, clicks: int}> */
    public function getTopLinksProperty(): array
    {
        $sendIds = $this->campaign->sends()->pluck('id');

        $clicksByHash = Event::query()
            ->whereIn('send_id', $sendIds)
            ->where('type', EventType::Clicked->value)
            ->get()
            ->countBy(fn (Event $event): string => $event->payload['link_hash'] ?? '');

        $links = CampaignLink::query()->where('campaign_id', $this->campaign->id)->get()->keyBy('link_hash');

        return $clicksByHash
            ->map(fn (int $clicks, string $hash): array => [
                'url' => $links->get($hash)?->url ?? $hash,
                'clicks' => $clicks,
            ])
            ->sortByDesc('clicks')
            ->take(5)
            ->values()
            ->all();
    }

    /** @return array<string, int> */
    public function getTimelineProperty(): array
    {
        $sentAtTimes = $this->campaign->sends()->whereNotNull('sent_at')->pluck('sent_at');

        if ($sentAtTimes->isEmpty()) {
            return [];
        }

        $hourly = $sentAtTimes->max()->diffInHours($sentAtTimes->min()) < 24;

        $format = $hourly ? 'Y-m-d H:00' : 'Y-m-d';

        return $sentAtTimes
            ->countBy(fn (Carbon $sentAt): string => $sentAt->format($format))
            ->sortKeys()
            ->all();
    }
};
?>

<div>
    <h2>{{ $campaign->name }}</h2>

    @if (session('nettmail-status'))
        <div class="nettmail-card" style="border-color: #16a34a;">{{ session('nettmail-status') }}</div>
    @endif

    <div class="nettmail-card">
        <p>Status: <span class="nettmail-badge">{{ $campaign->status->value }}</span></p>

        @if ($campaign->status === \Nettsite\NettMail\Core\Domain\Campaigns\CampaignStatus::Sending)
            <button type="button" class="nettmail-btn nettmail-btn-secondary" wire:click="pause">Pause</button>
        @elseif ($campaign->status === \Nettsite\NettMail\Core\Domain\Campaigns\CampaignStatus::Paused)
            <button type="button" class="nettmail-btn" wire:click="resume">Resume</button>
        @elseif ($campaign->status === \Nettsite\NettMail\Core\Domain\Campaigns\CampaignStatus::Scheduled)
            <button type="button" class="nettmail-btn nettmail-btn-secondary" wire:click="unschedule">Move back to draft</button>
        @elseif ($campaign->status === \Nettsite\NettMail\Core\Domain\Campaigns\CampaignStatus::Draft)
            <button type="button" class="nettmail-btn" wire:click="sendNow" wire:confirm="Send this campaign now?">Send now</button>
        @endif
    </div>

    @if ($campaign->status === \Nettsite\NettMail\Core\Domain\Campaigns\CampaignStatus::Draft)
        <div class="nettmail-card">
            <h3>Details</h3>

            <form wire:submit="save">
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
                    <label>Segment (optional)</label>
                    <select class="nettmail-select" wire:model="segmentId">
                        <option value="">All subscribed members</option>
                        @foreach ($this->segments as $segment)
                            <option value="{{ $segment->id }}">{{ $segment->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="nettmail-field">
                    <label><input type="checkbox" wire:model="trackOpens"> Track opens</label>
                </div>
                <div class="nettmail-field">
                    <label><input type="checkbox" wire:model="trackClicks"> Track clicks</label>
                </div>
                <button type="submit" class="nettmail-btn">Save</button>
            </form>
        </div>

        <div class="nettmail-card">
            <h3>Schedule</h3>

            <form wire:submit="schedule">
                <div class="nettmail-field">
                    <label>Send at</label>
                    <input type="datetime-local" class="nettmail-input" wire:model="scheduledAt">
                    @error('scheduledAt') <div class="nettmail-error">{{ $message }}</div> @enderror
                </div>
                <button type="submit" class="nettmail-btn">Schedule</button>
            </form>
        </div>
    @endif

    <div class="nettmail-card">
        <h3>Report</h3>

        <button type="button" class="nettmail-btn nettmail-btn-secondary" wire:click="exportReport">Export CSV</button>

        <div class="nettmail-stats">
            <div class="nettmail-stat">
                <strong>{{ $this->stats['total'] }}</strong>
                <span>Sent</span>
            </div>
            <div class="nettmail-stat">
                <strong>{{ number_format($this->stats['deliveryRate'] * 100, 1) }}%</strong>
                <span>Delivered ({{ $this->stats['delivered'] }})</span>
            </div>
            <div class="nettmail-stat">
                <strong>{{ number_format($this->stats['openRate'] * 100, 1) }}%</strong>
                <span>Opens ({{ $this->stats['uniqueOpens'] }} unique / {{ $this->stats['totalOpens'] }} total)</span>
            </div>
            <div class="nettmail-stat">
                <strong>{{ number_format($this->stats['clickRate'] * 100, 1) }}%</strong>
                <span>Clicks ({{ $this->stats['uniqueClicks'] }} unique / {{ $this->stats['totalClicks'] }} total)</span>
            </div>
            <div class="nettmail-stat">
                <strong>{{ number_format($this->stats['bounceRate'] * 100, 1) }}%</strong>
                <span>Bounced ({{ $this->stats['bounced'] }})</span>
            </div>
        </div>

        <h4>Top links</h4>
        <table class="nettmail-table">
            <thead>
                <tr>
                    <th>URL</th>
                    <th>Clicks</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->topLinks as $link)
                    <tr>
                        <td>{{ $link['url'] }}</td>
                        <td>{{ $link['clicks'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2">No clicks yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <h4>Timeline</h4>
        <table class="nettmail-table">
            <thead>
                <tr>
                    <th>Period</th>
                    <th>Sent</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($this->timeline as $period => $count)
                    <tr>
                        <td>{{ $period }}</td>
                        <td>{{ $count }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="2">No sends yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
