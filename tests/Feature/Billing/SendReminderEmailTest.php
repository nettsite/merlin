<?php

use App\Mail\SalesInvoiceMail;
use App\Modules\Billing\Models\BillingEmailTemplate;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Nettsite\NettMail\Core\Domain\Templates\TemplateType;
use NettSite\NettMail\Models\Template;

function reminderEmailUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(['documents-view-any', 'documents-view', 'can-send-sales-invoices']);

    return $user;
}

function reminderEmailClient(): Party
{
    return app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Reminder Email Client Ltd',
        'trading_name' => 'Reminder Email Client',
        'status' => 'active',
    ], ['client']);
}

function reminderEmailRecipient(Party $client, string $email = 'billing@client.com'): void
{
    $personParty = app(PartyService::class)->createPerson([
        'first_name' => 'Billing',
        'last_name' => 'Contact',
        'email' => $email,
        'status' => 'active',
    ]);

    $client->assignContact($personParty->person, ['role' => 'billing', 'receives_invoices' => true, 'is_active' => true]);
}

/**
 * Status defaults to 'sent' with a non-zero balance — an open invoice eligible
 * for reminders. Pass balanceDue: 0.0 for a fully paid one.
 */
function openSalesInvoice(Party $client, float $balanceDue = 1000.00, string $status = 'sent'): Document
{
    return Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => $status,
        'party_id' => $client->id,
        'issue_date' => now()->toDateString(),
        'due_date' => now()->toDateString(),
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'total' => 1000.00,
        'balance_due' => $balanceDue,
        'source' => 'manual',
    ]);
}

function emailReminderTemplate(string $name = 'Early Reminder', int $offsetDays = -3): BillingEmailTemplate
{
    return BillingEmailTemplate::create([
        'type' => 'reminder',
        'name' => $name,
        'subject' => 'Reminder: {{invoice_number}}',
        'body' => 'Dear {{first_name}}, your invoice is due.',
        'offset_days' => $offsetDays,
        'enabled' => true,
    ]);
}

// --- BillingService::sendReminder() ---

it('sendReminder sends the legacy subject/body when no NettMail template is set', function (): void {
    Mail::fake();

    $client = reminderEmailClient();
    reminderEmailRecipient($client);
    $doc = openSalesInvoice($client);
    $reminder = emailReminderTemplate();

    app(BillingService::class)->sendReminder($doc, $reminder, null);

    Mail::assertSent(SalesInvoiceMail::class, fn (SalesInvoiceMail $mail): bool => $mail->hasTo('billing@client.com')
        && str_contains($mail->emailSubject, $doc->document_number)
        && str_contains($mail->emailHtml, 'your invoice is due'));
});

it('sendReminder uses the NettMail template when the reminder row has one set', function (): void {
    Mail::fake();

    $nettMailTemplate = Template::create([
        'name' => 'Reminder Email',
        'type' => TemplateType::Transactional,
        'subject' => 'NettMail reminder {{invoice_number}}',
        'html' => '<p>NettMail body for {{first_name}}</p>',
    ]);

    $client = reminderEmailClient();
    reminderEmailRecipient($client);
    $doc = openSalesInvoice($client);
    $reminder = emailReminderTemplate();
    $reminder->update(['nettmail_template_id' => $nettMailTemplate->id]);

    app(BillingService::class)->sendReminder($doc, $reminder, null);

    Mail::assertSent(SalesInvoiceMail::class, fn (SalesInvoiceMail $mail): bool => str_contains($mail->emailSubject, 'NettMail reminder')
        && str_contains($mail->emailHtml, 'NettMail body for Billing'));
});

it('sendReminder throws when the invoice has no recipients', function (): void {
    $client = reminderEmailClient();
    $doc = openSalesInvoice($client);
    $reminder = emailReminderTemplate();

    expect(fn () => app(BillingService::class)->sendReminder($doc, $reminder, null))
        ->toThrow(RuntimeException::class);
});

it('sendReminder regenerates the invoice PDF when the stored media file is missing from disk', function (): void {
    Mail::fake();

    $client = reminderEmailClient();
    reminderEmailRecipient($client);
    $doc = openSalesInvoice($client);
    $reminder = emailReminderTemplate();

    app(BillingService::class)->generatePdf($doc);
    $doc->refresh();
    unlink($doc->getFirstMedia('invoice_pdf')->getPath());

    app(BillingService::class)->sendReminder($doc, $reminder, null);

    Mail::assertSent(SalesInvoiceMail::class);
    expect(is_file($doc->fresh()->getFirstMedia('invoice_pdf')->getPath()))->toBeTrue();
});

it('sendReminder records a reminder_sent activity', function (): void {
    Mail::fake();

    $client = reminderEmailClient();
    reminderEmailRecipient($client);
    $doc = openSalesInvoice($client);
    $reminder = emailReminderTemplate();

    app(BillingService::class)->sendReminder($doc, $reminder, reminderEmailUser());

    expect($doc->activities()->where('activity_type', 'reminder_sent')->exists())->toBeTrue();
});

// --- BillingService::sendCustomEmail() ---

