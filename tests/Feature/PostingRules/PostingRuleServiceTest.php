<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Party;
use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Models\DocumentLine;
use App\Modules\Purchasing\Models\PostingRule;
use App\Modules\Purchasing\Services\PostingRuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = app(PostingRuleService::class);

    // A supplier and an account for line coding
    $this->supplier = Party::factory()->create();
    $this->account = Account::factory()->create();
    $payableId = Account::factory()->create(['code' => '2000', 'name' => 'Accounts Payable'])->id;

    // A document that is eligible: has supplier, all lines coded, high confidence
    $this->document = Document::factory()->purchaseInvoice()->create([
        'party_id' => $this->supplier->id,
        'status' => 'received',
        'llm_confidence' => 0.95,
        'total' => 200.00,
        'payable_account_id' => $payableId,
    ]);

    DocumentLine::factory()->create([
        'document_id' => $this->document->id,
        'description' => 'Monthly hosting fee',
        'account_id' => $this->account->id,
    ]);
});

// --- Eligibility guard ---

it('does not fire when the document has no supplier', function (): void {
    $this->document->update(['party_id' => null]);

    PostingRule::factory()->create([
        'conditions' => ['min_confidence' => 0.90],
        'actions' => ['auto_approve' => true, 'auto_post' => false],
    ]);

    $this->service->evaluateAndPost($this->document);

    expect($this->document->fresh()->status)->toBe('received');
});

it('does not fire when a line has no account assigned', function (): void {
    DocumentLine::factory()->create([
        'document_id' => $this->document->id,
        'description' => 'Unallocated line',
        'account_id' => null,
    ]);

    PostingRule::factory()->create([
        'conditions' => ['min_confidence' => 0.90],
        'actions' => ['auto_approve' => true, 'auto_post' => false],
    ]);

    $this->service->evaluateAndPost($this->document);

    expect($this->document->fresh()->status)->toBe('received');
});

it('does not fire when confidence is below the hard floor of 0.90', function (): void {
    $this->document->update(['llm_confidence' => 0.85]);

    PostingRule::factory()->create([
        'conditions' => ['min_confidence' => 0.90],
        'actions' => ['auto_approve' => true, 'auto_post' => false],
    ]);

    $this->service->evaluateAndPost($this->document);

    expect($this->document->fresh()->status)->toBe('received');
});

// --- Condition matching ---

it('matches a rule when all conditions are met', function (): void {
    PostingRule::factory()->create([
        'conditions' => [
            'min_confidence' => 0.90,
            'total_range' => ['min' => 100, 'max' => 500],
            'line_description_contains' => ['hosting'],
        ],
        'actions' => ['auto_approve' => true, 'auto_post' => false],
    ]);

    $this->service->evaluateAndPost($this->document);

    expect($this->document->fresh()->status)->toBe('approved');
});

it('does not match when total is outside the specified range', function (): void {
    PostingRule::factory()->create([
        'conditions' => [
            'min_confidence' => 0.90,
            'total_range' => ['max' => 50],
        ],
        'actions' => ['auto_approve' => true, 'auto_post' => false],
    ]);

    $this->service->evaluateAndPost($this->document);

    expect($this->document->fresh()->status)->toBe('received');
});

it('does not match when no line description contains any keyword', function (): void {
    PostingRule::factory()->create([
        'conditions' => [
            'min_confidence' => 0.90,
            'line_description_contains' => ['payroll', 'salary'],
        ],
        'actions' => ['auto_approve' => true, 'auto_post' => false],
    ]);

    $this->service->evaluateAndPost($this->document);

    expect($this->document->fresh()->status)->toBe('received');
});

it('does not match when rule-level confidence threshold is not met', function (): void {
    $this->document->update(['llm_confidence' => 0.92]);

    PostingRule::factory()->create([
        'conditions' => ['min_confidence' => 0.95],
        'actions' => ['auto_approve' => true, 'auto_post' => false],
    ]);

    $this->service->evaluateAndPost($this->document);

    expect($this->document->fresh()->status)->toBe('received');
});

// --- Actions ---

it('auto-approves but does not auto-post when only auto_approve is set', function (): void {
    PostingRule::factory()->create([
        'conditions' => ['min_confidence' => 0.90],
        'actions' => ['auto_approve' => true, 'auto_post' => false],
    ]);

    $this->service->evaluateAndPost($this->document);

    expect($this->document->fresh()->status)->toBe('approved');
});

