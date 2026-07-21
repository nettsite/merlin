<?php

use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the standalone purchasing settings page with the searchable account selects', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('access-panel');
    $this->actingAs($user);

    $response = $this->get(route('settings.purchasing'));

    $response->assertOk();
    $response->assertSee('Purchasing Settings');
    $response->assertSee('Default Payment Contra Account');
    $response->assertSee('Search…');
});
