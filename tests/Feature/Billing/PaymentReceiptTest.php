<?php

use App\Mail\PaymentReceiptMail;
use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Models\BillingEmailTemplate;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;
use App\Modules\Core\Services\PartyService;
use Illuminate\Support\Facades\Mail;
use Nettsite\NettMail\Core\Domain\Templates\TemplateType;
use NettSite\NettMail\Models\Template;

beforeEach(function (): void {
    BillingEmailTemplate::firstOrCreate(
        ['type' => 'receipt', 'name' => 'Payment Receipt'],
        [
            'subject' => 'Payment received — {{invoices}}',
            'body' => '<p>Hi {{first_name}}, thanks for your payment of {{amount}} for {{invoices}}.</p>',
            'enabled' => true,
        ],
    );
});

function receiptClient(): Party
{
    return app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Receipt Client Ltd',
        'trading_name' => 'Receipt Client',
        'status' => 'active',
    ], ['client']);
}

function receiptRecipient(Party $client, string $email = 'billing@client.com', string $firstName = 'Robin'): void
{
    $personParty = app(PartyService::class)->createPerson([
        'first_name' => $firstName,
        'last_name' => 'Client',
        'email' => $email,
        'status' => 'active',
    ]);

    $client->assignContact($personParty->person, [
        'role' => 'billing',
        'receives_invoices' => true,
        'is_active' => true,
    ]);
}

function receiptSentInvoice(?Party $client = null, float $amount = 500.00): Document
{
    $client ??= receiptClient();
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

    return $invoice->fresh();
}

it('emails a receipt automatically when a payment is recorded', function (): void {
    Mail::fake();

    $client = receiptClient();
    receiptRecipient($client);
    $invoice = receiptSentInvoice($client, 500.00);

    app(BillingService::class)->recordPayment($invoice, [
        'amount' => 500.00,
        'date' => now()->toDateString(),
    ], null);

    Mail::assertSent(PaymentReceiptMail::class, fn (PaymentReceiptMail $mail): bool => $mail->hasTo('billing@client.com')
        && str_contains($mail->emailHtml, $invoice->document_number)
    );
});

it('attaches a receipt PDF to the receipt email', function (): void {
    Mail::fake();

    $client = receiptClient();
    receiptRecipient($client);
    $invoice = receiptSentInvoice($client);

    $payment = app(BillingService::class)->recordPayment($invoice, [
        'amount' => 500.00,
        'date' => now()->toDateString(),
    ], null);

    expect($payment->fresh()->getFirstMedia('payment_receipt'))->not->toBeNull();
});

it('records a receipt_sent activity on the payment', function (): void {
    Mail::fake();

    $client = receiptClient();
    receiptRecipient($client);
    $invoice = receiptSentInvoice($client);

    $payment = app(BillingService::class)->recordPayment($invoice, [
        'amount' => 500.00,
        'date' => now()->toDateString(),
    ], null);

    expect($payment->fresh()->activities()->where('activity_type', 'receipt_sent')->exists())->toBeTrue();
});

it('does not send a receipt when there are no flagged recipients', function (): void {
    Mail::fake();

    $invoice = receiptSentInvoice();

    app(BillingService::class)->recordPayment($invoice, [
        'amount' => 500.00,
        'date' => now()->toDateString(),
    ], null);

    Mail::assertNothingSent();
});

it('numbers a new payment with plain digits, no prefix', function (): void {
    Mail::fake();

    $client = receiptClient();
    receiptRecipient($client);
    $invoice = receiptSentInvoice($client);

    $payment = app(BillingService::class)->recordPayment($invoice, [
        'amount' => 500.00,
        'date' => now()->toDateString(),
    ], null);

    expect($payment->document_number)->toMatch('/^\d+$/');
});

it('does not reuse a sales invoice number when both share the sequential numbering', function (): void {
    Mail::fake();

    $client = receiptClient();
    receiptRecipient($client);
    $invoiceOne = receiptSentInvoice($client);
    $invoiceTwo = receiptSentInvoice($client);

    $paymentOne = app(BillingService::class)->recordPayment($invoiceOne, [
        'amount' => 500.00,
        'date' => now()->toDateString(),
    ], null);
    $paymentTwo = app(BillingService::class)->recordPayment($invoiceTwo, [
        'amount' => 500.00,
        'date' => now()->toDateString(),
    ], null);

    expect($paymentOne->document_number)->not->toBe($paymentTwo->document_number)
        ->and($invoiceOne->document_number)->not->toBe($paymentOne->document_number);
});

it('uses the NettMail payment email over the Templates tab text when one is configured', function (): void {
    Mail::fake();

    $template = Template::create([
        'name' => 'Payment Email',
        'type' => TemplateType::Transactional,
        'subject' => 'Received {{amount}}',
        'html' => '<p>Dear {{first_name}}, thanks.</p>',
    ]);
    $settings = app(BillingSettings::class);
    $settings->payment_email_template_id = $template->id;
    $settings->save();

    $client = receiptClient();
    receiptRecipient($client, 'billing@client.com', 'Robin');
    $invoice = receiptSentInvoice($client, 500.00);

    app(BillingService::class)->recordPayment($invoice, [
        'amount' => 500.00,
        'date' => now()->toDateString(),
    ], null);

    Mail::assertSent(PaymentReceiptMail::class, fn (PaymentReceiptMail $mail): bool => $mail->hasTo('billing@client.com')
        && $mail->emailHtml === '<p>Dear Robin, thanks.</p>'
    );
});

it('does not roll back the payment when sending the receipt fails', function (): void {
    BillingEmailTemplate::where('type', 'receipt')->delete();

    $client = receiptClient();
    receiptRecipient($client);
    $invoice = receiptSentInvoice($client);

    $payment = app(BillingService::class)->recordPayment($invoice, [
        'amount' => 500.00,
        'date' => now()->toDateString(),
    ], null);

    expect($payment->exists)->toBeTrue()
        ->and($invoice->fresh()->balance_due)->toBe('0.00');
});
