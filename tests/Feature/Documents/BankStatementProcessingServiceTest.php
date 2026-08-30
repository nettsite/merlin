<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\DTO\ExtractedBankStatement;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\BankStatementProcessingService;
use App\Modules\Core\Services\DocumentService;
use App\Modules\Core\Services\DocumentTextExtractor;
use App\Modules\Core\Services\LlmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
    $this->extractorMock = Mockery::mock(DocumentTextExtractor::class);
    $this->extractorMock->allows('extract')->andReturn('fake extracted statement text');
    $this->llmMock = Mockery::mock(LlmService::class);
    $this->service = new BankStatementProcessingService($this->extractorMock, $this->llmMock);

    // process() dispatches GenerateBankTemplateHints on a successful extraction,
    // which resolves a real LlmService from the container — fake the queue so
    // that job never actually runs against the live Anthropic API in tests.
    Queue::fake();
});

function attachFakeStatementMedia(Document $document): void
{
    Media::create([
        'model_type' => (new Document)->getMorphClass(),
        'model_id' => $document->id,
        'uuid' => Str::uuid(),
        'collection_name' => 'source_document',
        'name' => 'statement',
        'file_name' => 'statement.pdf',
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

function fakeStatement(array $transactions, array $overrides = []): ExtractedBankStatement
{
    return ExtractedBankStatement::fromArray(array_merge([
        'bank_name' => 'Test Bank',
        'account_name' => 'Cheque Account',
        'statement_number' => 'STMT-100',
        'period_from' => '2026-03-01',
        'period_to' => '2026-03-31',
        'opening_balance' => 1000.00,
        'closing_balance' => 1000.00 + array_sum(array_map(
            fn ($t) => ($t['credit'] ?? 0) - ($t['debit'] ?? 0),
            $transactions,
        )),
        'currency' => 'ZAR',
        'transactions' => $transactions,
        'confidence' => 0.95,
        'warnings' => [],
    ], $overrides));
}

it('creates one document line per extracted transaction and does not post to the ledger', function (): void {
    $statement = Document::create([
        'document_type' => 'bank_statement',
        'direction' => 'inbound',
        'status' => 'queued',
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'contra_account_id' => Account::factory()->create()->id,
        'source' => 'upload',
    ]);
    attachFakeStatementMedia($statement);

    $this->llmMock->allows('extractBankStatement')->andReturn(fakeStatement([
        ['transaction_date' => '2026-03-05', 'description' => 'Client payment', 'credit' => 1500.00],
        ['transaction_date' => '2026-03-10', 'description' => 'Bank fee', 'debit' => 50.00],
    ]));

    $before = (int) DB::table('postings')->count();

    $this->service->process($statement);

    $statement->refresh();
    expect($statement->lines)->toHaveCount(2)
        ->and((float) $statement->lines[0]->unit_price)->toBe(1500.0)
        ->and((float) $statement->lines[1]->unit_price)->toBe(-50.0)
        ->and((float) $statement->metadata['opening_balance'])->toBe(1000.0)
        ->and(DB::table('postings')->count())->toBe($before); // extraction never posts
});

it('links a credit line to the invoice number it mentions, without creating a payment', function (): void {
    $invoice = Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'sent',
        'document_number' => 'SINV-2026-00042',
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'total' => 1500.00,
        'balance_due' => 1500.00,
        'source' => 'manual',
    ]);

    $statement = Document::create([
        'document_type' => 'bank_statement',
        'direction' => 'inbound',
        'status' => 'queued',
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'contra_account_id' => Account::factory()->create()->id,
        'source' => 'upload',
    ]);
    attachFakeStatementMedia($statement);

    $this->llmMock->allows('extractBankStatement')->andReturn(fakeStatement([
        ['transaction_date' => '2026-03-05', 'description' => 'SINV-2026-00042 payment', 'credit' => 1500.00, 'suggested_invoice_number' => 'SINV-2026-00042'],
    ]));

    $this->service->process($statement);

    $line = $statement->fresh()->lines->first();
    expect($line->linked_document_id)->toBe($invoice->id)
        ->and($invoice->fresh()->status)->toBe('sent') // untouched — a suggestion, not a settlement
        ->and((float) $invoice->fresh()->balance_due)->toBe(1500.0);
});

it('reprocessing replaces lines and their reconciliation matches', function (): void {
    $statement = Document::create([
        'document_type' => 'bank_statement',
        'direction' => 'inbound',
        'status' => 'received',
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'contra_account_id' => Account::factory()->create()->id,
        'source' => 'upload',
    ]);
    attachFakeStatementMedia($statement);

    $this->llmMock->allows('extractBankStatement')->andReturn(fakeStatement([
        ['transaction_date' => '2026-03-05', 'description' => 'Interest', 'credit' => 10.00],
    ]));
    $this->service->process($statement);

    $oldLine = $statement->fresh()->lines->first();
    $user = User::factory()->create();
    $glAccount = Account::factory()->create();
    app(DocumentService::class)->reconcileToGlAccount($oldLine, $glAccount->id, $user);
    expect($oldLine->reconciliationMatch)->not->toBeNull();

    // Reprocess: callers must delete existing lines first, per process()'s own contract.
    $statement->lines()->delete();
    $this->llmMock->allows('extractBankStatement')->andReturn(fakeStatement([
        ['transaction_date' => '2026-03-06', 'description' => 'Interest (corrected date)', 'credit' => 10.00],
    ]));
    $this->service->process($statement);

    expect($statement->fresh()->lines)->toHaveCount(1)
        ->and($statement->fresh()->lines->first()->id)->not->toBe($oldLine->id);
});
