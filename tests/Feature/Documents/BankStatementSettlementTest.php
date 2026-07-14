<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;
use App\Modules\Core\Services\PartyService;

function bssSentInvoice(): Document
{
    $client = app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Statement Test Client',
        'trading_name' => 'Statement Test Client',
        'status' => 'active',
    ], ['client']);

    $invoice = app(BillingService::class)->createDraft($client, ['issue_date' => now()->toDateString()]);
    $invoice->update(['receivable_account_id' => Account::factory()->create()->id]);

    $invoice->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Consulting',
        'quantity' => 1,
        'unit_price' => 1000.00,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_rate' => 0,
    ]);
    $invoice->recalculateTotals();
    app(DocumentService::class)->markAsSent($invoice, User::factory()->create());

    return $invoice->fresh();
}

it('creates a payment document when a bank statement settles a linked invoice', function (): void {
    $invoice = bssSentInvoice(); // total 1000, balance_due 1000
    $bankAccount = Account::factory()->create();

    $statement = Document::create([
        'document_type' => 'bank_statement',
        'direction' => 'inbound',
        'status' => 'received',
        'party_id' => Party::factory()->create()->id,
        'issue_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'contra_account_id' => $bankAccount->id,
        'reference' => 'STMT-001',
        'source' => 'manual',
    ]);

    $statement->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Payment received',
        'quantity' => 1,
        'unit_price' => 1000.00,
        'tax_rate' => null,
        'linked_document_id' => $invoice->id,
    ]);

    app(DocumentService::class)->postBankStatement($statement, User::factory()->create());

    $fresh = $invoice->fresh();
    expect($fresh->status)->toBe('paid')
        ->and((float) $fresh->balance_due)->toBe(0.0);

    $payment = Document::where('document_type', 'payment')
        ->where('party_id', $invoice->party_id)
        ->latest()
        ->first();

    expect($payment)->not->toBeNull()
        ->and($payment->direction)->toBe('inbound')
        ->and((float) $payment->total)->toBe(1000.00)
        ->and($payment->receivable_account_id)->toBe($fresh->receivable_account_id)
        ->and($payment->contra_account_id)->toBe($bankAccount->id);

    $this->assertDatabaseHas('document_relationships', [
        'parent_document_id' => $invoice->id,
        'child_document_id' => $payment->id,
        'relationship_type' => 'payment_for',
    ]);
});
