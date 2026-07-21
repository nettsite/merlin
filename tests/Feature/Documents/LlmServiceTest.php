<?php

use App\Exceptions\LlmApiException;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\LlmLog;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\LlmService;
use App\Modules\Purchasing\DTO\ExtractedInvoice;
use App\Modules\Purchasing\DTO\ExtractedInvoiceLine;
use App\Modules\Purchasing\Settings\PurchasingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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

it('includes the supplier payment-behaviour note in the prompt and parses already_paid from the response', function (): void {
    $responseJson = str_replace('"confidence": 0.95', '"confidence": 0.95, "already_paid": true', $this->fixtureJson);

    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse($responseJson))]);

    $note = 'This supplier always sends the invoice already paid — a zero balance means record a payment too.';
    $result = $this->service->extractInvoice('sample invoice text', [], null, $note);

    expect($result->alreadyPaid)->toBeTrue();

    Http::assertSent(function ($request) use ($note) {
        $sentPrompt = $request->data()['messages'][0]['content'];

        return str_contains($sentPrompt, $note)
            && str_contains($sentPrompt, 'Supplier Payment Behaviour');
    });
});

it('leaves already_paid null when no supplier payment-behaviour note is configured', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse($this->fixtureJson))]);

    $result = $this->service->extractInvoice('sample invoice text');

    expect($result->alreadyPaid)->toBeNull();

    Http::assertSent(function ($request) {
        $sentPrompt = $request->data()['messages'][0]['content'];

        return ! str_contains($sentPrompt, 'Supplier Payment Behaviour');
    });
});

it('logs every api call to llm_logs', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse($this->fixtureJson))]);

    $this->service->extractInvoice('sample invoice text');

    expect(LlmLog::count())->toBe(1);

    $log = LlmLog::first();
    expect($log->model)->toBe(config('services.anthropic.model_fast'))
        ->and($log->prompt_tokens)->toBe(500)
        ->and($log->completion_tokens)->toBe(200)
        ->and($log->confidence)->toBe(0.95)
        ->and($log->error)->toBeNull();
});

it('persists the extracted confidence to the log', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse($this->fixtureJson))]);

    $this->service->extractInvoice('sample invoice text');

    expect(LlmLog::first()->confidence)->toBe(0.95);
});

it('falls back when the fast model confidence is below the threshold', function (): void {
    $lowConfidence = json_decode($this->fixtureJson, true);
    $lowConfidence['confidence'] = 0.50;

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(anthropicResponse((string) json_encode($lowConfidence)))
            ->push(anthropicResponse($this->fixtureJson)),
    ]);

    $result = $this->service->extractInvoice('text');

    expect($result->confidence)->toBe(0.95)
        ->and(LlmLog::count())->toBe(2)
        ->and(LlmLog::query()->orderBy('id')->pluck('model')->all())->toBe([
            config('services.anthropic.model_fast'),
            config('services.anthropic.model'),
        ])
        // The rejected fast attempt still records its (low) confidence, making
        // the fallback reason visible in the logs.
        ->and(LlmLog::query()->orderBy('id')->first()->confidence)->toBe(0.50);
});

it('respects the configurable fallback confidence threshold', function (): void {
    // Fixture confidence is 0.95; raising the threshold above it forces a fallback.
    app(PurchasingSettings::class)->fill(['fallback_confidence' => 0.99])->save();

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(anthropicResponse($this->fixtureJson))
            ->push(anthropicResponse($this->fixtureJson)),
    ]);

    $this->service->extractInvoice('text');

    expect(LlmLog::count())->toBe(2);
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
        ->toThrow(RuntimeException::class, 'invalid JSON');
});

it('strips markdown code fences before parsing JSON', function (): void {
    // Real-world: Claude sometimes wraps JSON output in ```json … ``` fences.
    // parseJsonResponse must strip them, otherwise json_decode fails.
    $wrapped = "```json\n".$this->fixtureJson."\n```";

    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse($wrapped))]);

    $result = $this->service->extractInvoice('text');

    expect($result)->toBeInstanceOf(ExtractedInvoice::class)
        ->and($result->supplierName)->toBe('Acme Hosting (Pty) Ltd');
});

it('records the loggable model on llm_logs when provided', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse($this->fixtureJson))]);

    $doc = Document::factory()->purchaseInvoice()->create();

    $this->service->extractInvoice('text', loggable: $doc);

    $log = LlmLog::first();
    expect($log->loggable_id)->toBe($doc->id)
        // Morph map alias, not FQCN — verifies enforceMorphMap is active.
        ->and($log->loggable_type)->toBe('document');
});

