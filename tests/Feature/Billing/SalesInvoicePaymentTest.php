<?php

use App\Modules\Billing\Services\BillingService;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Models\DocumentRelationship;
use App\Modules\Purchasing\Services\DocumentService;
use Livewire\Volt\Volt;

function sipClient()
{
    return app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Payment Test Client Ltd',
        'trading_name' => 'Payment Test Client',
        'status' => 'active',
    ], ['client']);
}

function sipSentInvoice($client = null): Document
{
    $client ??= sipClient();
    $doc = app(BillingService::class)->createDraft($client, ['issue_date' => now()->toDateString()]);

    $doc->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Consulting',
        'quantity' => 1,
        'unit_price' => 1000.00,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_rate' => 0,
    ]);
    $doc->recalculateTotals();
    app(DocumentService::class)->markAsSent($doc, User::factory()->create());

    return $doc->fresh();
}

it('rejects a payment exceeding the balance due', function () {
    $invoice = sipSentInvoice(); // total 1000.00

    expect(fn () => app(BillingService::class)->recordPayment($invoice, [
        'amount' => 1500.00,
        'date' => '2026-05-10',
    ], null))->toThrow(InvalidArgumentException::class, 'exceeds the balance due');

    $fresh = $invoice->fresh();
    expect((float) $fresh->amount_paid)->toBe(0.0)
        ->and($fresh->status)->toBe('sent')
        // Transaction rollback: no orphaned payment document.
        ->and(Document::where('document_type', 'payment')->count())->toBe(0);
});

it('rejects a zero or negative payment amount', function () {
    $invoice = sipSentInvoice();

    expect(fn () => app(BillingService::class)->recordPayment($invoice, [
        'amount' => 0.0,
        'date' => '2026-05-10',
    ], null))->toThrow(InvalidArgumentException::class, 'greater than zero');

    expect(fn () => app(BillingService::class)->recordPayment($invoice, [
        'amount' => -50.0,
        'date' => '2026-05-10',
    ], null))->toThrow(InvalidArgumentException::class, 'greater than zero');

    expect(Document::where('document_type', 'payment')->count())->toBe(0);
});

it('creates a payment document linked to the invoice', function () {
    $invoice = sipSentInvoice();

    app(BillingService::class)->recordPayment($invoice, [
        'amount' => 500.00,
        'date' => '2026-05-10',
        'reference' => 'EFT-001',
    ], null);

    $this->assertDatabaseHas('documents', [
        'document_type' => 'payment',
        'direction' => 'inbound',
        'party_id' => $invoice->party_id,
        'reference' => 'EFT-001',
    ]);

    $payment = Document::where('document_type', 'payment')->latest()->first();

    $this->assertDatabaseHas('document_relationships', [
        'parent_document_id' => $invoice->id,
        'child_document_id' => $payment->id,
        'relationship_type' => 'payment_for',
    ]);
});

it('updates invoice amount_paid and balance_due', function () {
    $invoice = sipSentInvoice();

    app(BillingService::class)->recordPayment($invoice, [
        'amount' => 600.00,
        'date' => '2026-05-10',
    ], null);

    $invoice->refresh();

    expect((float) $invoice->amount_paid)->toBe(600.0)
        ->and((float) $invoice->balance_due)->toBe(400.0);
});

it('transitions invoice to partially_paid on partial payment', function () {
    $invoice = sipSentInvoice();

    app(BillingService::class)->recordPayment($invoice, [
        'amount' => 400.00,
        'date' => '2026-05-10',
    ], null);

    expect($invoice->fresh()->status)->toBe('partially_paid');
});

it('transitions invoice to paid when balance is cleared', function () {
    $invoice = sipSentInvoice();

    app(BillingService::class)->recordPayment($invoice, [
        'amount' => 1000.00,
        'date' => '2026-05-10',
    ], null);

    expect($invoice->fresh()->status)->toBe('paid');
});

it('allows multiple partial payments', function () {
    $invoice = sipSentInvoice();

    app(BillingService::class)->recordPayment($invoice, ['amount' => 400.00, 'date' => '2026-05-10'], null);
    app(BillingService::class)->recordPayment($invoice->fresh(), ['amount' => 600.00, 'date' => '2026-05-15'], null);

    $invoice->refresh();

    expect((float) $invoice->amount_paid)->toBe(1000.0)
        ->and((float) $invoice->balance_due)->toBe(0.0)
        ->and($invoice->status)->toBe('paid');

    expect(
        DocumentRelationship::where('parent_document_id', $invoice->id)
            ->where('relationship_type', 'payment_for')
            ->count()
    )->toBe(2);
});

it('throws when recording payment against a draft invoice', function () {
    $client = sipClient();
    $draft = app(BillingService::class)->createDraft($client, ['issue_date' => now()->toDateString()]);

    expect(fn () => app(BillingService::class)->recordPayment($draft, [
        'amount' => 100.00,
        'date' => now()->toDateString(),
    ], null))->toThrow(RuntimeException::class);
});

it('throws when recording payment against a voided invoice', function () {
    $invoice = sipSentInvoice();
    app(DocumentService::class)->voidDocument($invoice, User::factory()->create());

    expect(fn () => app(BillingService::class)->recordPayment($invoice->fresh(), [
        'amount' => 100.00,
        'date' => now()->toDateString(),
    ], null))->toThrow(RuntimeException::class);
});

it('opens payment modal with balance pre-filled', function () {
    $invoice = sipSentInvoice();
    $user = User::factory()->create();
    $user->givePermissionTo(['documents-view-any', 'documents-view', 'can-record-payments']);
    $this->actingAs($user);

    Volt::test('pages.sales-invoices.index')
        ->call('openDetail', $invoice->id)
        ->call('openPaymentModal')
        ->assertSet('showPaymentModal', true)
        ->assertSet('paymentForm.amount', '1000.00');
});

it('records payment through the Volt UI', function () {
    $invoice = sipSentInvoice();
    $user = User::factory()->create();
    $user->givePermissionTo(['documents-view-any', 'documents-view', 'can-record-payments']);
    $this->actingAs($user);

    Volt::test('pages.sales-invoices.index')
        ->call('openDetail', $invoice->id)
        ->call('openPaymentModal')
        ->set('paymentForm.amount', '1000.00')
        ->set('paymentForm.date', now()->toDateString())
        ->set('paymentForm.reference', 'EFT-XYZ')
        ->call('submitPayment')
        ->assertSet('showPaymentModal', false);

    expect($invoice->fresh()->status)->toBe('paid');
});

it('forbids recording payment without permission', function () {
    $invoice = sipSentInvoice();
    $user = User::factory()->create();
    $user->givePermissionTo(['documents-view-any', 'documents-view']);
    $this->actingAs($user);

    Volt::test('pages.sales-invoices.index')
        ->call('openDetail', $invoice->id)
        ->call('openPaymentModal')
        ->assertForbidden();
});
