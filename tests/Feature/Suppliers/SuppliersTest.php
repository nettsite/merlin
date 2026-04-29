<?php

namespace Tests\Feature\Suppliers;

use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
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
}
