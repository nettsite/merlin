<?php

namespace Tests\Feature\Billing;

use App\Exceptions\InvalidDocumentStateException;
use App\Modules\Billing\Models\PaymentTerm;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SalesInvoiceTest extends TestCase
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

    // -------------------------------------------------------------------------
    // Access
    // -------------------------------------------------------------------------

    public function test_unauthenticated_redirected_to_login(): void
    {
        $this->get('/sales-invoices')->assertRedirect('/login');
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('pages.sales-invoices.index')->assertForbidden();
    }

    public function test_page_renders(): void
    {
        $this->actingAs($this->userWith(['documents-view-any']));

        Volt::test('pages.sales-invoices.index')
            ->assertOk()
            ->assertSee('Sales Invoices');
    }

    // -------------------------------------------------------------------------
    // BillingService::createDraft
    // -------------------------------------------------------------------------

    public function test_create_draft_creates_document(): void
    {
        $client = $this->makeClient();

        $doc = app(BillingService::class)->createDraft($client, [
            'issue_date' => '2026-05-01',
        ]);

        $this->assertInstanceOf(Document::class, $doc);
        $this->assertEquals('sales_invoice', $doc->document_type);
        $this->assertEquals('outbound', $doc->direction);
        $this->assertEquals('draft', $doc->status);
        $this->assertEquals($client->id, $doc->party_id);
        $this->assertEquals('2026-05-01', $doc->issue_date->toDateString());
    }

    public function test_create_draft_resolves_payment_term_from_client(): void
    {
        $client = $this->makeClient();
        $term = PaymentTerm::factory()->daysAfterInvoice(30)->create(['name' => '30 Days']);

        $rel = $client->relationships()->where('relationship_type', 'client')->first();
        $rel->mergeMetadata(['payment_term_id' => $term->id]);

        $doc = app(BillingService::class)->createDraft($client, [
            'issue_date' => '2026-05-01',
        ]);

        $this->assertEquals($term->id, $doc->payment_term_id);
        $this->assertEquals('2026-05-31', $doc->due_date->toDateString());
    }

    public function test_create_draft_explicit_term_overrides_client_default(): void
    {
        $client = $this->makeClient();
        $clientTerm = PaymentTerm::factory()->daysAfterInvoice(30)->create(['name' => '30 Days']);
        $explicitTerm = PaymentTerm::factory()->daysAfterInvoice(7)->create(['name' => '7 Days']);

        $rel = $client->relationships()->where('relationship_type', 'client')->first();
        $rel->mergeMetadata(['payment_term_id' => $clientTerm->id]);

        $doc = app(BillingService::class)->createDraft($client, [
            'issue_date' => '2026-05-01',
            'payment_term_id' => $explicitTerm->id,
        ]);

        $this->assertEquals($explicitTerm->id, $doc->payment_term_id);
        $this->assertEquals('2026-05-08', $doc->due_date->toDateString());
    }

    // -------------------------------------------------------------------------
    // Lines
    // -------------------------------------------------------------------------

    public function test_add_line_updates_document_totals(): void
    {
        $doc = $this->makeDraft();

        $doc->lines()->create([
            'line_number' => 1,
            'type' => 'service',
            'description' => 'Consulting',
            'quantity' => 1,
            'unit_price' => 1000.00,
            'discount_percent' => 0,
            'discount_amount' => 0,
            'tax_rate' => 15.00,
        ]);

        $doc->refresh();
        $this->assertEquals('1000.00', $doc->subtotal);
        $this->assertEquals('150.00', $doc->tax_total);
        $this->assertEquals('1150.00', $doc->total);
    }

    // -------------------------------------------------------------------------
    // Status transitions
    // -------------------------------------------------------------------------

    public function test_send_invoice_transitions_to_sent(): void
    {
        Mail::fake();

        $client = $this->makeClient();
        $personParty = app(PartyService::class)->createPerson([
            'first_name' => 'Billing',
            'last_name' => 'Contact',
            'email' => 'billing@client.com',
            'status' => 'active',
        ]);
        $client->assignContact($personParty->person, ['role' => 'billing', 'receives_invoices' => true, 'is_active' => true]);

        $doc = $this->makeDraft($client);
        $user = $this->userWith(['documents-view-any', 'documents-view', 'can-send-sales-invoices']);
        $this->actingAs($user);

        Volt::test('pages.sales-invoices.index')
            ->call('openDetail', $doc->id)
            ->call('openSendModal')
            ->call('confirmSend');

        $this->assertEquals('sent', $doc->fresh()->status);
    }

    public function test_void_draft_invoice(): void
    {
        $doc = $this->makeDraft();
        $user = $this->userWith(['documents-view-any', 'documents-view', 'can-void-sales-invoices']);
        $this->actingAs($user);

        Volt::test('pages.sales-invoices.index')
            ->call('openDetail', $doc->id)
            ->call('void');

        $this->assertEquals('voided', $doc->fresh()->status);
    }

    public function test_void_sent_invoice(): void
    {
        $doc = $this->makeDraft();
        $actor = User::factory()->create();
        app(DocumentService::class)->markAsSent($doc, $actor);

        $user = $this->userWith(['documents-view-any', 'documents-view', 'can-void-sales-invoices']);
        $this->actingAs($user);

        Volt::test('pages.sales-invoices.index')
            ->call('openDetail', $doc->id)
            ->call('void');

        $this->assertEquals('voided', $doc->fresh()->status);
    }

    public function test_cannot_send_without_permission(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->can('can-send-sales-invoices'));
    }

    public function test_cannot_send_voided_invoice(): void
    {
        $doc = $this->makeDraft();
        $actor = User::factory()->create();
        app(DocumentService::class)->voidDocument($doc, $actor);

        $this->expectException(InvalidDocumentStateException::class);

        app(DocumentService::class)->markAsSent($doc->fresh(), $actor);
    }

    // -------------------------------------------------------------------------
    // Create via Volt UI
    // -------------------------------------------------------------------------

    public function test_create_invoice_via_ui(): void
    {
        $client = $this->makeClient();
        $user = $this->userWith(['documents-view-any', 'documents-view', 'documents-create']);
        $this->actingAs($user);

        Volt::test('pages.sales-invoices.index')
            ->call('openCreate')
            ->assertSet('showCreateModal', true)
            ->set('createForm.party_id', $client->id)
            ->set('createForm.issue_date', '2026-05-01')
            ->call('createInvoice')
            ->assertSet('showCreateModal', false)
            ->assertSet('showDetail', true);

        $this->assertDatabaseHas('documents', [
            'document_type' => 'sales_invoice',
            'party_id' => $client->id,
            'status' => 'draft',
        ]);
    }
}
