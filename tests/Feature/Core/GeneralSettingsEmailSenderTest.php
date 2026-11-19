<?php

use App\Modules\Core\Models\User;
use App\Modules\Core\Settings\CompanySettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('access-panel');
    $this->actingAs($user);
});

it('saves the outgoing email sender from General settings', function (): void {
    Livewire::test('pages.settings.index')
        ->set('companyName', 'NettSite')
        ->set('emailFromName', 'NettSite Support')
        ->set('emailFromAddress', 'support@nettsite.co.za')
        ->set('emailReplyToAddress', 'help@nettsite.co.za')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved', true);

    $company = app(CompanySettings::class);

    expect($company->email_from_name)->toBe('NettSite Support')
        ->and($company->email_from_address)->toBe('support@nettsite.co.za')
        ->and($company->email_reply_to_address)->toBe('help@nettsite.co.za');
});

it('loads the saved sender into the form', function (): void {
    $company = app(CompanySettings::class);
    $company->email_from_name = 'NettSite';
    $company->email_from_address = 'support@nettsite.co.za';
    $company->email_reply_to_address = 'support@nettsite.co.za';
    $company->save();

    Livewire::test('pages.settings.index')
        ->assertSet('emailFromName', 'NettSite')
        ->assertSet('emailFromAddress', 'support@nettsite.co.za')
        ->assertSet('emailReplyToAddress', 'support@nettsite.co.za');
});

it('rejects invalid sender addresses', function (): void {
    Livewire::test('pages.settings.index')
        ->set('companyName', 'NettSite')
        ->set('emailFromName', '')
        ->set('emailFromAddress', 'not-an-email')
        ->set('emailReplyToAddress', 'also bad')
        ->call('save')
        ->assertHasErrors(['emailFromName', 'emailFromAddress', 'emailReplyToAddress']);
});

it('shows the Outgoing Email section on the General settings URL', function (): void {
    $this->followingRedirects()
        ->get(route('settings.general'))
        ->assertOk()
        ->assertSee('Outgoing Email')
        ->assertSee('Reply-To Address');
});

it('requires permission to open the settings page', function (): void {
    $this->actingAs(User::factory()->create());

    Livewire::test('pages.settings.index')->assertForbidden();
});
