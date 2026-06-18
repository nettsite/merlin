<?php

use App\Modules\Billing\Services\PortalInviteService;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\Person;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
use App\Modules\Purchasing\Models\Document;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Livewire\Volt\Volt;

// --- Helpers ---

function portalPerson(string $email = 'portal@example.com'): Person
{
    $party = app(PartyService::class)->createPerson([
        'first_name' => 'Portal',
        'last_name' => 'User',
        'email' => $email,
        'status' => 'active',
    ]);

    return $party->person;
}

function clientPartyForPortal(): Party
{
    return app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Portal Client Ltd',
        'status' => 'active',
    ], ['client']);
}

function portalInvoice(Party $client): Document
{
    return Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'draft',
        'party_id' => $client->id,
        'issue_date' => '2026-01-15',
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'source' => 'manual',
    ]);
}

// --- Person authenticatable ---

it('null-password person cannot log in', function (): void {
    portalPerson('nopass@example.com');

    Volt::test('pages.portal.auth.login')
        ->set('email', 'nopass@example.com')
        ->set('password', 'anything')
        ->call('login')
        ->assertHasErrors('email');

    expect(Auth::guard('portal')->check())->toBeFalse();
});

it('person with password can log in via portal guard', function (): void {
    $person = portalPerson('login@example.com');
    $person->forceFill(['password' => Hash::make('Secret1234!')])->save();

    Volt::test('pages.portal.auth.login')
        ->set('email', 'login@example.com')
        ->set('password', 'Secret1234!')
        ->call('login')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(Auth::guard('portal')->check())->toBeTrue();
    expect(Auth::guard('web')->check())->toBeFalse();
});

it('portal guard is isolated from web guard', function (): void {
    $staffUser = User::factory()->create();
    $this->actingAs($staffUser, 'web');

    expect(Auth::guard('web')->check())->toBeTrue();
    expect(Auth::guard('portal')->check())->toBeFalse();
});

it('portal-authenticated person is not authenticated on web guard', function (): void {
    $person = portalPerson('portalonly@example.com');
    $person->forceFill(['password' => Hash::make('Secret1234!')])->save();

    $this->actingAs($person, 'portal');

    expect(Auth::guard('portal')->check())->toBeTrue();
    expect(Auth::guard('web')->check())->toBeFalse();
});

// --- Invite / set-password flow ---

it('person can set password from a valid invite link', function (): void {
    $person = portalPerson('invite@example.com');

    $url = app(PortalInviteService::class)->generateInviteUrl($person);

    preg_match('/set-password\/([^?]+)/', $url, $matches);
    $token = $matches[1];

    Volt::test('pages.portal.auth.set-password', ['token' => $token])
        ->set('email', 'invite@example.com')
        ->set('password', 'NewPassword1!')
        ->set('password_confirmation', 'NewPassword1!')
        ->call('setPassword')
        ->assertHasNoErrors()
        ->assertRedirect();

    $person->refresh();
    expect(Hash::check('NewPassword1!', $person->password))->toBeTrue();
    expect(Auth::guard('portal')->check())->toBeTrue();
});

it('person cannot set password from an expired invite token', function (): void {
    $person = portalPerson('expired@example.com');

    $token = Password::broker('portal')->createToken($person);
    Password::broker('portal')->deleteToken($person);

    Volt::test('pages.portal.auth.set-password', ['token' => $token])
        ->set('email', 'expired@example.com')
        ->set('password', 'NewPassword1!')
        ->set('password_confirmation', 'NewPassword1!')
        ->call('setPassword')
        ->assertHasErrors('email');

    $person->refresh();
    expect($person->password)->toBeNull();
});

// --- portal.view-invoice gate ---

it('person with active receives-invoices assignment can view invoice', function (): void {
    $client = clientPartyForPortal();
    $person = portalPerson('canview@example.com');
    $client->assignContact($person, ['role' => 'billing', 'receives_invoices' => true, 'is_active' => true]);

    $invoice = portalInvoice($client);

    expect($person->can('portal.view-invoice', $invoice))->toBeTrue();
});

it('person without assignment cannot view invoice', function (): void {
    $client = clientPartyForPortal();
    $person = portalPerson('cantview@example.com');

    $invoice = portalInvoice($client);

    expect($person->can('portal.view-invoice', $invoice))->toBeFalse();
});

it('person with inactive assignment cannot view invoice', function (): void {
    $client = clientPartyForPortal();
    $person = portalPerson('inactive@example.com');
    $client->assignContact($person, ['role' => 'billing', 'receives_invoices' => true, 'is_active' => false]);

    $invoice = portalInvoice($client);

    expect($person->can('portal.view-invoice', $invoice))->toBeFalse();
});

it('portal login page is accessible', function (): void {
    $this->get('/portal/login')->assertOk();
});
