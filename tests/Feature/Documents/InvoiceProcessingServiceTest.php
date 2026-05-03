<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\User;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\DTO\ExtractedInvoice;
use App\Modules\Purchasing\DTO\ExtractedInvoiceLine;
use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Models\DocumentActivity;
use App\Modules\Purchasing\Models\DocumentLine;
use App\Modules\Purchasing\Services\AccountResolver;
use App\Modules\Purchasing\Services\DocumentTextExtractor;
use App\Modules\Purchasing\Services\ExchangeRateService;
use App\Modules\Purchasing\Services\InvoiceProcessingService;
use App\Modules\Purchasing\Services\LlmService;
use App\Modules\Purchasing\Services\PostingRuleService;
use App\Modules\Purchasing\Services\SupplierResolver;
use App\Modules\Purchasing\Settings\PurchasingSettings;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    $this->actingAs(User::factory()->create());

    // Shared account for account resolver to find
    $this->account = Account::factory()->create([
        'code' => '5210',
        'name' => 'IT & Software',
        'is_active' => true,
        'allow_direct_posting' => true,
    ]);

    // Mock DocumentTextExtractor to avoid actual filesystem/pdftotext calls
    $this->extractorMock = Mockery::mock(DocumentTextExtractor::class);
    $this->extractorMock->allows('extract')->andReturn('fake extracted invoice text');

    // Mock LlmService to avoid live API calls
    $this->llmMock = Mockery::mock(LlmService::class);

    $this->service = new InvoiceProcessingService(
        extractor: $this->extractorMock,
        llm: $this->llmMock,
        supplierResolver: app(SupplierResolver::class),
        accountResolver: app(AccountResolver::class),
        exchangeRateService: app(ExchangeRateService::class),
        postingRuleService: app(PostingRuleService::class),
        currencySettings: app(CurrencySettings::class),
        purchasingSettings: app(PurchasingSettings::class),
    );
});

/**
 * Build a fake ExtractedInvoice with one line.
 *
 * @param  ExtractedInvoiceLine[]  $lines
 */
function fakeExtracted(array $lines = [], float $confidence = 0.95): ExtractedInvoice
{
    return new ExtractedInvoice(
        supplierName: 'Acme Hosting (Pty) Ltd',
        supplierTaxNumber: '4123456789',
        supplierEmail: null,
        supplierPhone: null,
        invoiceNumber: 'INV-2024-001',
        issueDate: Carbon::parse('2024-01-15'),
        dueDate: Carbon::parse('2024-02-14'),
        currency: config('currency.base', 'ZAR'),
        subtotal: 1000.0,
        taxTotal: 150.0,
        total: 1150.0,
        lines: $lines,
        confidence: $confidence,
        warnings: [],
    );
}

function fakeLine(string $description = 'Monthly hosting fee', ?string $accountCode = '5210'): ExtractedInvoiceLine
{
    return new ExtractedInvoiceLine(
        description: $description,
        quantity: 1,
        unitPrice: 1000,
        lineTotal: 1000,
        taxRate: null,
        suggestedAccountCode: $accountCode,
        accountConfidence: 0.92,
        accountReason: 'IT service',
    );
}

/**
 * Attach a fake media item to a document (bypasses actual file storage).
 */
function attachFakeMedia(Document $document): void
{
    Media::create([
        'model_type' => (new Document)->getMorphClass(),
        'model_id' => $document->id,
        'uuid' => Str::uuid(),
        'collection_name' => 'source_document',
        'name' => 'invoice',
        'file_name' => 'invoice.pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'local',
        'conversions_disk' => 'local',
        'size' => 1024,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
    ]);
}

it('creates document lines from extracted invoice data', function (): void {
    $document = Document::factory()->purchaseInvoice()->create();
    attachFakeMedia($document);

    $this->llmMock->allows('extractInvoice')->andReturn(fakeExtracted([fakeLine()]));

    $this->service->process($document);

    expect(DocumentLine::where('document_id', $document->id)->count())->toBe(1);

    $line = DocumentLine::where('document_id', $document->id)->first();
    expect($line->description)->toBe('Monthly hosting fee')
        ->and($line->quantity)->toBe('1.0000')
        ->and($line->unit_price)->toBe('1000.0000');
});

it('sets llm_confidence on the document', function (): void {
    $document = Document::factory()->purchaseInvoice()->create();
    attachFakeMedia($document);

    $this->llmMock->allows('extractInvoice')->andReturn(fakeExtracted([], 0.87));

    $this->service->process($document);

    expect((float) $document->fresh()->llm_confidence)->toBe(0.87);
});

it('sets source to llm_extracted', function (): void {
    $document = Document::factory()->purchaseInvoice()->create(['source' => 'manual']);
    attachFakeMedia($document);

    $this->llmMock->allows('extractInvoice')->andReturn(fakeExtracted());

    $this->service->process($document);

    expect($document->fresh()->source)->toBe('llm_extracted');
});

it('records an llm_extracted activity', function (): void {
    $document = Document::factory()->purchaseInvoice()->create();
    attachFakeMedia($document);

    $this->llmMock->allows('extractInvoice')->andReturn(fakeExtracted([fakeLine()]));

    $this->service->process($document);

    $activity = DocumentActivity::where('document_id', $document->id)
        ->where('activity_type', 'llm_extracted')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->description)->toContain('95%');
});

it('sets the llm account suggestion on lines', function (): void {
    $document = Document::factory()->purchaseInvoice()->create();
    attachFakeMedia($document);

    $this->llmMock->allows('extractInvoice')->andReturn(fakeExtracted([fakeLine()]));

    $this->service->process($document);

    $line = DocumentLine::where('document_id', $document->id)->first();
    expect($line->llm_account_suggestion)->toBe($this->account->id);
});