it('auto-approves then auto-posts when both actions are set', function (): void {
    PostingRule::factory()->create([
        'conditions' => ['min_confidence' => 0.90],
        'actions' => ['auto_approve' => true, 'auto_post' => true],
    ]);

    $this->service->evaluateAndPost($this->document);

    expect($this->document->fresh()->status)->toBe('posted');
});

it('records autonomous posting in document activities', function (): void {
    $rule = PostingRule::factory()->create([
        'name' => 'Auto approve rule',
        'conditions' => ['min_confidence' => 0.90],
        'actions' => ['auto_approve' => true, 'auto_post' => false],
    ]);

    $this->service->evaluateAndPost($this->document);

    expect($this->document->activities()->where('activity_type', 'status_changed')->exists())->toBeTrue();
});

it('increments match_count and sets last_matched_at on the rule', function (): void {
    $rule = PostingRule::factory()->create([
        'conditions' => ['min_confidence' => 0.90],
        'actions' => ['auto_approve' => true, 'auto_post' => false],
        'match_count' => 0,
        'last_matched_at' => null,
    ]);

    $this->service->evaluateAndPost($this->document);

    $rule->refresh();
    expect($rule->match_count)->toBe(1);
    expect($rule->last_matched_at)->not->toBeNull();
});

it('does not fire inactive rules', function (): void {
    PostingRule::factory()->create([
        'is_active' => false,
        'conditions' => ['min_confidence' => 0.90],
        'actions' => ['auto_approve' => true, 'auto_post' => false],
    ]);

    $this->service->evaluateAndPost($this->document);

    expect($this->document->fresh()->status)->toBe('received');
});

it('only fires rules scoped to a different supplier when the document supplier does not match', function (): void {
    $otherSupplier = Party::factory()->create();

    PostingRule::factory()->create([
        'party_id' => $otherSupplier->id,
        'conditions' => ['min_confidence' => 0.90],
        'actions' => ['auto_approve' => true, 'auto_post' => false],
    ]);

    $this->service->evaluateAndPost($this->document);

    expect($this->document->fresh()->status)->toBe('received');
});

it('fires a supplier-scoped rule when it matches the document supplier', function (): void {
    PostingRule::factory()->create([
        'party_id' => $this->supplier->id,
        'conditions' => ['min_confidence' => 0.90],
        'actions' => ['auto_approve' => true, 'auto_post' => false],
    ]);

    $this->service->evaluateAndPost($this->document);

    expect($this->document->fresh()->status)->toBe('approved');
});

// --- Pattern-based auto-posting ---

// Helper: create a posted invoice for the supplier with one matched line
function postedInvoice(mixed $supplier, mixed $account, array $overrides = []): Document
{
    $payableId = Account::firstOrCreate(
        ['code' => '2000'],
        ['name' => 'Accounts Payable', 'is_active' => true, 'allow_direct_posting' => true]
    )->id;

    $doc = Document::factory()->purchaseInvoice()->create(array_merge([
        'party_id' => $supplier->id,
        'status' => 'posted',
        'llm_confidence' => 0.95,
        'currency' => 'ZAR',
        'payable_account_id' => $payableId,
    ], $overrides));

    DocumentLine::factory()->create([
        'document_id' => $doc->id,
        'description' => 'Hosting (01/02/2026 - 28/02/2026)',
        'account_id' => $account->id,
        'unit_price' => 100.00,
    ]);

    return $doc;
}

it('auto-posts when all lines match a previous posted invoice', function (): void {
    $payableId = Account::firstOrCreate(
        ['code' => '2000'],
        ['name' => 'Accounts Payable', 'is_active' => true, 'allow_direct_posting' => true]
    )->id;

    postedInvoice($this->supplier, $this->account);

    $current = Document::factory()->purchaseInvoice()->create([
        'party_id' => $this->supplier->id,
        'status' => 'received',
        'llm_confidence' => 0.95,
        'currency' => 'ZAR',
        'payable_account_id' => $payableId,
    ]);

    DocumentLine::factory()->create([
        'document_id' => $current->id,
        'description' => 'Hosting (01/03/2026 - 31/03/2026)',
        'account_id' => $this->account->id,
        'unit_price' => 100.00,
    ]);

    app(PostingRuleService::class)->evaluateAndPost($current);

    expect($current->fresh()->status)->toBe('posted');
});

