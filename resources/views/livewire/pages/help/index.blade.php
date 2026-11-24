<?php

use App\Modules\Core\Models\ChatMessage;
use App\Modules\Core\Models\ChatSession;
use App\Services\ClaudeChatService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

new #[Layout('components.layout.app')] class extends Component
{
    #[Locked]
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

<div class="flex flex-col h-[calc(100vh-4rem)] bg-white dark:bg-zinc-900">
    @if(count($messages) === 0)
        {{-- Empty state: heading + input centered together --}}
        <div class="flex-1 flex flex-col items-center justify-center gap-6 px-4">
            <flux:heading size="xl" class="text-zinc-400 dark:text-zinc-500">
                How can I help?
            </flux:heading>

            <form wire:submit="send" class="max-w-3xl mx-auto w-full">
                <div class="flex items-center gap-2 rounded-full border border-zinc-300 dark:border-zinc-600 bg-zinc-50 dark:bg-zinc-800 pl-5 pr-2 py-2 focus-within:ring-2 focus-within:ring-blue-500">
                    <input
                        type="text"
                        wire:model="input"
                        placeholder="Ask a question about the app…"
                        autocomplete="off"
                        wire:loading.attr="disabled"
                        wire:target="send"
                        class="flex-1 bg-transparent border-0 focus:ring-0 text-sm text-zinc-900 dark:text-zinc-100 placeholder-zinc-400"
                    >
                    <flux:button
                        type="submit"
                        icon="arrow-up"
                        variant="primary"
                        size="sm"
                        class="rounded-full"
                        wire:loading.attr="disabled"
                        wire:target="send"
                    />
                </div>
                @error('input') <flux:error class="mt-2 text-center">{{ $message }}</flux:error> @enderror
            </form>
        </div>
    @else
        {{-- Message list --}}
        <div
            class="flex-1 overflow-y-auto"
            x-data
            x-init="$el.scrollTop = $el.scrollHeight"
            x-on:chat-updated.window="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
        >
            <div class="max-w-3xl mx-auto px-4 py-8 space-y-6">
                <div class="flex justify-end -mt-2 mb-2">
                    <flux:button
                        wire:click="clearHistory"
                        wire:confirm="Clear the entire conversation history?"
                        variant="ghost"
                        size="sm"
                    >
                        Clear history
                    </flux:button>
                </div>

                @foreach($messages as $message)
                    @if($message['role'] === 'user')
                        <div class="flex justify-end">
                            <div class="max-w-[80%] rounded-3xl px-4 py-2.5 text-sm leading-relaxed bg-zinc-100 dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100">
                                {{ $message['content'] }}
                            </div>
                        </div>
                    @else
                        <div class="text-sm leading-relaxed text-zinc-900 dark:text-zinc-100 chat-response">
                            {!! Str::markdown($message['content'], ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                        </div>
                    @endif
                @endforeach

                <div wire:loading wire:target="send" class="text-sm text-zinc-400 dark:text-zinc-500">
                    Thinking…
                </div>
            </div>
        </div>

        {{-- Input --}}
        <div class="px-4 pb-6 pt-2">
            <form wire:submit="send" class="max-w-3xl mx-auto w-full">
                <div class="flex items-center gap-2 rounded-full border border-zinc-300 dark:border-zinc-600 bg-zinc-50 dark:bg-zinc-800 pl-5 pr-2 py-2 focus-within:ring-2 focus-within:ring-blue-500">
                    <input
                        type="text"
                        wire:model="input"
                        placeholder="Ask a question about the app…"
                        autocomplete="off"
                        wire:loading.attr="disabled"
                        wire:target="send"
                        class="flex-1 bg-transparent border-0 focus:ring-0 text-sm text-zinc-900 dark:text-zinc-100 placeholder-zinc-400"
                    >
                    <flux:button
                        type="submit"
                        icon="arrow-up"
                        variant="primary"
                        size="sm"
                        class="rounded-full"
                        wire:loading.attr="disabled"
                        wire:target="send"
                    />
                </div>
                @error('input') <flux:error class="mt-2 text-center">{{ $message }}</flux:error> @enderror
            </form>
        </div>
    @endif
</div>