it('sendCustomEmail sends ad hoc content with merge tags substituted, with no BillingEmailTemplate involved', function (): void {
    Mail::fake();

    $client = reminderEmailClient();
    reminderEmailRecipient($client);
    $doc = openSalesInvoice($client);

    app(BillingService::class)->sendCustomEmail($doc, 'Custom subject {{invoice_number}}', 'Hello {{first_name}}, a note.', null);

    Mail::assertSent(SalesInvoiceMail::class, fn (SalesInvoiceMail $mail): bool => str_contains($mail->emailSubject, $doc->document_number)
        && str_contains($mail->emailHtml, 'Hello Billing, a note.'));
});

it('sendCustomEmail records a custom_email_sent activity', function (): void {
    Mail::fake();

    $client = reminderEmailClient();
    reminderEmailRecipient($client);
    $doc = openSalesInvoice($client);

    app(BillingService::class)->sendCustomEmail($doc, 'Subject', 'Body', reminderEmailUser());

    expect($doc->activities()->where('activity_type', 'custom_email_sent')->exists())->toBeTrue();
});

// --- BillingService::sendReminder() override (per-send edit) ---

it('sendReminder with an override sends the edited wording without changing the stored template', function (): void {
    Mail::fake();

    $client = reminderEmailClient();
    reminderEmailRecipient($client);
    $doc = openSalesInvoice($client);
    $reminder = emailReminderTemplate();

    app(BillingService::class)->sendReminder($doc, $reminder, null, 'Edited subject only for this send', 'Edited body only for this send');

    Mail::assertSent(SalesInvoiceMail::class, fn (SalesInvoiceMail $mail): bool => str_contains($mail->emailSubject, 'Edited subject only for this send')
        && str_contains($mail->emailHtml, 'Edited body only for this send'));

    expect($reminder->fresh()->subject)->toBe('Reminder: {{invoice_number}}')
        ->and($reminder->fresh()->body)->toBe('Dear {{first_name}}, your invoice is due.');
});

// --- Bulk "Send Email" action (sales invoices list) ---

it('bulk Send Email sends the chosen reminder only to eligible (unpaid, sent) invoices', function (): void {
    Mail::fake();

    $client = reminderEmailClient();
    reminderEmailRecipient($client);
    $open = openSalesInvoice($client, balanceDue: 1000.00);
    $paid = openSalesInvoice($client, balanceDue: 0.00);
    $reminder = emailReminderTemplate();

    $this->actingAs(reminderEmailUser());

    Livewire::test('pages.sales-invoices.index')
        ->set('selectedIds', [$open->id, $paid->id])
        ->call('openSendEmailModal')
        ->set('selectedEmailType', $reminder->id)
        ->assertSet('sendEligible', 1)
        ->assertSet('sendSkipped', 1)
        ->call('confirmSendEmail');

    Mail::assertSentCount(1);
    expect($open->activities()->where('activity_type', 'reminder_sent')->exists())->toBeTrue()
        ->and($paid->activities()->where('activity_type', 'reminder_sent')->exists())->toBeFalse();
});

it('bulk Send Email Custom Email is not balance-gated — a paid invoice is still eligible', function (): void {
    Mail::fake();

    $client = reminderEmailClient();
    reminderEmailRecipient($client);
    $paid = openSalesInvoice($client, balanceDue: 0.00);

    $this->actingAs(reminderEmailUser());

    Livewire::test('pages.sales-invoices.index')
        ->set('selectedIds', [$paid->id])
        ->call('openSendEmailModal')
        ->set('selectedEmailType', 'custom')
        ->assertSet('sendEligible', 1)
        ->set('emailSubjectOverride', 'Thanks for your business')
        ->set('emailBodyOverride', 'A note with no merge tags.')
        ->call('confirmSendEmail');

    Mail::assertSent(SalesInvoiceMail::class, fn (SalesInvoiceMail $mail): bool => str_contains($mail->emailSubject, 'Thanks for your business'));
});

it('bulk Send Email skips a draft invoice for every email type', function (): void {
    Mail::fake();

    $client = reminderEmailClient();
    reminderEmailRecipient($client);
    $draft = app(BillingService::class)->createDraft($client, ['issue_date' => now()->toDateString()]);
    $reminder = emailReminderTemplate();

    $this->actingAs(reminderEmailUser());

    Livewire::test('pages.sales-invoices.index')
        ->set('selectedIds', [$draft->id])
        ->call('openSendEmailModal')
        ->set('selectedEmailType', $reminder->id)
        ->assertSet('sendEligible', 0)
        ->assertSet('sendSkipped', 1);

    Mail::assertNothingSent();
});

it('bulk Send Email requires can-send-sales-invoices', function (): void {
    $client = reminderEmailClient();
    $doc = openSalesInvoice($client);

    $user = User::factory()->create();
    $user->givePermissionTo(['documents-view-any', 'documents-view']); // no can-send-sales-invoices
    $this->actingAs($user);

    Livewire::test('pages.sales-invoices.index')
        ->set('selectedIds', [$doc->id])
        ->call('openSendEmailModal')
        ->assertForbidden();
});
