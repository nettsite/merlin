<?php

use App\Ai\Agents\InvoiceExtractionAgent;
use App\Ai\Agents\PdfVisionExtractionAgent;
use App\Exceptions\LlmApiException;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\LlmLog;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\LlmService;
use App\Modules\Purchasing\DTO\ExtractedInvoice;
use App\Modules\Purchasing\DTO\ExtractedInvoiceLine;
use App\Modules\Purchasing\Settings\PurchasingSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\StructuredTextResponse;
use Laravel\Ai\Responses\TextResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
    $this->service = app(LlmService::class);
    $this->fixture = json_decode(file_get_contents(base_path('tests/Fixtures/extracted-invoice.json')), true);
});

/**
 * Build a fake structured agent response wrapping the given extracted-invoice array.
 *
 * @param  array<string, mixed>  $data
 */
function fakeInvoiceResponse(array $data, int $inputTokens = 500, int $outputTokens = 200): StructuredTextResponse
{
    return new StructuredTextResponse(
        $data,
        (string) json_encode($data),
        new Usage($inputTokens, $outputTokens),
        new Meta('anthropic', 'fake-model'),
    );
}

it('extracts header fields from a sample invoice', function (): void {
    InvoiceExtractionAgent::fake([fakeInvoiceResponse($this->fixture)]);

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
    InvoiceExtractionAgent::fake([fakeInvoiceResponse($this->fixture)]);

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
    $data = array_merge($this->fixture, ['already_paid' => true]);
    InvoiceExtractionAgent::fake([fakeInvoiceResponse($data)]);

    $note = 'This supplier always sends the invoice already paid — a zero balance means record a payment too.';
    $result = $this->service->extractInvoice('sample invoice text', [], null, $note);

    expect($result->alreadyPaid)->toBeTrue();

    InvoiceExtractionAgent::assertPrompted(
        fn ($prompt): bool => str_contains($prompt->prompt, $note) && str_contains($prompt->prompt, 'Supplier Payment Behaviour')
    );
});

it('leaves already_paid null when no supplier payment-behaviour note is configured', function (): void {
    InvoiceExtractionAgent::fake([fakeInvoiceResponse($this->fixture)]);

    $result = $this->service->extractInvoice('sample invoice text');

    expect($result->alreadyPaid)->toBeNull();

    InvoiceExtractionAgent::assertPrompted(
        fn ($prompt): bool => ! str_contains($prompt->prompt, 'Supplier Payment Behaviour')
    );
});

it('logs every api call to llm_logs', function (): void {
    InvoiceExtractionAgent::fake([fakeInvoiceResponse($this->fixture)]);

    $this->service->extractInvoice('sample invoice text');

    expect(LlmLog::count())->toBe(1);

    $log = LlmLog::first();
    expect($log->model)->toBe(config('ai.providers.anthropic.models.fast'))
        ->and($log->prompt_tokens)->toBe(500)
        ->and($log->completion_tokens)->toBe(200)
        ->and($log->confidence)->toBe(0.95)
        ->and($log->error)->toBeNull();
});

it('persists the extracted confidence to the log', function (): void {
    InvoiceExtractionAgent::fake([fakeInvoiceResponse($this->fixture)]);

    $this->service->extractInvoice('sample invoice text');

    expect(LlmLog::first()->confidence)->toBe(0.95);
});

it('falls back when the fast model confidence is below the threshold', function (): void {
    $lowConfidence = array_merge($this->fixture, ['confidence' => 0.50]);

    InvoiceExtractionAgent::fake([
        fakeInvoiceResponse($lowConfidence),
        fakeInvoiceResponse($this->fixture),
    ]);

    $result = $this->service->extractInvoice('text');

    expect($result->confidence)->toBe(0.95)
        ->and(LlmLog::count())->toBe(2)
        ->and(LlmLog::query()->orderBy('id')->pluck('model')->all())->toBe([
            config('ai.providers.anthropic.models.fast'),
            config('ai.providers.anthropic.models.strong'),
        ])
        // The rejected fast attempt still records its (low) confidence, making
        // the fallback reason visible in the logs.
        ->and(LlmLog::query()->orderBy('id')->first()->confidence)->toBe(0.50);
});

it('respects the configurable fallback confidence threshold', function (): void {
    // Fixture confidence is 0.95; raising the threshold above it forces a fallback.
    app(PurchasingSettings::class)->fill(['fallback_confidence' => 0.99])->save();

    InvoiceExtractionAgent::fake([
        fakeInvoiceResponse($this->fixture),
        fakeInvoiceResponse($this->fixture),
    ]);

    $this->service->extractInvoice('text');

    expect(LlmLog::count())->toBe(2);
});

