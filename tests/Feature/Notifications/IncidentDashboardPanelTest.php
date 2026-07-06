<?php

use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function panelUser(): User
{
    $user = User::factory()->create();
    Permission::findOrCreate('documents-view-any', 'web');
    $user->givePermissionTo('documents-view-any');

    return $user;
}

it('renders an active unposted-invoices incident on the dashboard', function (): void {
    $this->actingAs(panelUser());
    Document::factory()->purchaseInvoice()->create(['status' => 'reviewed']);

    Livewire::test('incident-dashboard-panel')
        ->assertSee('Unposted invoices')
        ->assertSee('1 purchase invoice');
});

it('renders nothing when there are no active incidents', function (): void {
    $this->actingAs(panelUser());

    Livewire::test('incident-dashboard-panel')
        ->assertDontSee('Unposted invoices');
});

it('renders nothing for a user without documents-view-any permission', function (): void {
    $this->actingAs(User::factory()->create());
    Document::factory()->purchaseInvoice()->create(['status' => 'approved']);

    Livewire::test('incident-dashboard-panel')
        ->assertDontSee('Unposted invoices');
});