it('throws when no source document is attached', function (): void {
    $document = Document::factory()->purchaseInvoice()->create();

    expect(fn () => $this->service->process($document))
        ->toThrow(RuntimeException::class, 'No source document');
});

// --- Foreign currency ---

it('leaves exchange rate at 1.0 and no foreign amounts for a ZAR invoice', function (): void {
    $document = Document::factory()->purchaseInvoice()->create();
    attachFakeMedia($document);

    $this->llmMock->allows('extractInvoice')->andReturn(fakeExtracted([fakeLine()]));

    $this->service->process($document);

    $doc = $document->fresh();
    expect($doc->currency)->toBe(config('currency.base', 'ZAR'))
        ->and((float) $doc->exchange_rate)->toBe(1.0)
        ->and($doc->exchange_rate_date)->toBeNull()
        ->and($doc->foreign_total)->toBeNull();
});

// --- VAT / tax handling ---

it('does not double-count VAT when LLM extracts VAT-inclusive line prices with an explicit tax rate', function (): void {
    // Reproduces real bug: invoice total R4085 (VAT-inclusive at 15%).
    // LLM extracted unit prices as shown on invoice (VAT-inclusive) and also
    // set tax_rate = 15 on each line, causing the system to add 15% on top of
    // amounts that already include VAT, producing total = R4697.75 instead of R4085.
    $document = Document::factory()->purchaseInvoice()->create();
    attachFakeMedia($document);

    // Simulate the real invoice: five lines whose totals sum to R4085 (incl. VAT)
    // LLM sets tax_rate=15 on each — these are the VAT-inclusive amounts.
    $vatInclusiveLines = [
        new ExtractedInvoiceLine('Service A', 1, 740.00, 740.00, 15.0, '5210', 0.9, ''),
        new ExtractedInvoiceLine('Service B', 1, 1820.00, 1820.00, 15.0, '5210', 0.9, ''),
        new ExtractedInvoiceLine('Service C', 1, 1610.00, 1610.00, 15.0, '5210', 0.9, ''),
        new ExtractedInvoiceLine('Service D', 1, 130.00, 130.00, 15.0, '5210', 0.9, ''),
        new ExtractedInvoiceLine('Discount', 1, -215.00, -215.00, 15.0, '5210', 0.9, ''),
    ];

    // sum(lineTotal) = 4085, which equals extracted.total → prices are VAT-inclusive
    $extracted = new ExtractedInvoice(
        supplierName: 'Hosting Co',
        supplierTaxNumber: '4123456789',
        supplierEmail: null,
        supplierPhone: null,
        invoiceNumber: 'INV-001',
        issueDate: Carbon::parse('2026-02-01'),
        dueDate: Carbon::parse('2026-03-01'),
        currency: config('currency.base', 'ZAR'),
        subtotal: 3552.17,
        taxTotal: 532.83,
        total: 4085.00,
        lines: $vatInclusiveLines,
        confidence: 0.95,
        warnings: [],
    );

    $this->llmMock->allows('extractInvoice')->andReturn($extracted);
    $this->service->process($document);

    $doc = $document->fresh();

    // The recorded total must match the original invoice — not be inflated by double VAT
    expect((float) $doc->total)->toBe(4085.00);
});

it('sets exchange rate and foreign amounts when invoice is in USD', function (): void {
    // USD rate: 1 ZAR = 0.054674 USD  →  1 USD = 18.29 ZAR (approx)
    Http::fake(['*' => Http::response([
        'result' => 'success',
        'base_code' => config('currency.base', 'ZAR'),
        'conversion_rates' => [config('currency.base', 'ZAR') => 1.0, 'USD' => 0.054674],
    ], 200)]);

    $document = Document::factory()->purchaseInvoice()->create();
    attachFakeMedia($document);

    $usdLine = new ExtractedInvoiceLine(
        description: 'Cloud hosting',
        quantity: 1,
        unitPrice: 100.0,   // $100 USD
        lineTotal: 100.0,
        taxRate: null,
        suggestedAccountCode: '5210',
        accountConfidence: 0.9,
        accountReason: 'IT service',
    );

    $usdExtracted = new ExtractedInvoice(
        supplierName: 'AWS',
        supplierTaxNumber: null,
        supplierEmail: null,
        supplierPhone: null,
        invoiceNumber: 'AWS-001',
        issueDate: Carbon::parse('2024-01-15'),
        dueDate: null,
        currency: 'USD',
        subtotal: 100.0,
        taxTotal: 0.0,
        total: 100.0,
        lines: [$usdLine],
        confidence: 0.95,
        warnings: [],
    );

    $this->llmMock->allows('extractInvoice')->andReturn($usdExtracted);

    $this->service->process($document);

    $doc = $document->fresh();
    $line = DocumentLine::where('document_id', $document->id)->first();

    $expectedRate = round(1 / 0.054674, 6);

    expect($doc->currency)->toBe('USD')
        ->and((float) $doc->exchange_rate)->toBe($expectedRate)
        ->and($doc->exchange_rate_date)->not->toBeNull()
        ->and((bool) $doc->exchange_rate_provisional)->toBeTrue()
        ->and((float) $line->foreign_unit_price)->toBe(100.0)
        ->and((float) $line->foreign_line_total)->toBe(100.0)
        ->and($line->foreign_tax_amount)->not->toBeNull()
        ->and((float) $line->unit_price)->toBe(round(100.0 * $expectedRate, 4));
});
