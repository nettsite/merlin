<?php

use App\Mail\PaymentReceiptMail;
use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Models\BillingEmailTemplate;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;
use App\Modules\Core\Services\PartyService;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

function paymentsPageUser(array $extra = []): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(['documents-view-any', 'documents-view', ...$extra]);

    return $user;
}

function recordedPayment(float $amount = 250.00): Document
{
    BillingEmailTemplate::firstOrCreate(
        ['type' => 'receipt', 'name' => 'Payment Receipt'],
        ['subject' => 'Payment received', 'body' => '<p>Thanks {{client_name}}.</p>', 'enabled' => true],
    );

    $client = app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Payments Page Client',
        'trading_name' => 'Payments Page Client',
        'status' => 'active',
    ], ['client']);

    $personParty = app(PartyService::class)->createPerson([
        'first_name' => 'Billing',
        'last_name' => 'Contact',
        'email' => 'billing@payments-page-client.com',
        'status' => 'active',
    ]);
    $client->assignContact($personParty->person, [
        'role' => 'billing',
        'receives_invoices' => true,
        'is_active' => true,
    ]);

    $incomeAccount = Account::factory()->create();
    $invoice = app(BillingService::class)->createDraft($client, ['issue_date' => now()->toDateString()]);
    $invoice->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Consulting',
        'account_id' => $incomeAccount->id,
        'quantity' => 1,
        'unit_price' => $amount,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_rate' => 0,
    ]);
    $invoice->recalculateTotals();
    app(DocumentService::class)->markAsSent($invoice, User::factory()->create());

    return app(BillingService::class)->recordPayment($invoice->fresh(), [
        'amount' => $amount,
        'date' => now()->toDateString(),
    ], null);
}

it('lists recorded payments with number, client and amount', function (): void {
    Mail::fake();
    $payment = recordedPayment(250.00);

    $this->actingAs(paymentsPageUser());

    Livewire::test('pages.payments.index')
        ->assertOk()
        ->assertSee($payment->document_number)
        ->assertSee('Payments Page Client')
        ->assertSee('250.00');
});

it('hides the send/resend button without the send permission', function (): void {
    Mail::fake();
    recordedPayment();

    $this->actingAs(paymentsPageUser());

    Livewire::test('pages.payments.index')
        ->assertDontSee('Resend')
        ->assertDontSee('wire:click="sendReceipt', false);
});

it('resends a receipt when the send permission is granted', function (): void {
    Mail::fake();
    $payment = recordedPayment();

    $this->actingAs(paymentsPageUser(['can-send-sales-invoices']));

    Livewire::test('pages.payments.index')
        ->call('sendReceipt', $payment->id)
        ->assertOk();

    Mail::assertSent(PaymentReceiptMail::class, 2);
});

it('refuses to resend a receipt without the send permission', function (): void {
    Mail::fake();
    $payment = recordedPayment();

    $this->actingAs(paymentsPageUser());

    Livewire::test('pages.payments.index')
        ->call('sendReceipt', $payment->id)
        ->assertForbidden();
});

/** @return array{0: Party, 1: Document, 2: Document} */
function ppClientWithTwoInvoices(): array
{
    BillingEmailTemplate::firstOrCreate(
        ['type' => 'receipt', 'name' => 'Payment Receipt'],
        ['subject' => 'Payment received', 'body' => '<p>Thanks {{client_name}}.</p>', 'enabled' => true],
    );

    $client = app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Multi Invoice Client',
        'trading_name' => 'Multi Invoice Client',
        'status' => 'active',
    ], ['client']);

    $incomeAccount = Account::factory()->create();

    $makeInvoice = function (float $amount) use ($client, $incomeAccount): Document {
        $invoice = app(BillingService::class)->createDraft($client, ['issue_date' => now()->toDateString()]);
        $invoice->lines()->create([
            'line_number' => 1,
            'type' => 'service',
            'description' => 'Consulting',
            'account_id' => $incomeAccount->id,
            'quantity' => 1,
            'unit_price' => $amount,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'tax_rate' => 0,
        ]);
        $invoice->recalculateTotals();
        app(DocumentService::class)->markAsSent($invoice, User::factory()->create());

        return $invoice->fresh();
    };

    return [$client, $makeInvoice(300.00), $makeInvoice(200.00)];
}

it('shows a client\'s open invoices once chosen in the new payment modal', function (): void {
    [$client, $invoiceOne, $invoiceTwo] = ppClientWithTwoInvoices();

    $this->actingAs(paymentsPageUser(['can-record-payments']));

    Livewire::test('pages.payments.index')
        ->call('openNewPaymentModal')
        ->set('newPaymentClientId', $client->id)
        ->assertSee($invoiceOne->document_number)
        ->assertSee($invoiceTwo->document_number);
});

it('records one payment across both selected invoices', function (): void {
    Mail::fake();
    [$client, $invoiceOne, $invoiceTwo] = ppClientWithTwoInvoices();

    $this->actingAs(paymentsPageUser(['can-record-payments']));

    Livewire::test('pages.payments.index')
        ->call('openNewPaymentModal')
        ->set('newPaymentClientId', $client->id)
        ->set("newPaymentAllocations.{$invoiceOne->id}.selected", true)
        ->set("newPaymentAllocations.{$invoiceTwo->id}.selected", true)
        ->call('submitNewPayment')
        ->assertSet('showNewPaymentModal', false);

    expect($invoiceOne->fresh()->status)->toBe('paid')
        ->and($invoiceTwo->fresh()->status)->toBe('paid');
});

it('requires at least one invoice selected before recording a payment', function (): void {
    [$client] = ppClientWithTwoInvoices();

    $this->actingAs(paymentsPageUser(['can-record-payments']));

    Livewire::test('pages.payments.index')
        ->call('openNewPaymentModal')
        ->set('newPaymentClientId', $client->id)
        ->call('submitNewPayment')
        ->assertSet('showNewPaymentModal', true)
        ->assertSet('newPaymentError', 'Select at least one invoice.');
});

it('refuses to open the new payment modal without the record-payments permission', function (): void {
    $this->actingAs(paymentsPageUser());

    Livewire::test('pages.payments.index')
        ->call('openNewPaymentModal')
        ->assertForbidden();
});
