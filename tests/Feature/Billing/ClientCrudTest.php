<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Models\PaymentTerm;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ClientCrudTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function makeClient(array $overrides = []): Party
    {
        return app(PartyService::class)->createBusiness(array_merge([
            'business_type' => 'company',
            'legal_name' => 'Test Client Ltd',
            'trading_name' => 'Test Client',
            'status' => 'active',
        ], $overrides), ['client']);
    }

    public function test_unauthenticated_redirected_to_login(): void
    {
        $this->get('/clients')->assertRedirect('/login');
    }

    public function test_user_without_permission_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('pages.clients.index')->assertForbidden();
    }

    public function test_page_renders(): void
    {
        $this->actingAs($this->userWith(['parties-view-any']));

        Volt::test('pages.clients.index')
            ->assertOk()
            ->assertSee('Clients');
    }

    public function test_existing_clients_are_listed(): void
    {
        $this->makeClient(['legal_name' => 'Acme Corp', 'trading_name' => 'Acme Corp']);
        $this->actingAs($this->userWith(['parties-view-any']));

        Volt::test('pages.clients.index')
            ->assertSee('Acme Corp');
    }

    public function test_can_create_client(): void
    {
        $this->actingAs($this->userWith(['parties-view-any', 'parties-create']));

        Volt::test('pages.clients.index')
            ->call('create')
            ->assertSet('showForm', true)
            ->set('legalName', 'New Client Ltd')
            ->set('tradingName', 'New Client')
            ->set('email', 'billing@newclient.com')
            ->call('save')
            ->assertSet('showForm', false);

        $party = Party::clients()->latest()->first();
        $this->assertNotNull($party);
        $this->assertEquals('New Client Ltd', $party->business?->legal_name);
    }

    public function test_can_edit_client_payment_term(): void
    {
        $client = $this->makeClient(['legal_name' => 'Edit Me Ltd']);
        $term = PaymentTerm::factory()->daysAfterInvoice(30)->create(['name' => '30 Days']);
        $this->actingAs($this->userWith(['parties-view-any', 'parties-update']));

        Volt::test('pages.clients.index')
            ->call('edit', $client->id)
            ->assertSet('showForm', true)
            ->assertSet('legalName', 'Edit Me Ltd')
            ->set('paymentTermId', $term->id)
            ->call('save')
            ->assertSet('showForm', false);

        $rel = $client->fresh()->relationships->firstWhere('relationship_type', 'client');
        $this->assertEquals($term->id, $rel->payment_term_id);
    }

    public function test_can_delete_client(): void
    {
        $client = $this->makeClient(['legal_name' => 'Delete Me Ltd']);
        $this->actingAs($this->userWith(['parties-view-any', 'parties-delete']));

        Volt::test('pages.clients.index')
            ->call('delete', $client->id)
            ->assertSet('showForm', false);

        $this->assertSoftDeleted('parties', ['id' => $client->id]);
    }

    public function test_suppliers_not_shown_in_clients_list(): void
    {
        app(PartyService::class)->createBusiness([
            'business_type' => 'company',
            'legal_name' => 'Only A Supplier Ltd',
            'status' => 'active',
        ], ['supplier']);

        $this->actingAs($this->userWith(['parties-view-any']));

        Volt::test('pages.clients.index')
            ->assertDontSee('Only A Supplier Ltd');
    }
}
