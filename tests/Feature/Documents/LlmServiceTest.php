<?php

use App\Exceptions\LlmApiException;
use App\Modules\Core\Models\User;
use App\Modules\Purchasing\DTO\ExtractedInvoice;
use App\Modules\Purchasing\DTO\ExtractedInvoiceLine;
use App\Modules\Purchasing\Models\LlmLog;
use App\Modules\Purchasing\Services\LlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
    $this->service = app(LlmService::class);
    $this->fixtureJson = file_get_contents(base_path('tests/Fixtures/extracted-invoice.json'));
});

/**
 * Build a fake Anthropic API response envelope wrapping a given text body.
 *
 * @return array<string, mixed>
 */
function anthropicResponse(string $text, int $inputTokens = 500, int $outputTokens = 200): array
{
    return [
        'id' => 'msg_test',
        'type' => 'message',
        'role' => 'assistant',
        'content' => [['type' => 'text', 'text' => $text]],
        'model' => 'claude-sonnet-4-20250514',
        'stop_reason' => 'end_turn',
        'usage' => [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ],
    ];
}

it('extracts header fields from a sample invoice', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse($this->fixtureJson))]);

    $result = $this->service->extractInvoice('sample invoice text');

    expect($result)->toBeInstanceOf(ExtractedInvoice::class)
        ->and($result->supplierName)->toBe('Acme Hosting (Pty) Ltd')
        ->and($result->supplierTaxNumber)->toBe('4123456789')
        ->and($result->invoiceNumber)->toBe('INV-2024-001234')
        ->and($result->issueDate?->format('Y-m-d'))->toBe('2024-01-15')
        ->and($result->dueDate?->format('Y-m-d'))->toBe('2024-02-14')
        ->and($result->currency)->toBe('ZAR')
        ->and($result->subtotal)->toBe(1000.00)
        ->and($result->taxTotal)->toBe(150.00)
        ->and($result->total)->toBe(1150.00)
        ->and($result->confidence)->toBe(0.95)
        ->and($result->warnings)->toBeEmpty();
});

it('extracts line items with account suggestions', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse($this->fixtureJson))]);

    $result = $this->service->extractInvoice('sample invoice text');

    expect($result->lines)->toHaveCount(1);

    $line = $result->lines[0];
    expect($line)->toBeInstanceOf(ExtractedInvoiceLine::class)
        ->and($line->description)->toBe('Monthly hosting fee - January 2024')
        ->and($line->quantity)->toBe(1.0)
        ->and($line->unitPrice)->toBe(1000.00)
        ->and($line->lineTotal)->toBe(1000.00)
        ->and($line->suggestedAccountCode)->toBe('5210')
        ->and($line->accountConfidence)->toBe(0.92);
});

it('logs every api call to llm_logs', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse($this->fixtureJson))]);

    $this->service->extractInvoice('sample invoice text');

    expect(LlmLog::count())->toBe(1);

    $log = LlmLog::first();
    expect($log->model)->toBe('claude-sonnet-4-20250514')
        ->and($log->prompt_tokens)->toBe(500)
        ->and($log->completion_tokens)->toBe(200)
        ->and($log->error)->toBeNull();
});

it('throws LlmApiException when the api returns an error', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(
            ['error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']],
            529
        ),
    ]);

    expect(fn () => $this->service->extractInvoice('text'))
        ->toThrow(LlmApiException::class, 'Overloaded');
});

it('logs failed api calls', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(
            ['error' => ['type' => 'invalid_api_key', 'message' => 'Invalid API key']],
            401
        ),
    ]);

    try {
        $this->service->extractInvoice('text');
    } catch (LlmApiException) {
    }

    $log = LlmLog::first();
    expect($log)->not->toBeNull()
        ->and($log->error)->toContain('Invalid API key');
});

it('throws a RuntimeException when the llm returns invalid json', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(
            anthropicResponse('This is not JSON at all.')
        ),
    ]);

    expect(fn () => $this->service->extractInvoice('text'))
        ->toThrow(\RuntimeException::class, 'invalid JSON');
});

it('parses null dates gracefully', function (): void {
    $json = json_encode(array_merge(
        json_decode($this->fixtureJson, true),
        ['issue_date' => null, 'due_date' => null]
    ));

    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse($json))]);

    $result = $this->service->extractInvoice('text');

    expect($result->issueDate)->toBeNull()
        ->and($result->dueDate)->toBeNull();
});