it('throws LlmApiException when the api returns an error', function (): void {
    InvoiceExtractionAgent::fake(
        fn () => throw fakeAnthropicRequestException(['error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']], 529)
    );

    expect(fn () => $this->service->extractInvoice('text'))
        ->toThrow(LlmApiException::class, 'Overloaded');
});

it('logs failed api calls', function (): void {
    InvoiceExtractionAgent::fake(
        fn () => throw fakeAnthropicRequestException(['error' => ['type' => 'invalid_api_key', 'message' => 'Invalid API key']], 401)
    );

    try {
        $this->service->extractInvoice('text');
    } catch (LlmApiException) {
    }

    $log = LlmLog::first();
    expect($log)->not->toBeNull()
        ->and($log->error)->toContain('Invalid API key');
});

it('throws a RuntimeException when the llm returns invalid json', function (): void {
    // Same invalid text for every call (fast and strong both fail the same way).
    InvoiceExtractionAgent::fake(
        fn () => new TextResponse('This is not JSON at all.', new Usage(500, 200), new Meta('anthropic', 'fake-model'))
    );

    expect(fn () => $this->service->extractInvoice('text'))
        ->toThrow(RuntimeException::class, 'invalid JSON');
});

it('records the loggable model on llm_logs when provided', function (): void {
    InvoiceExtractionAgent::fake([fakeInvoiceResponse($this->fixture)]);

    $doc = Document::factory()->purchaseInvoice()->create();

    $this->service->extractInvoice('text', loggable: $doc);

    $log = LlmLog::first();
    expect($log->loggable_id)->toBe($doc->id)
        // Morph map alias, not FQCN — verifies enforceMorphMap is active.
        ->and($log->loggable_type)->toBe('document');
});

it('records duration_ms on successful calls', function (): void {
    InvoiceExtractionAgent::fake([fakeInvoiceResponse($this->fixture)]);

    $this->service->extractInvoice('text');

    $log = LlmLog::first();
    expect($log->duration_ms)->toBeGreaterThanOrEqual(0);
});

it('uses the configured fast model for the first api call', function (): void {
    config(['ai.providers.anthropic.models.fast' => 'claude-test-model']);
    InvoiceExtractionAgent::fake([fakeInvoiceResponse($this->fixture)]);

    $this->service->extractInvoice('text');

    InvoiceExtractionAgent::assertPromptedTimes(1);
    expect(LlmLog::first()->model)->toBe('claude-test-model');
});

it('wraps connection failures in LlmApiException and logs them', function (): void {
    InvoiceExtractionAgent::fake(
        fn () => throw ProviderConnectionException::forProvider('anthropic', 0, new RuntimeException('cURL error 28: Operation timed out'))
    );

    expect(fn () => $this->service->extractInvoice('text'))
        ->toThrow(LlmApiException::class, 'timed out');

    $log = LlmLog::first();
    expect($log)->not->toBeNull()
        ->and($log->error)->toContain('timed out');
});

it('does not embed the pdf file contents in the logged request payload', function (): void {
    // The SDK sends the PDF as a file reference (Laravel\Ai\Files\Document),
    // never as raw base64 in our own request log, so there is nothing to redact.
    PdfVisionExtractionAgent::fake(['extracted text']);

    $path = tempnam(sys_get_temp_dir(), 'pdf');
    file_put_contents($path, '%PDF-1.4 fake pdf body');

    try {
        $this->service->extractRawTextFromPdf($path);
    } finally {
        unlink($path);
    }

    $payload = LlmLog::first()->request_payload;

    expect(json_encode($payload))->not->toContain(base64_encode('%PDF-1.4 fake pdf body'));
});

it('parses null dates gracefully', function (): void {
    $data = array_merge($this->fixture, ['issue_date' => null, 'due_date' => null]);
    InvoiceExtractionAgent::fake([fakeInvoiceResponse($data)]);

    $result = $this->service->extractInvoice('text');

    expect($result->issueDate)->toBeNull()
        ->and($result->dueDate)->toBeNull();
});

it('does not fall back when the fast model extraction reconciles', function (): void {
    InvoiceExtractionAgent::fake([fakeInvoiceResponse($this->fixture)]);

    $this->service->extractInvoice('text');

    expect(LlmLog::count())->toBe(1)
        ->and(LlmLog::first()->model)->toBe(config('ai.providers.anthropic.models.fast'));
});