it('does not pattern-post when there is no previous posted invoice', function (): void {
    $payableId = Account::firstOrCreate(
        ['code' => '2000'],
        ['name' => 'Accounts Payable', 'is_active' => true, 'allow_direct_posting' => true]
    )->id;

    $current = Document::factory()->purchaseInvoice()->create([
        'party_id' => $this->supplier->id,
        'status' => 'received',
        'llm_confidence' => 0.95,
        'currency' => 'ZAR',
        'payable_account_id' => $payableId,
    ]);

    DocumentLine::factory()->create([
        'document_id' => $current->id,
        'description' => 'Hosting (01/03/2026 - 31/03/2026)',
        'account_id' => $this->account->id,
        'unit_price' => 100.00,
    ]);

    app(PostingRuleService::class)->evaluateAndPost($current);

    expect($current->fresh()->status)->toBe('received');
});

it('blocks pattern post when an extra line is present', function (): void {
    $payableId = Account::firstOrCreate(
        ['code' => '2000'],
        ['name' => 'Accounts Payable', 'is_active' => true, 'allow_direct_posting' => true]
    )->id;

    postedInvoice($this->supplier, $this->account); // 1 line

    $current = Document::factory()->purchaseInvoice()->create([
        'party_id' => $this->supplier->id,
        'status' => 'received',
        'llm_confidence' => 0.95,
        'currency' => 'ZAR',
        'payable_account_id' => $payableId,
    ]);

    DocumentLine::factory()->create([
        'document_id' => $current->id,
        'description' => 'Hosting (01/03/2026 - 31/03/2026)',
        'account_id' => $this->account->id,
        'unit_price' => 100.00,
    ]);

    DocumentLine::factory()->create([
        'document_id' => $current->id,
        'description' => 'One-time setup fee',
        'account_id' => $this->account->id,
        'unit_price' => 50.00,
    ]);

    app(PostingRuleService::class)->evaluateAndPost($current);

    expect($current->fresh()->status)->toBe('received');
});

it('blocks pattern post when amount exceeds tolerance', function (): void {
    $payableId = Account::firstOrCreate(
        ['code' => '2000'],
        ['name' => 'Accounts Payable', 'is_active' => true, 'allow_direct_posting' => true]
    )->id;

    postedInvoice($this->supplier, $this->account); // line_total = 100.00

    $current = Document::factory()->purchaseInvoice()->create([
        'party_id' => $this->supplier->id,
        'status' => 'received',
        'llm_confidence' => 0.95,
        'currency' => 'ZAR',
        'payable_account_id' => $payableId,
    ]);

    DocumentLine::factory()->create([
        'document_id' => $current->id,
        'description' => 'Hosting (01/03/2026 - 31/03/2026)',
        'account_id' => $this->account->id,
        'unit_price' => 120.00, // 20% above — exceeds default 10% tolerance
    ]);

    app(PostingRuleService::class)->evaluateAndPost($current);

    expect($current->fresh()->status)->toBe('received');
});

it('blocks pattern post when description similarity is too low', function (): void {
    $payableId = Account::firstOrCreate(
        ['code' => '2000'],
        ['name' => 'Accounts Payable', 'is_active' => true, 'allow_direct_posting' => true]
    )->id;

    postedInvoice($this->supplier, $this->account); // description: "Hosting (01/02/2026 - 28/02/2026)"

    $current = Document::factory()->purchaseInvoice()->create([
        'party_id' => $this->supplier->id,
        'status' => 'received',
        'llm_confidence' => 0.95,
        'currency' => 'ZAR',
        'payable_account_id' => $payableId,
    ]);

    DocumentLine::factory()->create([
        'document_id' => $current->id,
        'description' => 'Domain registration renewal annual fee',
        'account_id' => $this->account->id,
        'unit_price' => 100.00,
    ]);

    app(PostingRuleService::class)->evaluateAndPost($current);

    expect($current->fresh()->status)->toBe('received');
});

it('blocks pattern post when currencies differ', function (): void {
    $payableId = Account::firstOrCreate(
        ['code' => '2000'],
        ['name' => 'Accounts Payable', 'is_active' => true, 'allow_direct_posting' => true]
    )->id;

    postedInvoice($this->supplier, $this->account, ['currency' => 'USD']);

    $current = Document::factory()->purchaseInvoice()->create([
        'party_id' => $this->supplier->id,
        'status' => 'received',
        'llm_confidence' => 0.95,
        'currency' => 'ZAR',
        'payable_account_id' => $payableId,
    ]);

    DocumentLine::factory()->create([
        'document_id' => $current->id,
        'description' => 'Hosting (01/03/2026 - 31/03/2026)',
        'account_id' => $this->account->id,
        'unit_price' => 100.00,
    ]);

    app(PostingRuleService::class)->evaluateAndPost($current);

    expect($current->fresh()->status)->toBe('received');
});
