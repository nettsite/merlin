<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
use App\Modules\Purchasing\DTO\ExtractedInvoiceLine;
use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Models\DocumentLine;
use App\Modules\Purchasing\Services\AccountResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
    $this->resolver = app(AccountResolver::class);

    $this->account = Account::factory()->create([
        'code' => '5210',
        'name' => 'IT & Software',
        'is_active' => true,
        'allow_direct_posting' => true,
    ]);
});

/**
 * Build a minimal ExtractedInvoiceLine.
 */
function extractedLine(?string $accountCode = null, float $confidence = 0.9): ExtractedInvoiceLine
{
    return new ExtractedInvoiceLine(
        description: 'Monthly hosting fee',
        quantity: 1,
        unitPrice: 1000,
        lineTotal: 1000,
        taxRate: null,
        suggestedAccountCode: $accountCode,
        accountConfidence: $confidence,
        accountReason: 'Test reason',
    );
}

it('resolves account from history before using llm suggestion', function (): void {
    $service = app(PartyService::class);
    $supplier = $service->createBusiness(
        ['business_type' => 'company', 'legal_name' => 'Repeat Supplier'],
        relationships: ['supplier']
    );

    // A different account — the LLM would suggest '5210' but history should win
    $historicalAccount = Account::factory()->create([
        'code' => '5100',
        'name' => 'Telephone & Internet',
        'is_active' => true,
        'allow_direct_posting' => true,
    ]);

    // A previously posted document for this supplier with the same description
    $postedDoc = Document::factory()->purchaseInvoice()->create([
        'party_id' => $supplier->id,
        'status' => 'posted',
    ]);
    DocumentLine::factory()->create([
        'document_id' => $postedDoc->id,
        'description' => 'Monthly hosting fee',
        'account_id' => $historicalAccount->id,
    ]);

    $result = $this->resolver->resolve('Monthly hosting fee', $supplier->id, extractedLine('5210'));

    expect($result['account_id'])->toBe($historicalAccount->id)
        ->and($result['llm_account_suggestion'])->toBe($this->account->id); // LLM suggestion still stored
});

it('auto-applies llm_account_suggestion to account_id when no history match exists', function (): void {
    $result = $this->resolver->resolve('Brand new service', null, extractedLine('5210', 0.85));

    expect($result['account_id'])->toBe($this->account->id)
        ->and($result['llm_account_suggestion'])->toBe($this->account->id)
        ->and($result['llm_confidence'])->toBe(0.85);
});

it('leaves both null when no history and no matching llm suggestion', function (): void {
    $result = $this->resolver->resolve('Unknown service', null, extractedLine('9999'));

    expect($result['account_id'])->toBeNull()
        ->and($result['llm_account_suggestion'])->toBeNull()
        ->and($result['llm_confidence'])->toBeNull();
});

it('auto-applies llm suggestion when there is no history for this supplier', function (): void {
    $service = app(PartyService::class);
    $supplier = $service->createBusiness(
        ['business_type' => 'company', 'legal_name' => 'New Supplier'],
        relationships: ['supplier']
    );

    $result = $this->resolver->resolve('Monthly hosting fee', $supplier->id, extractedLine('5210'));

    expect($result['account_id'])->toBe($this->account->id)
        ->and($result['llm_account_suggestion'])->toBe($this->account->id);
});

it('ignores history from non-posted documents', function (): void {
    $service = app(PartyService::class);
    $supplier = $service->createBusiness(
        ['business_type' => 'company', 'legal_name' => 'Draft Supplier'],
        relationships: ['supplier']
    );

    $draftDoc = Document::factory()->purchaseInvoice()->create([
        'party_id' => $supplier->id,
        'status' => 'received', // not posted
    ]);
    DocumentLine::factory()->create([
        'document_id' => $draftDoc->id,
        'description' => 'Monthly hosting fee',
        'account_id' => $this->account->id,
    ]);

    $result = $this->resolver->resolve('Monthly hosting fee', $supplier->id, extractedLine(null));

    expect($result['account_id'])->toBeNull(); // not posted, so not used as history
});
