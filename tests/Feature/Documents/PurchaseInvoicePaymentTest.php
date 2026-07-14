<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

function postedPurchaseInvoice(float $unitPrice = 1000.00): Document
{
    $doc = Document::factory()->purchaseInvoice()->create([
        'status' => 'posted',
        'issue_date' => now()->toDateString(),
    ]);

    $doc->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Service',
        'quantity' => 1,
        'unit_price' => $unitPrice,
        'tax_rate' => 0,
    ]);

    return $doc->fresh();
}

it('records a partial payment and transitions to partially_paid', function (): void {
    $invoice = postedPurchaseInvoice(); // total 1000

    $payment = app(DocumentService::class)->recordPurchasePayment($invoice, [
        'amount' => 400.00,
        'date' => now()->toDateString(),
        'reference' => 'EFT-100',
    ], null);

    $fresh = $invoice->fresh();
    expect($fresh->status)->toBe('partially_paid')
        ->and((float) $fresh->amount_paid)->toBe(400.00)
        ->and((float) $fresh->balance_due)->toBe(600.00)
        ->and($payment->document_type)->toBe('payment')
        ->and($payment->direction)->toBe('outbound');

    $this->assertDatabaseHas('document_relationships', [
        'parent_document_id' => $invoice->id,
        'child_document_id' => $payment->id,
        'relationship_type' => 'payment_for',
    ]);
});

it('sets payable_account_id and contra_account_id on the payment so AP is debited and bank credited in reports', function (): void {
    $invoice = postedPurchaseInvoice();
    $bankAccount = Account::factory()->create();

    $payment = app(DocumentService::class)->recordPurchasePayment($invoice, [
        'amount' => 400.00,
        'date' => now()->toDateString(),
        'contra_account_id' => $bankAccount->id,
    ], null);

    expect($payment->payable_account_id)->toBe($invoice->payable_account_id)
        ->and($payment->contra_account_id)->toBe($bankAccount->id);
});

it('records a full payment and transitions to paid', function (): void {
    $invoice = postedPurchaseInvoice();

    app(DocumentService::class)->recordPurchasePayment($invoice, [
        'amount' => 1000.00,
        'date' => now()->toDateString(),
    ], null);

    $fresh = $invoice->fresh();
    expect($fresh->status)->toBe('paid')
        ->and((float) $fresh->balance_due)->toBe(0.0);
});

it('rejects payments against unposted purchase invoices', function (): void {
    $invoice = Document::factory()->purchaseInvoice()->create(['status' => 'received']);

    expect(fn () => app(DocumentService::class)->recordPurchasePayment($invoice, [
        'amount' => 100.00,
        'date' => now()->toDateString(),
    ], null))->toThrow(InvalidArgumentException::class, 'received purchase invoice');
});

it('rejects overpayment of a purchase invoice', function (): void {
    $invoice = postedPurchaseInvoice();

    expect(fn () => app(DocumentService::class)->recordPurchasePayment($invoice, [
        'amount' => 1500.00,
        'date' => now()->toDateString(),
    ], null))->toThrow(InvalidArgumentException::class, 'exceeds the balance due');

    expect(Document::where('document_type', 'payment')->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe('posted');
});

it('finalises the exchange rate from the actual amount paid', function (): void {
    $invoice = Document::factory()->purchaseInvoice()->create([
        'status' => 'posted',
        'currency' => 'USD',
        'exchange_rate' => 18.0,
        'exchange_rate_provisional' => true,
        'issue_date' => now()->toDateString(),
    ]);

    $invoice->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Hosting',
        'quantity' => 1,
        'unit_price' => 1800.00,
        'foreign_unit_price' => 100.00,
        'foreign_line_total' => 100.00,
        'foreign_tax_amount' => 0,
        'tax_rate' => 0,
    ]);

    $invoice = $invoice->fresh(); // foreign_total 100, total 1800 provisional

    app(DocumentService::class)->recordPurchasePayment($invoice, [
        'amount' => 1850.00, // actual ZAR paid for USD 100 → rate 18.5
        'date' => now()->toDateString(),
        'finalise_rate' => true,
    ], null);

    $fresh = $invoice->fresh();
    expect((float) $fresh->exchange_rate)->toBe(18.5)
        ->and($fresh->exchange_rate_provisional)->toBeFalse()
        ->and((float) $fresh->total)->toBe(1850.00)
        ->and($fresh->status)->toBe('paid');
});

it('records a payment through the purchase invoices page', function (): void {
    $user = User::factory()->create();
    foreach (['documents-view-any', 'documents-view', 'can-record-payments'] as $perm) {
        Permission::findOrCreate($perm, 'web');
        $user->givePermissionTo($perm);
    }
    $this->actingAs($user);

    $invoice = postedPurchaseInvoice();

    Livewire::test('pages.purchase-invoices.index')
        ->set('detailId', $invoice->id)
        ->set('showDetail', true)
        ->call('openPaymentModal')
        ->set('paymentForm.amount', '1000.00')
        ->set('paymentForm.date', now()->toDateString())
        ->call('submitPayment')
        ->assertHasNoErrors()
        ->assertSet('showPaymentModal', false);

    expect($invoice->fresh()->status)->toBe('paid');
});
