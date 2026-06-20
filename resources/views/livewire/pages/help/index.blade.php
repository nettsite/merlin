<?php

use App\Modules\Core\Models\ChatMessage;
use App\Modules\Core\Models\ChatSession;
use App\Services\ClaudeChatService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layout.app')] class extends Component
{
    public string $sessionId = '';

    public string $input = '';

    /** @var array<int, array{role: string, content: string}> */
    public array $messages = [];

    public function mount(): void
    {
        $session = ChatSession::firstOrCreate(['user_id' => Auth::id()]);
        $this->sessionId = $session->id;

        $this->messages = ChatMessage::where('chat_session_id', $this->sessionId)
            ->orderBy('created_at')
            ->get()
            ->map(fn (ChatMessage $m): array => ['role' => $m->role, 'content' => $m->content])
            ->all();
    }

    public function send(): void
    {
        $this->validate(['input' => 'required|string|max:4000']);

        $userMessage = trim($this->input);
        $this->input = '';

        ChatMessage::create([
            'chat_session_id' => $this->sessionId,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        $history = $this->messages;
        $this->messages[] = ['role' => 'user', 'content' => $userMessage];

        try {
            $reply = app(ClaudeChatService::class)->chat($userMessage, $history);
        } catch (\Throwable $e) {
            Log::error('HelpChat: API error', ['error' => $e->getMessage()]);
            $reply = 'Sorry, I\'m unable to answer right now. Please try again shortly.';
        }

        ChatMessage::create([
            'chat_session_id' => $this->sessionId,
            'role' => 'assistant',
            'content' => $reply,
        ]);

        $this->messages[] = ['role' => 'assistant', 'content' => $reply];

        $this->dispatch('chat-updated');
    }

    public function clearHistory(): void
    {
        ChatMessage::where('chat_session_id', $this->sessionId)->delete();
        $this->messages = [];
    }
}; ?>

<div class="flex flex-col" style="height: calc(100vh - 8rem);">
    <div class="flex items-center justify-between mb-4">
        <flux:heading size="xl">Help Chat</flux:heading>

        @if(count($messages) > 0)
            <flux:button
                wire:click="clearHistory"
                wire:confirm="Clear the entire conversation history?"
                variant="ghost"
                size="sm"
            >
                Clear history
            </flux:button>
        @endif
    </div>

    {{-- Message list --}}
    <div
        class="flex-1 overflow-y-auto space-y-4 p-4 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 mb-4"
        x-data
        x-init="$el.scrollTop = $el.scrollHeight"
        x-on:chat-updated.window="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
    >
        @forelse($messages as $message)
            <div class="flex {{ $message['role'] === 'user' ? 'justify-end' : 'justify-start' }}">
                @if($message['role'] === 'user')
                    <div class="max-w-[72%] rounded-2xl rounded-br-sm px-4 py-2.5 text-sm leading-relaxed bg-blue-600 text-white">
                        {{ $message['content'] }}
                    </div>
                @else
                    <div class="max-w-[80%] rounded-2xl rounded-bl-sm px-4 py-3 bg-zinc-100 dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 chat-response">
                        {!! Str::markdown($message['content'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                    </div>
                @endif
            </div>
        @empty
            <div class="flex items-center justify-center h-full text-zinc-400 dark:text-zinc-500 text-sm">
                Ask a question about Merlin to get started.
            </div>
        @endforelse

        <div wire:loading wire:target="send" class="flex justify-start">
            <div class="bg-zinc-100 dark:bg-zinc-800 rounded-lg px-4 py-2.5 text-sm text-zinc-500 dark:text-zinc-400">
                Thinking…
            </div>
        </div>
    </div>

    {{-- Input --}}
    <form wire:submit="send" class="flex gap-2">
        <div class="flex-1">
            <flux:input
                wire:model="input"
                placeholder="Ask a question about the app…"
                autocomplete="off"
                wire:loading.attr="disabled"
                wire:target="send"
            />
        </div>
        <flux:button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="send"
        >
            Send
        </flux:button>
    </form>
</div>
