<?php

namespace Tests\Feature\Suppliers;

use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
use App\Modules\Purchasing\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class SuppliersTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function createSupplier(array $override = []): Party
    {
        return app(PartyService::class)->createBusiness(array_merge([
            'business_type' => 'company',
            'legal_name' => fake()->company(),
            'primary_email' => fake()->safeEmail(),
            'status' => 'active',
        ], $override), ['supplier']);
    }

    public function test_unauthenticated_redirected_to_login(): void
    {
        $this->get('/suppliers')->assertRedirect('/login');
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('pages.suppliers.index')->assertForbidden();
    }

    public function test_suppliers_page_renders(): void
    {
        $this->actingAs($this->userWith(['parties-view-any']));

        Volt::test('pages.suppliers.index')
            ->assertOk()
            ->assertSee('Suppliers');
    }

    public function test_existing_suppliers_are_listed(): void
    {
        $party = $this->createSupplier(['legal_name' => 'Visible Supplier Ltd']);
        $this->actingAs($this->userWith(['parties-view-any']));

        Volt::test('pages.suppliers.index')
            ->assertSee('Visible Supplier Ltd');
    }

    public function test_can_create_supplier(): void
    {
        $this->actingAs($this->userWith(['parties-view-any', 'parties-create']));

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
    }

    public function test_create_requires_legal_name(): void
    {
        $this->actingAs($this->userWith(['parties-view-any', 'parties-create']));

        Volt::test('pages.suppliers.index')
            ->call('create')
            ->set('legalName', '')
            ->call('save')
            ->assertHasErrors(['legalName' => 'required']);
    }

    public function test_can_edit_supplier(): void
    {
        $party = $this->createSupplier(['legal_name' => 'Original Name Pty Ltd']);
        $this->actingAs($this->userWith(['parties-view-any', 'parties-update']));

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
    }

    public function test_can_delete_supplier(): void
    {
        $party = $this->createSupplier();
        $this->actingAs($this->userWith(['parties-view-any', 'parties-delete']));

        Volt::test('pages.suppliers.index')
            ->call('delete', $party->id)
            ->assertHasNoErrors();

        $this->assertSoftDeleted('parties', ['id' => $party->id]);
    }

    public function test_search_filters_results(): void
    {
        $this->createSupplier(['legal_name' => 'Alpha Corp']);
        $this->createSupplier(['legal_name' => 'Beta Ltd']);
        $this->actingAs($this->userWith(['parties-view-any']));

        Volt::test('pages.suppliers.index')
            ->set('search', 'Alpha')
            ->assertSee('Alpha Corp')
            ->assertDontSee('Beta Ltd');
    }

    public function test_status_filter_shows_only_matching_suppliers(): void
    {
        $this->createSupplier(['legal_name' => 'Active Supplier', 'status' => 'active']);
        $this->createSupplier(['legal_name' => 'Pending Supplier', 'status' => 'pending']);
        $this->actingAs($this->userWith(['parties-view-any']));

        Volt::test('pages.suppliers.index')
            ->set('filterStatus', 'active')
            ->assertSee('Active Supplier')
            ->assertDontSee('Pending Supplier');
    }

    public function test_approve_supplier_sets_status_to_active(): void
    {
        $party = $this->createSupplier(['status' => 'pending']);
        $this->actingAs($this->userWith(['parties-view-any', 'parties-update']));

        Volt::test('pages.suppliers.index')
            ->call('approveSupplier', $party->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('parties', ['id' => $party->id, 'status' => 'active']);
    }

    public function test_deactivate_supplier_sets_status_to_inactive(): void
    {
        $party = $this->createSupplier(['status' => 'active']);
        $this->actingAs($this->userWith(['parties-view-any', 'parties-update']));

        Volt::test('pages.suppliers.index')
            ->call('deactivateSupplier', $party->id)
            ->assertHasNoErrors();

        $this->assertDatabaseHas('parties', ['id' => $party->id, 'status' => 'inactive']);
    }

    public function test_bulk_approve_activates_selected_suppliers(): void
    {
        $pending = $this->createSupplier(['status' => 'pending']);
        $inactive = $this->createSupplier(['status' => 'inactive']);
        $this->actingAs($this->userWith(['parties-view-any', 'parties-update']));

        Volt::test('pages.suppliers.index')
            ->set('selectedIds', [$pending->id, $inactive->id])
            ->call('bulkApprove')
            ->assertHasNoErrors()
            ->assertSet('selectedIds', [])
            ->assertSet('selectAllFiltered', false);

        $this->assertDatabaseHas('parties', ['id' => $pending->id, 'status' => 'active']);
        $this->assertDatabaseHas('parties', ['id' => $inactive->id, 'status' => 'active']);
    }

    public function test_bulk_deactivate_deactivates_selected_suppliers(): void
    {
        $active = $this->createSupplier(['status' => 'active']);
        $this->actingAs($this->userWith(['parties-view-any', 'parties-update']));

        Volt::test('pages.suppliers.index')
            ->set('selectedIds', [$active->id])
            ->call('bulkDeactivate')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('parties', ['id' => $active->id, 'status' => 'inactive']);
    }

    public function test_select_all_filtered_applies_bulk_action_to_all(): void
    {
        $a = $this->createSupplier(['status' => 'pending']);
        $b = $this->createSupplier(['status' => 'pending']);
        $this->actingAs($this->userWith(['parties-view-any', 'parties-update']));

        Volt::test('pages.suppliers.index')
            ->set('filterStatus', 'pending')
            ->call('markSelectAllFiltered')
            ->assertSet('selectAllFiltered', true)
            ->call('bulkApprove')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('parties', ['id' => $a->id, 'status' => 'active']);
        $this->assertDatabaseHas('parties', ['id' => $b->id, 'status' => 'active']);
    }

    public function test_show_page_renders_supplier_info(): void
    {
        $party = $this->createSupplier(['legal_name' => 'Show Test Supplier', 'primary_email' => 'show@test.co.za']);
        $this->actingAs($this->userWith(['parties-view-any', 'parties-view']));

        Volt::test('pages.suppliers.show', ['id' => $party->id])
            ->assertOk()
            ->assertSee('Show Test Supplier')
            ->assertSee('show@test.co.za');
    }

    public function test_show_page_lists_supplier_invoices(): void
    {
        $party = $this->createSupplier();
        Document::factory()->create([
            'party_id' => $party->id,
            'document_type' => 'purchase_invoice',
            'direction' => 'inbound',
            'status' => 'received',
        ]);
        $this->actingAs($this->userWith(['parties-view-any', 'parties-view']));

        Volt::test('pages.suppliers.show', ['id' => $party->id])
            ->assertOk()
            ->assertSee('received');
    }

    public function test_show_page_invoice_status_filter(): void
    {
        $party = $this->createSupplier();
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
        $this->actingAs($this->userWith(['parties-view-any', 'parties-view']));

        Volt::test('pages.suppliers.show', ['id' => $party->id])
            ->set('invoiceStatus', 'posted')
            ->assertSee('PINV-TEST-001')
            ->assertDontSee('PINV-TEST-002');
    }

    public function test_show_page_approve_sets_status_active(): void
    {
        $party = $this->createSupplier(['status' => 'pending']);
        $this->actingAs($this->userWith(['parties-view-any', 'parties-view', 'parties-update']));

        Volt::test('pages.suppliers.show', ['id' => $party->id])
            ->call('approveSupplier')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('parties', ['id' => $party->id, 'status' => 'active']);
    }

    public function test_show_page_edit_saves_changes(): void
    {
        $party = $this->createSupplier(['legal_name' => 'Before Edit Ltd']);
        $this->actingAs($this->userWith(['parties-view-any', 'parties-view', 'parties-update']));

        Volt::test('pages.suppliers.show', ['id' => $party->id])
            ->call('openEditForm')
            ->assertSet('showEditForm', true)
            ->assertSet('legalName', 'Before Edit Ltd')
            ->set('legalName', 'After Edit Ltd')
            ->call('saveEdit')
            ->assertHasNoErrors()
            ->assertSet('showEditForm', false);

        $this->assertDatabaseHas('businesses', ['id' => $party->id, 'legal_name' => 'After Edit Ltd']);
    }
}
