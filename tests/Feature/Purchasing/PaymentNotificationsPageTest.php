<?php

use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function pmtNotifUser(string ...$permissions): User
{
    $user = User::factory()->create();
    foreach ($permissions as $perm) {
        Permission::findOrCreate($perm, 'web');
        $user->givePermissionTo($perm);
    }

    return $user;
}

it('lists unmatched payment notifications with amount and reference columns', function (): void {
    $this->actingAs(pmtNotifUser('documents-view-any', 'documents-view'));

    Document::factory()->create([
        'document_type' => 'payment_notification',
        'status' => 'received',
        'party_id' => null,
        'currency' => 'ZAR',
        'total' => 450.0,
        'reference' => 'INV-1234',
        'metadata' => ['payee_name' => 'Domains CoZa', 'method' => 'FNB Connect'],
    ]);

    Livewire::test('pages.payment-notifications.index')
        ->assertSee('Domains CoZa')
        ->assertSee('INV-1234')
        ->assertSee('FNB Connect')
        ->assertSee('450.00');
});

it('does not list matched/merged payment notifications', function (): void {
    $this->actingAs(pmtNotifUser('documents-view-any', 'documents-view'));

    Document::factory()->create([
        'document_type' => 'payment_notification',
        'status' => 'received',
        'party_id' => null,
        'metadata' => ['payee_name' => 'Still Pending'],
    ]);

    Livewire::test('pages.payment-notifications.index')
        ->assertSee('Still Pending')
        ->assertDontSee('No unmatched payments');
});

it('filters by search term across payee, reference, and method', function (): void {
    $this->actingAs(pmtNotifUser('documents-view-any', 'documents-view'));

    Document::factory()->create([
        'document_type' => 'payment_notification',
        'status' => 'received',
        'party_id' => null,
        'reference' => 'INV-1234',
        'metadata' => ['payee_name' => 'Domains CoZa'],
    ]);
    Document::factory()->create([
        'document_type' => 'payment_notification',
        'status' => 'received',
        'party_id' => null,
        'reference' => 'INV-9999',
        'metadata' => ['payee_name' => 'Zoom Connect'],
    ]);

    Livewire::test('pages.payment-notifications.index')
        ->set('search', 'Domains')
        ->assertSee('Domains CoZa')
        ->assertDontSee('Zoom Connect');
});

it('manually links a payment notification to a chosen invoice via the searchable select', function (): void {
    $this->actingAs(pmtNotifUser('documents-view-any', 'documents-view', 'documents-create', 'documents-update'));

    $invoice = Document::factory()->purchaseInvoice()->create();

    $notification = Document::factory()->create([
        'document_type' => 'payment_notification',
        'status' => 'received',
        'party_id' => null,
        'currency' => 'ZAR',
        'total' => 450.0,
        'metadata' => ['payee_name' => 'Domains CoZa'],
    ]);

    Livewire::test('pages.payment-notifications.index')
        ->call('openLinkModal', $notification->id)
        ->assertSet('showLinkModal', true)
        ->call('selectLinkInvoice', $invoice->id)
        ->assertSet('linkInvoiceId', $invoice->id)
        ->call('confirmLink')
        ->assertSet('showLinkModal', false);

    expect(Document::find($notification->id))->toBeNull()
        ->and($invoice->fresh()->metadata['payment_notification']['payee_name'] ?? null)->toBe('Domains CoZa');
});

it('orders the invoice search results by supplier name', function (): void {
    $this->actingAs(pmtNotifUser('documents-view-any', 'documents-view', 'documents-create'));

    $supplierZ = app(PartyService::class)->createBusiness([
        'business_type' => 'company', 'legal_name' => 'Zulu Traders', 'trading_name' => 'Zulu Traders', 'status' => 'active',
    ], ['supplier']);
    $supplierA = app(PartyService::class)->createBusiness([
        'business_type' => 'company', 'legal_name' => 'Acme Hosting', 'trading_name' => 'Acme Hosting', 'status' => 'active',
    ], ['supplier']);

    Document::factory()->purchaseInvoice()->create(['party_id' => $supplierZ->id]);
    Document::factory()->purchaseInvoice()->create(['party_id' => $supplierA->id]);

    $notification = Document::factory()->create([
        'document_type' => 'payment_notification',
        'status' => 'received',
        'party_id' => null,
    ]);

    $html = Livewire::test('pages.payment-notifications.index')
        ->call('openLinkModal', $notification->id)
        ->html();

    expect(strpos($html, 'Acme Hosting'))->toBeLessThan(strpos($html, 'Zulu Traders'));
});

it('filters the invoice search results by typed supplier name', function (): void {
    $this->actingAs(pmtNotifUser('documents-view-any', 'documents-view', 'documents-create'));

    $supplierA = app(PartyService::class)->createBusiness([
        'business_type' => 'company', 'legal_name' => 'Acme Hosting', 'trading_name' => 'Acme Hosting', 'status' => 'active',
    ], ['supplier']);
    $supplierB = app(PartyService::class)->createBusiness([
        'business_type' => 'company', 'legal_name' => 'Beta Traders', 'trading_name' => 'Beta Traders', 'status' => 'active',
    ], ['supplier']);

    Document::factory()->purchaseInvoice()->create(['party_id' => $supplierA->id]);
    Document::factory()->purchaseInvoice()->create(['party_id' => $supplierB->id]);

    $notification = Document::factory()->create([
        'document_type' => 'payment_notification',
        'status' => 'received',
        'party_id' => null,
    ]);

    Livewire::test('pages.payment-notifications.index')
        ->call('openLinkModal', $notification->id)
        ->set('linkInvoiceSearch', 'Acme')
        ->assertSee('Acme Hosting')
        ->assertDontSee('Beta Traders');
});

it('shows the invoice date and both base and foreign amounts in the search results', function (): void {
    $this->actingAs(pmtNotifUser('documents-view-any', 'documents-view', 'documents-create'));

    $supplier = app(PartyService::class)->createBusiness([
        'business_type' => 'company', 'legal_name' => 'Acme Hosting', 'trading_name' => 'Acme Hosting', 'status' => 'active',
    ], ['supplier']);

    Document::factory()->purchaseInvoice()->create([
        'party_id' => $supplier->id,
        'issue_date' => '2026-03-15',
        'currency' => 'USD',
        'exchange_rate' => 18.5,
        'total' => 1850.0,
        'foreign_total' => 100.0,
    ]);

    $notification = Document::factory()->create([
        'document_type' => 'payment_notification',
        'status' => 'received',
        'party_id' => null,
    ]);

    Livewire::test('pages.payment-notifications.index')
        ->call('openLinkModal', $notification->id)
        ->assertSee('15 Mar 2026')
        ->assertSee('1,850.00')
        ->assertSee('100.00');
});
