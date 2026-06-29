<?php

use App\Modules\Core\Models\ChatMessage;
use App\Modules\Core\Models\ChatSession;
use App\Modules\Core\Models\User;
use App\Services\ClaudeChatService;
use Livewire\Livewire;

it('redirects guests to login', function (): void {
    $this->get(route('help'))->assertRedirect(route('login'));
});

it('renders the help page for authenticated users', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('help'))
        ->assertOk();
});

it('creates a chat session on first visit', function (): void {
    $user = User::factory()->create();

    expect(ChatSession::where('user_id', $user->id)->exists())->toBeFalse();

    Livewire::actingAs($user)->test('pages.help.index');

    expect(ChatSession::where('user_id', $user->id)->exists())->toBeTrue();
});

it('reuses an existing session', function (): void {
    $user = User::factory()->create();
    $session = ChatSession::create(['user_id' => $user->id]);

    Livewire::actingAs($user)->test('pages.help.index');

    expect(ChatSession::where('user_id', $user->id)->count())->toBe(1);
    expect(ChatSession::where('user_id', $user->id)->first()->id)->toBe($session->id);
});

it('loads existing messages on mount', function (): void {
    $user = User::factory()->create();
    $session = ChatSession::create(['user_id' => $user->id]);

    ChatMessage::create(['chat_session_id' => $session->id, 'role' => 'user', 'content' => 'Hello']);
    ChatMessage::create(['chat_session_id' => $session->id, 'role' => 'assistant', 'content' => 'Hi there!']);

    $component = Livewire::actingAs($user)->test('pages.help.index');

    expect($component->get('messages'))->toHaveCount(2);
    expect($component->get('messages.0.content'))->toBe('Hello');
    expect($component->get('messages.1.content'))->toBe('Hi there!');
});

it('sends a message, persists it, and appends the reply', function (): void {
    $user = User::factory()->create();

    $mockService = Mockery::mock(ClaudeChatService::class);
    $mockService->shouldReceive('chat')
        ->once()
        ->with('How do I upload an invoice?', [])
        ->andReturn('Go to Expenses > Purchase Invoices and click the upload button.');

    app()->instance(ClaudeChatService::class, $mockService);

    $component = Livewire::actingAs($user)->test('pages.help.index');
    $sessionId = $component->get('sessionId');

    $component->set('input', 'How do I upload an invoice?')->call('send');

    expect(ChatMessage::where('chat_session_id', $sessionId)->count())->toBe(2);

    $messages = $component->get('messages');
    expect($messages)->toHaveCount(2);
    expect($messages[0]['role'])->toBe('user');
    expect($messages[1]['role'])->toBe('assistant');
    expect($messages[1]['content'])->toBe('Go to Expenses > Purchase Invoices and click the upload button.');
});

it('validates that the input is required', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages.help.index')
        ->set('input', '')
        ->call('send')
        ->assertHasErrors(['input' => 'required']);
});

it('shows a fallback message when the API fails', function (): void {
    $user = User::factory()->create();

    $mockService = Mockery::mock(ClaudeChatService::class);
    $mockService->shouldReceive('chat')->andThrow(new RuntimeException('API down'));

    app()->instance(ClaudeChatService::class, $mockService);

    $component = Livewire::actingAs($user)
        ->test('pages.help.index')
        ->set('input', 'What is Merlin?')
        ->call('send');

    $messages = $component->get('messages');
    expect(end($messages)['role'])->toBe('assistant');
    expect(end($messages)['content'])->toContain('unable to answer');
});

it('clears history', function (): void {
    $user = User::factory()->create();
    $session = ChatSession::create(['user_id' => $user->id]);

    ChatMessage::create(['chat_session_id' => $session->id, 'role' => 'user', 'content' => 'test']);

    $component = Livewire::actingAs($user)
        ->test('pages.help.index')
        ->call('clearHistory');

    expect(ChatMessage::where('chat_session_id', $session->id)->count())->toBe(0);
    expect($component->get('messages'))->toHaveCount(0);
});
