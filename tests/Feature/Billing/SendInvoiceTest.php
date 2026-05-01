<?php

namespace Tests\Feature\Billing;

use App\Mail\SalesInvoiceMail;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
use App\Modules\Purchasing\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use RuntimeException;
use Tests\TestCase;

class SendInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function makeClient(): Party
    {
        return app(PartyService::class)->createBusiness([
            'business_type' => 'company',
            'legal_name' => 'Test Client Ltd',
            'trading_name' => 'Test Client',
            'status' => 'active',
        ], ['client']);
    }

    private function makeDraft(?Party $client = null): Document
    {
        $client ??= $this->makeClient();

        return app(BillingService::class)->createDraft($client, [
            'issue_date' => now()->toDateString(),
        ]);
    }

    private function addInvoiceRecipient(Party $client, string $email = 'contact@example.com'): void
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

    // -------------------------------------------------------------------------
    // generatePdf
    // -------------------------------------------------------------------------

    public function test_generate_pdf_stores_media(): void
    {
        $doc = $this->makeDraft();

        app(BillingService::class)->generatePdf($doc);

        $doc->refresh();
        $this->assertNotNull($doc->getFirstMedia('invoice_pdf'));
    }

    public function test_generate_pdf_replaces_existing(): void
    {
        $doc = $this->makeDraft();

        app(BillingService::class)->generatePdf($doc);
        app(BillingService::class)->generatePdf($doc);

        $doc->refresh();
        $this->assertCount(1, $doc->getMedia('invoice_pdf'));
    }

    // -------------------------------------------------------------------------
    // resolveRecipients
    // -------------------------------------------------------------------------

    public function test_resolve_recipients_returns_flagged_contacts(): void
    {
        $client = $this->makeClient();
        $this->addInvoiceRecipient($client, 'billing@client.com');
        $doc = $this->makeDraft($client);

        $recipients = app(BillingService::class)->resolveRecipients($doc);

        $this->assertCount(1, $recipients);
        $this->assertEquals('billing@client.com', $recipients[0]['email']);
    }

    public function test_resolve_recipients_ignores_non_flagged_contacts(): void
    {
        $client = $this->makeClient();
        $personParty = app(PartyService::class)->createPerson([
            'first_name' => 'Other',
            'last_name' => 'Contact',
            'email' => 'other@client.com',
            'status' => 'active',
        ]);
        $client->assignContact($personParty->person, ['role' => 'general', 'receives_invoices' => false, 'is_active' => true]);
        $doc = $this->makeDraft($client);

        $recipients = app(BillingService::class)->resolveRecipients($doc);

        $this->assertEmpty($recipients);
    }

    // -------------------------------------------------------------------------
    // sendInvoice
    // -------------------------------------------------------------------------

    public function test_send_invoice_queues_mail_with_attachment(): void
    {
        Mail::fake();

        $client = $this->makeClient();
        $this->addInvoiceRecipient($client, 'billing@client.com');
        $doc = $this->makeDraft($client);
        $user = User::factory()->create();

        app(BillingService::class)->sendInvoice($doc, $user);

        Mail::assertSent(SalesInvoiceMail::class, function ($mail) {
            return $mail->hasTo('billing@client.com');
        });
    }

    public function test_send_invoice_transitions_to_sent(): void
    {
        Mail::fake();

        $client = $this->makeClient();
        $this->addInvoiceRecipient($client);
        $doc = $this->makeDraft($client);
        $user = User::factory()->create();

        app(BillingService::class)->sendInvoice($doc, $user);

        $this->assertEquals('sent', $doc->fresh()->status);
    }

    public function test_send_invoice_with_explicit_recipients(): void
    {
        Mail::fake();

        $doc = $this->makeDraft();
        $user = User::factory()->create();

        app(BillingService::class)->sendInvoice($doc, $user, ['explicit@example.com']);

        Mail::assertSent(SalesInvoiceMail::class, fn ($m) => $m->hasTo('explicit@example.com'));
    }

    public function test_send_invoice_throws_when_no_recipients(): void
    {
        $doc = $this->makeDraft();
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);

        app(BillingService::class)->sendInvoice($doc, $user);
    }

    public function test_send_invoice_generates_pdf_attachment(): void
    {
        Mail::fake();

        $client = $this->makeClient();
        $this->addInvoiceRecipient($client);
        $doc = $this->makeDraft($client);
        $user = User::factory()->create();

        app(BillingService::class)->sendInvoice($doc, $user);

        $doc->refresh();
        $this->assertNotNull($doc->getFirstMedia('invoice_pdf'));
    }

    // -------------------------------------------------------------------------
    // Volt UI — open send modal
    // -------------------------------------------------------------------------

    public function test_open_send_modal_requires_permission(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->can('can-send-sales-invoices'));
    }

    public function test_open_send_modal_resolves_recipients(): void
    {
        $client = $this->makeClient();
        $this->addInvoiceRecipient($client, 'billing@client.com');
        $doc = $this->makeDraft($client);

        $user = $this->userWith(['documents-view-any', 'documents-view', 'can-send-sales-invoices']);
        $this->actingAs($user);

        $component = Volt::test('pages.sales-invoices.index')
            ->call('openDetail', $doc->id)
            ->call('openSendModal');

        $component->assertSet('showSendModal', true);
        $recipients = $component->get('sendRecipients');
        $this->assertCount(1, $recipients);
        $this->assertEquals('billing@client.com', $recipients[0]['email']);
        $this->assertTrue($recipients[0]['selected']);
    }

    public function test_confirm_send_via_volt(): void
    {
        Mail::fake();

        $client = $this->makeClient();
        $this->addInvoiceRecipient($client, 'billing@client.com');
        $doc = $this->makeDraft($client);

        $user = $this->userWith(['documents-view-any', 'documents-view', 'can-send-sales-invoices']);
        $this->actingAs($user);

        Volt::test('pages.sales-invoices.index')
            ->call('openDetail', $doc->id)
            ->call('openSendModal')
            ->call('confirmSend');

        $this->assertEquals('sent', $doc->fresh()->status);
        Mail::assertSent(SalesInvoiceMail::class);
    }
}
