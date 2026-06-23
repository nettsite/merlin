<?php

use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
use Livewire\Volt\Volt;

function supplierUserWith(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function makeSupplier(array $override = []): Party
{
    return app(PartyService::class)->createBusiness(array_merge([
        'business_type' => 'company',
        'legal_name' => fake()->company(),
        'primary_email' => fake()->safeEmail(),
        'status' => 'active',
    ], $override), ['supplier']);
}

it('redirects unauthenticated users to login', function (): void {
    $this->get('/suppliers')->assertRedirect('/login');
});

it('forbids users without parties-view-any', function (): void {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.suppliers.index')->assertForbidden();
});

it('renders the suppliers page for permitted users', function (): void {
    $this->actingAs(supplierUserWith(['parties-view-any']));

    Volt::test('pages.suppliers.index')
        ->assertOk()
        ->assertSee('Suppliers');
});

it('lists existing suppliers', function (): void {
    makeSupplier(['legal_name' => 'Visible Supplier Ltd']);
    $this->actingAs(supplierUserWith(['parties-view-any']));

    Volt::test('pages.suppliers.index')
        ->assertSee('Visible Supplier Ltd');
});

it('creates a supplier', function (): void {
    $this->actingAs(supplierUserWith(['parties-view-any', 'parties-create']));

    Volt::test('pages.suppliers.index')
        ->call('create')
        ->assertSet('showForm', true)
        ->set('legalName', 'New Supplier Pty Ltd')
        ->set('email', 'accounts@newsupplier.co.za')
        ->set('status', 'active')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    $this->assertDatabaseHas('businesses', ['legal_name' => 'New Supplier Pty Ltd']);
});

it('requires legalName on create', function (): void {
    $this->actingAs(supplierUserWith(['parties-view-any', 'parties-create']));

    Volt::test('pages.suppliers.index')
        ->call('create')
        ->set('legalName', '')
        ->call('save')
        ->assertHasErrors(['legalName' => 'required']);
});

it('edits a supplier', function (): void {
    $party = makeSupplier(['legal_name' => 'Original Name Pty Ltd']);
    $this->actingAs(supplierUserWith(['parties-view-any', 'parties-update']));

    Volt::test('pages.suppliers.index')
        ->call('edit', $party->id)
        ->assertSet('showForm', true)
        ->assertSet('legalName', 'Original Name Pty Ltd')
        ->set('legalName', 'Updated Name Pty Ltd')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('showForm', false);

    $this->assertDatabaseHas('businesses', [
        'id' => $party->id,
        'legal_name' => 'Updated Name Pty Ltd',
    ]);
});

it('soft-deletes a supplier', function (): void {
    $party = makeSupplier();
    $this->actingAs(supplierUserWith(['parties-view-any', 'parties-delete']));

    Volt::test('pages.suppliers.index')
        ->call('delete', $party->id)
        ->assertHasNoErrors();

    $this->assertSoftDeleted('parties', ['id' => $party->id]);
});

it('filters by search term', function (): void {
    makeSupplier(['legal_name' => 'Alpha Corp']);
    makeSupplier(['legal_name' => 'Beta Ltd']);
    $this->actingAs(supplierUserWith(['parties-view-any']));

    Volt::test('pages.suppliers.index')
        ->set('search', 'Alpha')
        ->assertSee('Alpha Corp')
        ->assertDontSee('Beta Ltd');
});

it('filters by status', function (): void {
    makeSupplier(['legal_name' => 'Active Supplier', 'status' => 'active']);
    makeSupplier(['legal_name' => 'Pending Supplier', 'status' => 'pending']);
    $this->actingAs(supplierUserWith(['parties-view-any']));

    Volt::test('pages.suppliers.index')
        ->set('filterStatus', 'active')
        ->assertSee('Active Supplier')
        ->assertDontSee('Pending Supplier');
});

it('approves a supplier to active status', function (): void {
    $party = makeSupplier(['status' => 'pending']);
    $this->actingAs(supplierUserWith(['parties-view-any', 'parties-update']));

    Volt::test('pages.suppliers.index')
        ->call('approveSupplier', $party->id)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('parties', ['id' => $party->id, 'status' => 'active']);
});

it('deactivates a supplier', function (): void {
    $party = makeSupplier(['status' => 'active']);
    $this->actingAs(supplierUserWith(['parties-view-any', 'parties-update']));

    Volt::test('pages.suppliers.index')
        ->call('deactivateSupplier', $party->id)
        ->assertHasNoErrors();

    $this->assertDatabaseHas('parties', ['id' => $party->id, 'status' => 'inactive']);
});

it('bulk approves selected suppliers', function (): void {
    $pending = makeSupplier(['status' => 'pending']);
    $inactive = makeSupplier(['status' => 'inactive']);
    $this->actingAs(supplierUserWith(['parties-view-any', 'parties-update']));

    Volt::test('pages.suppliers.index')
        ->set('selectedIds', [$pending->id, $inactive->id])
        ->call('bulkApprove')
        ->assertHasNoErrors()
        ->assertSet('selectedIds', [])
        ->assertSet('selectAllFiltered', false);

    $this->assertDatabaseHas('parties', ['id' => $pending->id, 'status' => 'active']);
    $this->assertDatabaseHas('parties', ['id' => $inactive->id, 'status' => 'active']);
});

it('bulk deactivates selected suppliers', function (): void {
    $active = makeSupplier(['status' => 'active']);
    $this->actingAs(supplierUserWith(['parties-view-any', 'parties-update']));

    Volt::test('pages.suppliers.index')
        ->set('selectedIds', [$active->id])
        ->call('bulkDeactivate')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('parties', ['id' => $active->id, 'status' => 'inactive']);
});

it('selectAllFiltered applies bulk action to every match', function (): void {
    $a = makeSupplier(['status' => 'pending']);
    $b = makeSupplier(['status' => 'pending']);
    $this->actingAs(supplierUserWith(['parties-view-any', 'parties-update']));

    Volt::test('pages.suppliers.index')
        ->set('filterStatus', 'pending')
        ->call('markSelectAllFiltered')
        ->assertSet('selectAllFiltered', true)
        ->call('bulkApprove')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('parties', ['id' => $a->id, 'status' => 'active']);
    $this->assertDatabaseHas('parties', ['id' => $b->id, 'status' => 'active']);
});

it('renders the supplier detail page with party info', function (): void {
    $party = makeSupplier(['legal_name' => 'Show Test Supplier', 'primary_email' => 'show@test.co.za']);
    $this->actingAs(supplierUserWith(['parties-view-any', 'parties-view']));

    Volt::test('pages.suppliers.show', ['id' => $party->id])
        ->assertOk()
        ->assertSee('Show Test Supplier')
        ->assertSee('show@test.co.za');
});

it('lists supplier invoices on the show page', function (): void {
    $party = makeSupplier();
    Document::factory()->create([
        'party_id' => $party->id,
        'document_type' => 'purchase_invoice',
        'direction' => 'inbound',
        'status' => 'received',
    ]);
    $this->actingAs(supplierUserWith(['parties-view-any', 'parties-view']));

    Volt::test('pages.suppliers.show', ['id' => $party->id])
        ->assertOk()
        ->assertSee('received');
});

it('filters supplier invoices by status on the show page', function (): void {
    $party = makeSupplier();
    Document::factory()->create([
        'party_id' => $party->id,
        'document_type' => 'purchase_invoice',
        'direction' => 'inbound',
        'status' => 'posted',
        'document_number' => 'PINV-TEST-001',
    ]);
    Document::factory()->create([
        'party_id' => $party->id,
        'document_type' => 'purchase_invoice',
        'direction' => 'inbound',
        'status' => 'received',
        'document_number' => 'PINV-TEST-002',
    ]);
    $this->actingAs(supplierUserWith(['parties-view-any', 'parties-view']));

    Volt::test('pages.suppliers.show', ['id' => $party->id])
        ->set('invoiceStatus', 'posted')
        ->assertSee('PINV-TEST-001')
        ->assertDontSee('PINV-TEST-002');
});

it('approves a supplier from the show page', function (): void {
    $party = makeSupplier(['status' => 'pending']);
    $this->actingAs(supplierUserWith(['parties-view-any', 'parties-view', 'parties-update']));

    Volt::test('pages.suppliers.show', ['id' => $party->id])
        ->call('approveSupplier')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('parties', ['id' => $party->id, 'status' => 'active']);
});

it('edits a supplier from the show page', function (): void {
    $party = makeSupplier(['legal_name' => 'Before Edit Ltd']);
    $this->actingAs(supplierUserWith(['parties-view-any', 'parties-view', 'parties-update']));

    Volt::test('pages.suppliers.show', ['id' => $party->id])
        ->call('openEditForm')
        ->assertSet('showEditForm', true)
        ->assertSet('legalName', 'Before Edit Ltd')
        ->set('legalName', 'After Edit Ltd')
        ->call('saveEdit')
        ->assertHasNoErrors()
        ->assertSet('showEditForm', false);

    $this->assertDatabaseHas('businesses', ['id' => $party->id, 'legal_name' => 'After Edit Ltd']);
});
