<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ClaudeChatService
{
    private string $apiKey;

    private string $model;

    private const API_URL = 'https://api.anthropic.com/v1/messages';

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.key');
        $this->model = config('services.anthropic.model', 'claude-sonnet-4-6');
    }

    /**
     * @param  array<int, array{role: string, content: string}>  $history  Prior turns, oldest first
     */
    public function chat(string $userMessage, array $history = []): string
    {
        $docs = $this->loadDocs();

        $messages = [...$history, ['role' => 'user', 'content' => $userMessage]];

        $response = Http::timeout(60)
            ->withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'anthropic-beta' => 'prompt-caching-2024-07-31',
            ])
            ->post(self::API_URL, [
                'model' => $this->model,
                'max_tokens' => 1024,
                'system' => [
                    [
                        'type' => 'text',
                        'text' => $this->systemPrompt($docs),
                        'cache_control' => ['type' => 'ephemeral'],
                    ],
                ],
                'messages' => $messages,
            ]);

        if ($response->failed()) {
            Log::error('ClaudeChatService: API error', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException('Chat API request failed: '.$response->status());
        }

        return $response->json('content.0.text') ?? 'Sorry, I could not generate a response.';
    }

    private function loadDocs(): string
    {
        $parts = [];

        foreach (['user-guide' => 'User Guide', 'system-guide' => 'System / Developer Guide'] as $file => $heading) {
            if (Storage::disk('local')->exists("docs/{$file}.md")) {
                $parts[] = "# {$heading}\n\n".Storage::disk('local')->get("docs/{$file}.md");
            }
        }

        if ($parts === []) {
            return 'Documentation has not been synced yet. Run `php artisan docs:sync`.';
        }

        return implode("\n\n---\n\n", $parts);
    }

    private function systemPrompt(string $docs): string
    {
        return <<<EOT
You are a help assistant for Merlin, a business management application.

Answer questions ONLY using the documentation provided below. If the answer is not covered in the documentation, say so explicitly — do not guess or speculate about features, settings, or behaviour that are not mentioned.

When referencing specific features, mention where to find them in the app (for example: "Go to Expenses > Purchase Invoices").

Keep answers concise and practical.

--- DOCUMENTATION ---

{$docs}
EOT;
    }
}
