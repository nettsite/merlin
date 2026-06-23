<?php

use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\PaymentTerm;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
use Livewire\Volt\Volt;

function clientUserWith(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function makeClient(array $overrides = []): Party
{
    return app(PartyService::class)->createBusiness(array_merge([
        'business_type' => 'company',
        'legal_name' => 'Test Client Ltd',
        'trading_name' => 'Test Client',
        'status' => 'active',
    ], $overrides), ['client']);
}

it('redirects unauthenticated users to login', function (): void {
    $this->get('/clients')->assertRedirect('/login');
});

it('forbids users without parties-view-any', function (): void {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.clients.index')->assertForbidden();
});

it('renders the clients page', function (): void {
    $this->actingAs(clientUserWith(['parties-view-any']));

    Volt::test('pages.clients.index')
        ->assertOk()
        ->assertSee('Clients');
});

it('lists existing clients', function (): void {
    makeClient(['legal_name' => 'Acme Corp', 'trading_name' => 'Acme Corp']);
    $this->actingAs(clientUserWith(['parties-view-any']));

    Volt::test('pages.clients.index')->assertSee('Acme Corp');
});

it('creates a client', function (): void {
    $this->actingAs(clientUserWith(['parties-view-any', 'parties-create']));

    Volt::test('pages.clients.index')
        ->call('create')
        ->assertSet('showForm', true)
        ->set('legalName', 'New Client Ltd')
        ->set('tradingName', 'New Client')
        ->set('email', 'billing@newclient.com')
        ->call('save')
        ->assertSet('showForm', false);

    $party = Party::clients()->latest()->first();
    expect($party)->not->toBeNull()
        ->and($party->business?->legal_name)->toBe('New Client Ltd');
});

it('edits a client payment term', function (): void {
    $client = makeClient(['legal_name' => 'Edit Me Ltd']);
    $term = PaymentTerm::factory()->daysAfterInvoice(30)->create(['name' => '30 Days']);
    $this->actingAs(clientUserWith(['parties-view-any', 'parties-update']));

    Volt::test('pages.clients.index')
        ->call('edit', $client->id)
        ->assertSet('showForm', true)
        ->assertSet('legalName', 'Edit Me Ltd')
        ->set('paymentTermId', $term->id)
        ->call('save')
        ->assertSet('showForm', false);

    $rel = $client->fresh()->relationships->firstWhere('relationship_type', 'client');
    expect($rel->payment_term_id)->toBe($term->id);
});

it('soft-deletes a client', function (): void {
    $client = makeClient(['legal_name' => 'Delete Me Ltd']);
    $this->actingAs(clientUserWith(['parties-view-any', 'parties-delete']));

    Volt::test('pages.clients.index')
        ->call('delete', $client->id)
        ->assertSet('showForm', false);

    $this->assertSoftDeleted('parties', ['id' => $client->id]);
});

it('does not show suppliers in the clients list', function (): void {
    app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Only A Supplier Ltd',
        'status' => 'active',
    ], ['supplier']);

    $this->actingAs(clientUserWith(['parties-view-any']));

    Volt::test('pages.clients.index')->assertDontSee('Only A Supplier Ltd');
});
