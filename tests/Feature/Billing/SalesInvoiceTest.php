<?php

use App\Exceptions\InvalidDocumentStateException;
use App\Modules\Billing\Models\BillingEmailTemplate;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\PaymentTerm;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;
use App\Modules\Core\Services\PartyService;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

beforeEach(function (): void {
    BillingEmailTemplate::firstOrCreate(
        ['type' => 'invoice', 'name' => 'Default Invoice'],
        ['subject' => 'Invoice {{invoice_number}}', 'body' => '<p>Please find your invoice attached.</p>', 'enabled' => true],
    );
});

function siUserWith(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function siClient(): Party
{
    return app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Test Client Ltd',
        'trading_name' => 'Test Client',
        'status' => 'active',
    ], ['client']);
}

function siDraft(?Party $client = null): Document
{
    $client ??= siClient();

    return app(BillingService::class)->createDraft($client, [
        'issue_date' => now()->toDateString(),
    ]);
}

// --- Access ---

it('redirects unauthenticated users to login', function (): void {
    $this->get('/sales-invoices')->assertRedirect('/login');
});

it('forbids users without documents-view-any', function (): void {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.sales-invoices.index')->assertForbidden();
});

it('renders the sales invoices page', function (): void {
    $this->actingAs(siUserWith(['documents-view-any']));

    Volt::test('pages.sales-invoices.index')
        ->assertOk()
        ->assertSee('Sales Invoices');
});

// --- BillingService::createDraft ---

it('creates a draft document', function (): void {
    $client = siClient();

    $doc = app(BillingService::class)->createDraft($client, [
        'issue_date' => '2026-05-01',
    ]);

    expect($doc)->toBeInstanceOf(Document::class)
        ->and($doc->document_type)->toBe('sales_invoice')
        ->and($doc->direction)->toBe('outbound')
        ->and($doc->status)->toBe('draft')
        ->and($doc->party_id)->toBe($client->id)
        ->and($doc->issue_date->toDateString())->toBe('2026-05-01');
});

it('resolves payment term from client default', function (): void {
    $client = siClient();
    $term = PaymentTerm::factory()->daysAfterInvoice(30)->create(['name' => '30 Days']);

    $rel = $client->relationships()->where('relationship_type', 'client')->first();
    $rel->mergeMetadata(['payment_term_id' => $term->id]);

    $doc = app(BillingService::class)->createDraft($client, ['issue_date' => '2026-05-01']);

    expect($doc->payment_term_id)->toBe($term->id)
        ->and($doc->due_date->toDateString())->toBe('2026-05-31');
});

it('explicit term overrides the client default', function (): void {
    $client = siClient();
    $clientTerm = PaymentTerm::factory()->daysAfterInvoice(30)->create(['name' => '30 Days']);
    $explicitTerm = PaymentTerm::factory()->daysAfterInvoice(7)->create(['name' => '7 Days']);

    $rel = $client->relationships()->where('relationship_type', 'client')->first();
    $rel->mergeMetadata(['payment_term_id' => $clientTerm->id]);

    $doc = app(BillingService::class)->createDraft($client, [
        'issue_date' => '2026-05-01',
        'payment_term_id' => $explicitTerm->id,
    ]);

    expect($doc->payment_term_id)->toBe($explicitTerm->id)
        ->and($doc->due_date->toDateString())->toBe('2026-05-08');
});

// --- Lines ---

it('updates document totals when a line is added', function (): void {
    $doc = siDraft();

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
    expect($doc->subtotal)->toBe('1000.00')
        ->and($doc->tax_total)->toBe('150.00')
        ->and($doc->total)->toBe('1150.00');
});

// --- Status transitions ---

it('send transitions invoice to sent', function (): void {
    Mail::fake();

    $client = siClient();
    $personParty = app(PartyService::class)->createPerson([
        'first_name' => 'Billing',
        'last_name' => 'Contact',
        'email' => 'billing@client.com',
        'status' => 'active',
    ]);
    $client->assignContact($personParty->person, ['role' => 'billing', 'receives_invoices' => true, 'is_active' => true]);

    $doc = siDraft($client);
    $this->actingAs(siUserWith(['documents-view-any', 'documents-view', 'can-send-sales-invoices']));

    Volt::test('pages.sales-invoices.index')
        ->call('openDetail', $doc->id)
        ->call('openSendModal')
        ->call('confirmSend');

    expect($doc->fresh()->status)->toBe('sent');
});

it('voids a draft invoice', function (): void {
    $doc = siDraft();
    $this->actingAs(siUserWith(['documents-view-any', 'documents-view', 'can-void-sales-invoices']));

    Volt::test('pages.sales-invoices.index')
        ->call('openDetail', $doc->id)
        ->call('void');

    expect($doc->fresh()->status)->toBe('voided');
});

it('voids a sent invoice', function (): void {
    $doc = siDraft();
    $actor = User::factory()->create();
    app(DocumentService::class)->markAsSent($doc, $actor);

    $this->actingAs(siUserWith(['documents-view-any', 'documents-view', 'can-void-sales-invoices']));

    Volt::test('pages.sales-invoices.index')
        ->call('openDetail', $doc->id)
        ->call('void');

    expect($doc->fresh()->status)->toBe('voided');
});

it('fresh user lacks can-send-sales-invoices', function (): void {
    expect(User::factory()->create()->can('can-send-sales-invoices'))->toBeFalse();
});

it('cannot send a voided invoice', function (): void {
    $doc = siDraft();
    $actor = User::factory()->create();
    app(DocumentService::class)->voidDocument($doc, $actor);

    expect(fn () => app(DocumentService::class)->markAsSent($doc->fresh(), $actor))
        ->toThrow(InvalidDocumentStateException::class);
});

// --- Create via Volt UI ---

it('creates an invoice through the Volt UI', function (): void {
    $client = siClient();
    $this->actingAs(siUserWith(['documents-view-any', 'documents-view', 'documents-create']));

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
});