it('throws LlmApiException when response is missing content field', function (): void {
    // Malformed envelope: 200 OK but no content[0].text. Service must not
    // crash with "Undefined array key" — it must raise LlmApiException.
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'id' => 'msg_x',
            'type' => 'message',
            'content' => [],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 0],
        ]),
    ]);

    expect(fn () => $this->service->extractInvoice('text'))
        ->toThrow(LlmApiException::class);
});

it('records duration_ms on successful calls', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse($this->fixtureJson))]);

    $this->service->extractInvoice('text');

    $log = LlmLog::first();
    expect($log->duration_ms)->toBeGreaterThanOrEqual(0);
});

it('uses the configured fast model for the first api call', function (): void {
    config(['services.anthropic.model_fast' => 'claude-test-model']);
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse($this->fixtureJson))]);

    $this->service->extractInvoice('text');

    Http::assertSent(fn ($request) => $request['model'] === 'claude-test-model');
    expect(LlmLog::first()->model)->toBe('claude-test-model');
});

it('wraps connection failures in LlmApiException and logs them', function (): void {
    Http::fake([
        'api.anthropic.com/*' => fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out'
        ),
    ]);

    expect(fn () => $this->service->extractInvoice('text'))
        ->toThrow(LlmApiException::class, 'timed out');

    $log = LlmLog::first();
    expect($log)->not->toBeNull()
        ->and($log->error)->toContain('timed out');
});

it('omits base64 document data from the logged request payload', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse('extracted text'))]);

    $path = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($path, '%PDF-1.4 fake pdf body');

    try {
        $this->service->extractRawTextFromPdf($path);
    } finally {
        unlink($path);
    }

    $payload = LlmLog::first()->request_payload;
    $data = $payload['messages'][0]['content'][0]['source']['data'];

    expect($data)->toStartWith('[base64 omitted:')
        ->and(json_encode($payload))->not->toContain(base64_encode('%PDF-1.4 fake pdf body'));
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

it('does not fall back when the fast model extraction reconciles', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse($this->fixtureJson))]);

    $this->service->extractInvoice('text');

    expect(LlmLog::count())->toBe(1)
        ->and(LlmLog::first()->model)->toBe(config('services.anthropic.model_fast'));
});

it('falls back to the configured model when the fast model returns invalid json', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(anthropicResponse('not json at all'))
            ->push(anthropicResponse($this->fixtureJson)),
    ]);

    $result = $this->service->extractInvoice('text');

    expect($result->supplierName)->toBe('Acme Hosting (Pty) Ltd')
        ->and(LlmLog::count())->toBe(2)
        ->and(LlmLog::query()->orderBy('id')->pluck('model')->all())->toBe([
            config('services.anthropic.model_fast'),
            config('services.anthropic.model'),
        ]);
});

it('falls back to the configured model when line totals do not reconcile', function (): void {
    $unreconciled = json_decode($this->fixtureJson, true);
    $unreconciled['lines'][0]['line_total'] = 500.00;

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(anthropicResponse((string) json_encode($unreconciled)))
            ->push(anthropicResponse($this->fixtureJson)),
    ]);

    $result = $this->service->extractInvoice('text');

    expect($result->subtotal)->toBe(1000.00)
        ->and($result->lines[0]->lineTotal)->toBe(1000.00)
        ->and(LlmLog::count())->toBe(2)
        ->and(LlmLog::query()->orderBy('id')->pluck('model')->all())->toBe([
            config('services.anthropic.model_fast'),
            config('services.anthropic.model'),
        ]);
});

it('falls back when the lines cannot reconstruct the total', function (): void {
    // Lines gross up to 1150 but the header total is 9999 — the lines can't
    // account for the stated total, so the fast result must not be trusted.
    $brokenTotal = json_decode($this->fixtureJson, true);
    $brokenTotal['total'] = 9999.00;

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(anthropicResponse((string) json_encode($brokenTotal)))
            ->push(anthropicResponse($this->fixtureJson)),
    ]);

    $result = $this->service->extractInvoice('text');

    expect($result->total)->toBe(1150.00)
        ->and(LlmLog::count())->toBe(2);
});

