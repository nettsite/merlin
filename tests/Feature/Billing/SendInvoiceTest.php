<?php

use App\Mail\SalesInvoiceMail;
use App\Modules\Billing\Models\BillingEmailTemplate;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
use App\Modules\Purchasing\Models\Document;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

beforeEach(function (): void {
    BillingEmailTemplate::firstOrCreate(
        ['type' => 'invoice', 'name' => 'Default Invoice'],
        ['subject' => 'Invoice {{invoice_number}}', 'body' => '<p>Please find your invoice {{invoice_number}} attached.</p>', 'enabled' => true],
    );
});

function sendUserWith(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function sendClient(): Party
{
    return app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Test Client Ltd',
        'trading_name' => 'Test Client',
        'status' => 'active',
    ], ['client']);
}

function sendDraft(?Party $client = null): Document
{
    $client ??= sendClient();

    return app(BillingService::class)->createDraft($client, [
        'issue_date' => now()->toDateString(),
    ]);
}

function addInvoiceRecipient(Party $client, string $email = 'contact@example.com'): void
{
    $personParty = app(PartyService::class)->createPerson([
        'first_name' => 'Invoice',
        'last_name' => 'Recipient',
        'email' => $email,
        'status' => 'active',
    ]);

    $client->assignContact($personParty->person, [
        'role' => 'billing',
        'receives_invoices' => true,
        'is_active' => true,
    ]);
}

// --- generatePdf ---

it('generatePdf stores a media file on invoice_pdf collection', function (): void {
    $doc = sendDraft();

    app(BillingService::class)->generatePdf($doc);

    expect($doc->fresh()->getFirstMedia('invoice_pdf'))->not->toBeNull();
});

it('generatePdf replaces an existing PDF (singleFile collection)', function (): void {
    $doc = sendDraft();

    app(BillingService::class)->generatePdf($doc);
    app(BillingService::class)->generatePdf($doc);

    expect($doc->fresh()->getMedia('invoice_pdf'))->toHaveCount(1);
});

// --- resolveRecipients ---

it('returns contacts flagged receives_invoices', function (): void {
    $client = sendClient();
    addInvoiceRecipient($client, 'billing@client.com');
    $doc = sendDraft($client);

    $recipients = app(BillingService::class)->resolveRecipients($doc);

    expect($recipients)->toHaveCount(1)
        ->and($recipients[0]['email'])->toBe('billing@client.com');
});

it('ignores contacts not flagged receives_invoices', function (): void {
    $client = sendClient();
    $personParty = app(PartyService::class)->createPerson([
        'first_name' => 'Other',
        'last_name' => 'Contact',
        'email' => 'other@client.com',
        'status' => 'active',
    ]);
    $client->assignContact($personParty->person, ['role' => 'general', 'receives_invoices' => false, 'is_active' => true]);
    $doc = sendDraft($client);

    expect(app(BillingService::class)->resolveRecipients($doc))->toBeEmpty();
});

// --- sendInvoice ---

it('sendInvoice queues mail to flagged recipients', function (): void {
    Mail::fake();

    $client = sendClient();
    addInvoiceRecipient($client, 'billing@client.com');
    $doc = sendDraft($client);

    app(BillingService::class)->sendInvoice($doc, User::factory()->create());

    Mail::assertSent(SalesInvoiceMail::class, fn ($mail) => $mail->hasTo('billing@client.com'));
    Mail::assertSent(SalesInvoiceMail::class, fn ($mail) => str_contains($mail->emailHtml, $doc->document_number));
});

it('sendInvoice transitions doc to sent', function (): void {
    Mail::fake();

    $client = sendClient();
    addInvoiceRecipient($client);
    $doc = sendDraft($client);

    app(BillingService::class)->sendInvoice($doc, User::factory()->create());

    expect($doc->fresh()->status)->toBe('sent');
});

it('sendInvoice honours explicit recipient list', function (): void {
    Mail::fake();

    $doc = sendDraft();

    app(BillingService::class)->sendInvoice($doc, User::factory()->create(), ['explicit@example.com']);

    Mail::assertSent(SalesInvoiceMail::class, fn ($m) => $m->hasTo('explicit@example.com'));
});

it('sendInvoice throws when there are no recipients', function (): void {
    $doc = sendDraft();

    expect(fn () => app(BillingService::class)->sendInvoice($doc, User::factory()->create()))
        ->toThrow(RuntimeException::class);
});

it('sendInvoice generates a PDF for the attachment', function (): void {
    Mail::fake();

    $client = sendClient();
    addInvoiceRecipient($client);
    $doc = sendDraft($client);

    app(BillingService::class)->sendInvoice($doc, User::factory()->create());

    expect($doc->fresh()->getFirstMedia('invoice_pdf'))->not->toBeNull();
});

it('sendInvoice resends an already sent invoice without changing its status', function (): void {
    Mail::fake();

    $client = sendClient();
    addInvoiceRecipient($client, 'billing@client.com');
    $doc = sendDraft($client);

    app(BillingService::class)->sendInvoice($doc, User::factory()->create());
    expect($doc->fresh()->status)->toBe('sent');

    app(BillingService::class)->sendInvoice($doc, User::factory()->create());

    expect($doc->fresh()->status)->toBe('sent');
    Mail::assertSent(SalesInvoiceMail::class, fn ($mail) => $mail->hasTo('billing@client.com'), 2);
});

it('sendInvoice records a resend activity for an already sent invoice', function (): void {
    Mail::fake();

    $client = sendClient();
    addInvoiceRecipient($client);
    $doc = sendDraft($client);

    app(BillingService::class)->sendInvoice($doc, User::factory()->create());
    app(BillingService::class)->sendInvoice($doc, User::factory()->create());

    expect($doc->activities()->where('activity_type', 'resent')->exists())->toBeTrue();
});

// --- Volt UI ---

it('fresh user lacks can-send-sales-invoices', function (): void {
    expect(User::factory()->create()->can('can-send-sales-invoices'))->toBeFalse();
});

it('openSendModal resolves recipients into component state', function (): void {
    $client = sendClient();
    addInvoiceRecipient($client, 'billing@client.com');
    $doc = sendDraft($client);

    $this->actingAs(sendUserWith(['documents-view-any', 'documents-view', 'can-send-sales-invoices']));

    $component = Volt::test('pages.sales-invoices.index')
        ->call('openDetail', $doc->id)
        ->call('openSendModal')
        ->assertSet('showSendModal', true);

    $recipients = $component->get('sendRecipients');
    expect($recipients)->toHaveCount(1)
        ->and($recipients[0]['email'])->toBe('billing@client.com')
        ->and($recipients[0]['selected'])->toBeTrue();
});

it('confirmSend sends mail and marks doc sent', function (): void {
    Mail::fake();

    $client = sendClient();
    addInvoiceRecipient($client, 'billing@client.com');
    $doc = sendDraft($client);

    $this->actingAs(sendUserWith(['documents-view-any', 'documents-view', 'can-send-sales-invoices']));

    Volt::test('pages.sales-invoices.index')
        ->call('openDetail', $doc->id)
        ->call('openSendModal')
        ->call('confirmSend');

    expect($doc->fresh()->status)->toBe('sent');
    Mail::assertSent(SalesInvoiceMail::class);
});
