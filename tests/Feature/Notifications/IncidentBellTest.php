<?php

use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\NotificationIncident;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function bellUser(): User
{
    $user = User::factory()->create();
    Permission::findOrCreate('documents-view-any', 'web');
    $user->givePermissionTo('documents-view-any');

    return $user;
}

it('shows no badge when there are no active incidents', function (): void {
    $this->actingAs(bellUser());

    Livewire::test('incident-bell')
        ->call('checkIncidents')
        ->assertSee('No active notifications');
});

it('creates an incident and dispatches a toast once for an unposted invoice', function (): void {
    $this->actingAs(bellUser());
    Document::factory()->purchaseInvoice()->create(['status' => 'received']);

    Livewire::test('incident-bell')
        ->call('checkIncidents')
        ->assertDispatched('incident-toast')
        ->assertSee('Unposted invoices')
        ->assertSee('1 purchase invoice');

    expect(NotificationIncident::query()->active()->count())->toBe(1)
        ->and(NotificationIncident::query()->active()->first()->seen_at)->not->toBeNull();
});

it('does not dispatch the toast again once the incident has been seen', function (): void {
    $this->actingAs(bellUser());
    Document::factory()->purchaseInvoice()->create(['status' => 'received']);

    $component = Livewire::test('incident-bell')->call('checkIncidents');
    $component->assertDispatched('incident-toast');

    Livewire::test('incident-bell')
        ->call('checkIncidents')
        ->assertNotDispatched('incident-toast');
});

it('clears the incident once all invoices are posted', function (): void {
    $this->actingAs(bellUser());
    $doc = Document::factory()->purchaseInvoice()->create(['status' => 'received']);

    Livewire::test('incident-bell')->call('checkIncidents');
    expect(NotificationIncident::query()->active()->count())->toBe(1);

    $doc->update(['status' => 'posted']);

    Livewire::test('incident-bell')->call('checkIncidents');
    expect(NotificationIncident::query()->active()->count())->toBe(0);
});

it('hides incidents from a user without documents-view-any permission', function (): void {
    $this->actingAs(User::factory()->create());
    Document::factory()->purchaseInvoice()->create(['status' => 'received']);

    Livewire::test('incident-bell')
        ->call('checkIncidents')
        ->assertSee('No active notifications');
});
