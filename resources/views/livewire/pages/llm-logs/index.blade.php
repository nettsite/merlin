<?php

use App\Modules\Purchasing\Models\LlmLog;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Layout('components.layout.app')] class extends Component
{
    use WithPagination;

    public string $search = '';

    public ?string $selectedId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', LlmLog::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function select(string $id): void
    {
        $this->selectedId = $this->selectedId === $id ? null : $id;
    }

    public function with(): array
    {
        return [
            'rows' => LlmLog::with('loggable')
                ->when(
                    $this->search,
                    fn ($q) => $q->where('model', 'like', "%{$this->search}%")
                        ->orWhere('error', 'like', "%{$this->search}%")
                )
                ->latest()
                ->paginate(30),
            'selectedLog' => $this->selectedId ? LlmLog::find($this->selectedId) : null,
        ];
    }
}; ?>

<div>
<div>
    <div class="flex items-start justify-between px-6 py-5 border-b border-line">
        <div>
            <h1 class="text-[17px] font-semibold tracking-tight text-ink">LLM Logs</h1>
            <p class="mt-0.5 text-sm text-ink-muted">Audit trail of all AI processing calls</p>
        </div>
    </div>

    <div class="px-6 py-3 border-b border-line bg-surface-alt">
        <flux:input
            wire:model.live.debounce.300ms="search"
            placeholder="Search by model or error…"
            size="sm"
            icon="magnifying-glass"
            class="max-w-xs"
        />
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Date</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Model</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Prompt Tokens</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Completion Tokens</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Duration</th>
                    <th class="px-4 py-3 text-right text-xs font-medium text-ink-muted uppercase tracking-wide">Confidence</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-ink-muted uppercase tracking-wide">Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($rows as $log)
                    <tr
                        wire:click="select('{{ $log->id }}')"
                        class="border-t border-line hover:bg-surface-alt cursor-pointer {{ $selectedId === $log->id ? 'bg-surface-alt' : '' }}"
                    >
                        <td class="px-4 py-3 text-ink-soft tabular-nums text-xs whitespace-nowrap">
                            {{ $log->created_at->format('Y-m-d H:i:s') }}
                        </td>
                        <td class="px-4 py-3 font-mono text-xs text-ink">{{ $log->model ?? '—' }}</td>
                        <td class="px-4 py-3 text-ink-soft tabular-nums text-right">{{ number_format($log->prompt_tokens ?? 0) }}</td>
                        <td class="px-4 py-3 text-ink-soft tabular-nums text-right">{{ number_format($log->completion_tokens ?? 0) }}</td>
                        <td class="px-4 py-3 text-ink-soft tabular-nums text-right">
                            {{ $log->duration_ms ? number_format($log->duration_ms).'ms' : '—' }}
                        </td>
                        <td class="px-4 py-3 text-ink-soft tabular-nums text-right">
                            {{ $log->confidence !== null ? number_format($log->confidence * 100, 1).'%' : '—' }}
                        </td>
                        <td class="px-4 py-3">
                            @if($log->error)
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-50 text-danger">Error</span>
                            @else
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-50 text-success">OK</span>
                            @endif
                        </td>
                    </tr>
                    @if($selectedId === $log->id)
                        <tr class="border-t border-line bg-surface-alt">
                            <td colspan="7" class="px-4 py-4">
                                @if($selectedLog?->error)
                                    <div class="mb-3">
                                        <p class="text-xs font-medium text-danger mb-1">Error</p>
                                        <pre class="text-xs text-ink bg-white border border-line rounded p-3 overflow-x-auto">{{ $selectedLog->error }}</pre>
                                    </div>
                                @endif
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-xs font-medium text-ink-muted mb-1">Request Payload</p>
                                        <pre class="text-xs text-ink bg-white border border-line rounded p-3 overflow-x-auto max-h-48">{{ json_encode($selectedLog?->request_payload, JSON_PRETTY_PRINT) }}</pre>
                                    </div>
                                    <div>
                                        <p class="text-xs font-medium text-ink-muted mb-1">Response Payload</p>
                                        <pre class="text-xs text-ink bg-white border border-line rounded p-3 overflow-x-auto max-h-48">{{ json_encode($selectedLog?->response_payload, JSON_PRETTY_PRINT) }}</pre>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-12 text-center">
                            <p class="font-medium text-ink">No LLM logs yet.</p>
                            <p class="mt-1 text-sm text-ink-muted">Logs appear after invoices are processed by AI.</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="px-6 py-4 border-t border-line">
        {{ $rows->links() }}
    </div>
</div>
</div>