it('falls back to the configured model when the fast model returns invalid json', function (): void {
    InvoiceExtractionAgent::fake([
        new TextResponse('not json at all', new Usage(500, 200), new Meta('anthropic', 'fake-model')),
        fakeInvoiceResponse($this->fixture),
    ]);

    $result = $this->service->extractInvoice('text');

    expect($result->supplierName)->toBe('Acme Hosting (Pty) Ltd')
        ->and(LlmLog::count())->toBe(2)
        ->and(LlmLog::query()->orderBy('id')->pluck('model')->all())->toBe([
            config('ai.providers.anthropic.models.fast'),
            config('ai.providers.anthropic.models.strong'),
        ]);
});

it('falls back to the configured model when line totals do not reconcile', function (): void {
    $unreconciled = $this->fixture;
    $unreconciled['lines'][0]['line_total'] = 500.00;

    InvoiceExtractionAgent::fake([
        fakeInvoiceResponse($unreconciled),
        fakeInvoiceResponse($this->fixture),
    ]);

    $result = $this->service->extractInvoice('text');

    expect($result->subtotal)->toBe(1000.00)
        ->and($result->lines[0]->lineTotal)->toBe(1000.00)
        ->and(LlmLog::count())->toBe(2)
        ->and(LlmLog::query()->orderBy('id')->pluck('model')->all())->toBe([
            config('ai.providers.anthropic.models.fast'),
            config('ai.providers.anthropic.models.strong'),
        ]);
});

it('falls back when the lines cannot reconstruct the total', function (): void {
    // Lines gross up to 1150 but the header total is 9999 — the lines can't
    // account for the stated total, so the fast result must not be trusted.
    $brokenTotal = array_merge($this->fixture, ['total' => 9999.00]);

    InvoiceExtractionAgent::fake([
        fakeInvoiceResponse($brokenTotal),
        fakeInvoiceResponse($this->fixture),
    ]);

    $result = $this->service->extractInvoice('text');

    expect($result->total)->toBe(1150.00)
        ->and(LlmLog::count())->toBe(2);
});

it('falls back when the fast model returns no line items', function (): void {
    $noLines = array_merge($this->fixture, ['lines' => []]);

    InvoiceExtractionAgent::fake([
        fakeInvoiceResponse($noLines),
        fakeInvoiceResponse($this->fixture),
    ]);

    $result = $this->service->extractInvoice('text');

    expect($result->lines)->toHaveCount(1)
        ->and(LlmLog::count())->toBe(2);
});

it('accepts the fast model result when line prices are VAT-inclusive', function (): void {
    // Genuine VAT-inclusive extraction: line totals sum to the gross total, not
    // the ex-VAT subtotal. InvoiceProcessingService back-calculates this shape,
    // so it must NOT trigger a fallback.
    $vatInclusive = $this->fixture;
    $vatInclusive['lines'][0]['unit_price'] = 1150.00;
    $vatInclusive['lines'][0]['line_total'] = 1150.00;

    InvoiceExtractionAgent::fake([fakeInvoiceResponse($vatInclusive)]);

    $result = $this->service->extractInvoice('text');

    expect($result->lines[0]->lineTotal)->toBe(1150.00)
        ->and(LlmLog::count())->toBe(1)
        ->and(LlmLog::first()->model)->toBe(config('ai.providers.anthropic.models.fast'));
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

    InvoiceExtractionAgent::fake([fakeInvoiceResponse($itad)]);

    $result = $this->service->extractInvoice('text');

    expect($result->lines)->toHaveCount(3)
        ->and($result->lines[2]->description)->toContain('Shipping')
        ->and(LlmLog::count())->toBe(1)
        ->and(LlmLog::first()->model)->toBe(config('ai.providers.anthropic.models.fast'));
});

it('keeps a reconciling fast result when the strong model result does not reconcile', function (): void {
    // Fast result reconciles but is below the confidence threshold, so we try the
    // strong model — which drops a line and fails to reconstruct the total. The
    // reconciling fast result must win over the broken strong one.
    $fast = array_merge($this->fixture, ['confidence' => 0.50]);

    $strongBroken = $this->fixture;
    $strongBroken['lines'][0]['line_total'] = 200.00; // grosses to 230, not 1150

    InvoiceExtractionAgent::fake([
        fakeInvoiceResponse($fast),
        fakeInvoiceResponse($strongBroken),
    ]);

    $result = $this->service->extractInvoice('text');

    expect($result->lines[0]->lineTotal)->toBe(1000.00)
        ->and($result->confidence)->toBe(0.50)
        ->and(LlmLog::count())->toBe(2);
});