it('falls back when the fast model returns no line items', function (): void {
    $noLines = json_decode($this->fixtureJson, true);
    $noLines['lines'] = [];

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(anthropicResponse((string) json_encode($noLines)))
            ->push(anthropicResponse($this->fixtureJson)),
    ]);

    $result = $this->service->extractInvoice('text');

    expect($result->lines)->toHaveCount(1)
        ->and(LlmLog::count())->toBe(2);
});

it('accepts the fast model result when line prices are VAT-inclusive', function (): void {
    // Genuine VAT-inclusive extraction: line totals sum to the gross total, not
    // the ex-VAT subtotal. InvoiceProcessingService back-calculates this shape,
    // so it must NOT trigger a fallback.
    $vatInclusive = json_decode($this->fixtureJson, true);
    $vatInclusive['lines'][0]['unit_price'] = 1150.00;
    $vatInclusive['lines'][0]['line_total'] = 1150.00;

    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse((string) json_encode($vatInclusive)))]);

    $result = $this->service->extractInvoice('text');

    expect($result->lines[0]->lineTotal)->toBe(1150.00)
        ->and(LlmLog::count())->toBe(1)
        ->and(LlmLog::first()->model)->toBe(config('services.anthropic.model_fast'));
});

it('accepts ex-VAT lines (incl. shipping) that gross up to the total on a VAT-inclusive invoice', function (): void {
    // Regression for invoice #12255 (ITAD): VAT-inclusive invoice where the
    // header subtotal (700) excludes shipping (129). The fast model correctly
    // returns ex-VAT lines — monitor, kettle, and a shipping line — that gross
    // up at 15% to the 829 total. This must reconcile (no fallback), so a
    // shipping line the stronger model tends to drop is not lost.
    $itad = [
        'supplier_name' => 'ITAD AFRICA (PTY) LTD',
        'supplier_tax_number' => '4830301166',
        'invoice_number' => '#12255',
        'issue_date' => '2026-08-06',
        'due_date' => null,
        'currency' => 'ZAR',
        'subtotal' => 700.00,
        'tax_total' => 108.13,
        'total' => 829.00,
        'confidence' => 0.85,
        'warnings' => [],
        'lines' => [
            ['description' => 'Used 22 Inch Wide Lcd Monitor', 'quantity' => 1, 'unit_price' => 608.70, 'line_total' => 608.70, 'tax_rate' => 15.0, 'suggested_account_code' => '5999', 'account_confidence' => 0.6, 'account_reason' => 'IT equipment'],
            ['description' => 'Kettle Power Cord - Single', 'quantity' => 1, 'unit_price' => 0.0, 'line_total' => 0.0, 'tax_rate' => 15.0, 'suggested_account_code' => '5300', 'account_confidence' => 0.7, 'account_reason' => 'Accessory'],
            ['description' => 'Shipping (Economy Door to Door)', 'quantity' => 1, 'unit_price' => 112.17, 'line_total' => 112.17, 'tax_rate' => 15.0, 'suggested_account_code' => '5999', 'account_confidence' => 0.6, 'account_reason' => 'Delivery'],
        ],
    ];

    Http::fake(['api.anthropic.com/*' => Http::response(anthropicResponse((string) json_encode($itad)))]);

    $result = $this->service->extractInvoice('text');

    expect($result->lines)->toHaveCount(3)
        ->and($result->lines[2]->description)->toContain('Shipping')
        ->and(LlmLog::count())->toBe(1)
        ->and(LlmLog::first()->model)->toBe(config('services.anthropic.model_fast'));
});

it('keeps a reconciling fast result when the strong model result does not reconcile', function (): void {
    // Fast result reconciles but is below the confidence threshold, so we try the
    // strong model — which drops a line and fails to reconstruct the total. The
    // reconciling fast result must win over the broken strong one.
    $fast = json_decode($this->fixtureJson, true);
    $fast['confidence'] = 0.50;

    $strongBroken = json_decode($this->fixtureJson, true);
    $strongBroken['lines'][0]['line_total'] = 200.00; // grosses to 230, not 1150

    Http::fake([
        'api.anthropic.com/*' => Http::sequence()
            ->push(anthropicResponse((string) json_encode($fast)))
            ->push(anthropicResponse((string) json_encode($strongBroken))),
    ]);

    $result = $this->service->extractInvoice('text');

    expect($result->lines[0]->lineTotal)->toBe(1000.00)
        ->and($result->confidence)->toBe(0.50)
        ->and(LlmLog::count())->toBe(2);
});
