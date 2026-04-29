<?php

use App\Modules\Purchasing\Models\LlmLog;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layout.app')] class extends Component
{
    public function mount(): void
    {
        $this->authorize('view-llm-summary');
    }

    public function with(): array
    {
        $logs = LlmLog::query();

        $summary = (clone $logs)->selectRaw('
            COUNT(*) as total_calls,
            SUM(prompt_tokens) as total_prompt_tokens,
            SUM(completion_tokens) as total_completion_tokens,
            SUM(prompt_tokens + COALESCE(completion_tokens, 0)) as total_tokens,
            AVG(duration_ms) as avg_duration_ms,
            AVG(confidence) as avg_confidence,
            SUM(CASE WHEN error IS NOT NULL THEN 1 ELSE 0 END) as error_count
        ')->first();

        $byModel = (clone $logs)->selectRaw('
            model,
            COUNT(*) as call_count,
            SUM(prompt_tokens) as prompt_tokens,
            SUM(completion_tokens) as completion_tokens,
            AVG(duration_ms) as avg_duration_ms,
            AVG(confidence) as avg_confidence,
            SUM(CASE WHEN error IS NOT NULL THEN 1 ELSE 0 END) as error_count
        ')
        ->whereNotNull('model')
        ->groupBy('model')
        ->orderByDesc('call_count')
        ->get();

        $recentErrors = (clone $logs)->whereNotNull('error')->latest()->limit(10)->get();

        return [
            'summary' => $summary,
            'byModel' => $byModel,
            'recentErrors' => $recentErrors,
        ];
    }
}; ?>

<div>
@include('livewire.pages.reports._subnav')
<div class="px-6 py-5 border-b border-line">
    <h1 class="text-[17px] font-semibold tracking-tight text-ink">LLM Performance</h1>
    <p class="mt-0.5 text-sm text-ink-muted">AI processing statistics across all invoice runs</p>
</div>

{{-- Summary cards --}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-4 p-6 border-b border-line">
    <div class="bg-surface-alt rounded-lg p-4">
        <p class="text-xs text-ink-muted uppercase tracking-wide">Total Calls</p>
        <p class="text-2xl font-semibold text-ink mt-1">{{ number_format($summary->total_calls ?? 0) }}</p>
    </div>
    <div class="bg-surface-alt rounded-lg p-4">
        <p class="text-xs text-ink-muted uppercase tracking-wide">Total Tokens</p>
        <p class="text-2xl font-semibold text-ink mt-1">{{ number_format($summary->total_tokens ?? 0) }}</p>
    </div>
    <div class="bg-surface-alt rounded-lg p-4">
        <p class="text-xs text-ink-muted uppercase tracking-wide">Avg. Duration</p>
        <p class="text-2xl font-semibold text-ink mt-1">
            {{ $summary->avg_duration_ms ? number_format($summary->avg_duration_ms / 1000, 1).'s' : '—' }}
        </p>
    </div>
    <div class="bg-surface-alt rounded-lg p-4">
        <p class="text-xs text-ink-muted uppercase tracking-wide">Avg. Confidence</p>
        <p class="text-2xl font-semibold text-ink mt-1">
            {{ $summary->avg_confidence !== null ? number_format($summary->avg_confidence * 100, 1).'%' : '—' }}
        </p>
        @if(($summary->error_count ?? 0) > 0)
            <p class="text-xs text-danger mt-1">{{ $summary->error_count }} error(s)</p>
        @endif
    </div>
</div>

{{-- By model --}}
@if($byModel->isNotEmpty())
<div class="px-6 py-5 border-b border-line">
    <h2 class="text-sm font-semibold text-ink mb-4">By Model</h2>
    <table class="w-full text-sm">
        <thead>
            <tr>
                <th class="text-left text-xs font-medium text-ink-muted uppercase tracking-wide pb-2">Model</th>
                <th class="text-right text-xs font-medium text-ink-muted uppercase tracking-wide pb-2">Calls</th>
                <th class="text-right text-xs font-medium text-ink-muted uppercase tracking-wide pb-2">Prompt Tokens</th>
                <th class="text-right text-xs font-medium text-ink-muted uppercase tracking-wide pb-2">Completion Tokens</th>
                <th class="text-right text-xs font-medium text-ink-muted uppercase tracking-wide pb-2">Avg. Duration</th>
                <th class="text-right text-xs font-medium text-ink-muted uppercase tracking-wide pb-2">Avg. Confidence</th>
                <th class="text-right text-xs font-medium text-ink-muted uppercase tracking-wide pb-2">Errors</th>
            </tr>
        </thead>
        <tbody>
            @foreach($byModel as $model)
                <tr class="border-t border-line">
                    <td class="py-2 font-mono text-xs text-ink">{{ $model->model }}</td>
                    <td class="py-2 text-right text-ink-soft tabular-nums">{{ number_format($model->call_count) }}</td>
                    <td class="py-2 text-right text-ink-soft tabular-nums">{{ number_format($model->prompt_tokens) }}</td>
                    <td class="py-2 text-right text-ink-soft tabular-nums">{{ number_format($model->completion_tokens) }}</td>
                    <td class="py-2 text-right text-ink-soft tabular-nums">
                        {{ $model->avg_duration_ms ? number_format($model->avg_duration_ms / 1000, 1).'s' : '—' }}
                    </td>
                    <td class="py-2 text-right text-ink-soft tabular-nums">
                        {{ $model->avg_confidence !== null ? number_format($model->avg_confidence * 100, 1).'%' : '—' }}
                    </td>
                    <td class="py-2 text-right tabular-nums">
                        <span @class(['text-danger font-medium' => $model->error_count > 0, 'text-ink-soft' => $model->error_count === 0])>
                            {{ $model->error_count }}
                        </span>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endif

{{-- Recent errors --}}
@if($recentErrors->isNotEmpty())
<div class="px-6 py-5">
    <h2 class="text-sm font-semibold text-ink mb-4">Recent Errors</h2>
    <div class="space-y-3">
        @foreach($recentErrors as $log)
            <div class="rounded border border-red-100 bg-red-50 p-3">
                <div class="flex items-center justify-between mb-1">
                    <span class="font-mono text-xs text-ink-muted">{{ $log->model }}</span>
                    <span class="text-xs text-ink-muted">{{ $log->created_at->diffForHumans() }}</span>
                </div>
                <p class="text-xs text-danger">{{ $log->error }}</p>
            </div>
        @endforeach
    </div>
</div>
@endif
</div>
