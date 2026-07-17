<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('blocks a parent account from allowing direct posting once it gains a child', function (): void {
    $parent = Account::factory()->create(['allow_direct_posting' => true]);

    Account::factory()->create(['parent_id' => $parent->id]);

    expect($parent->fresh()->allow_direct_posting)->toBeFalse();
});

it('blocks a parent account from allowing direct posting when a child is reparented onto it', function (): void {
    $parent = Account::factory()->create(['allow_direct_posting' => true]);
    $child = Account::factory()->create();

    $child->update(['parent_id' => $parent->id]);

    expect($parent->fresh()->allow_direct_posting)->toBeFalse();
});

it('rejects setting allow_direct_posting=true on an account that already has children', function (): void {
    $parent = Account::factory()->create(['allow_direct_posting' => false]);
    Account::factory()->create(['parent_id' => $parent->id]);

    expect(fn () => $parent->update(['allow_direct_posting' => true]))
        ->toThrow(InvalidArgumentException::class);
});

it('allows creating a brand new account with allow_direct_posting=true (it has no children yet)', function (): void {
    $account = Account::factory()->create(['allow_direct_posting' => true]);

    expect($account->allow_direct_posting)->toBeTrue();
});

it('does not touch allow_direct_posting on accounts with no children', function (): void {
    $account = Account::factory()->create(['allow_direct_posting' => true]);

    $account->update(['name' => 'Renamed']);

    expect($account->fresh()->allow_direct_posting)->toBeTrue();
});

it('the accounts CRUD form surfaces the rejection as a field validation error', function (): void {
    $user = User::factory()->create();
    Permission::findOrCreate('accounts-view-any', 'web');
    Permission::findOrCreate('accounts-update', 'web');
    $user->givePermissionTo(['accounts-view-any', 'accounts-update']);
    $this->actingAs($user);

    $parent = Account::factory()->create(['allow_direct_posting' => false]);
    Account::factory()->create(['parent_id' => $parent->id]);

    Livewire\Livewire::test('pages.accounts.index')
        ->call('edit', $parent->id)
        ->set('allowDirectPosting', true)
        ->call('save')
        ->assertHasErrors(['allowDirectPosting']);

    expect($parent->fresh()->allow_direct_posting)->toBeFalse();
});

// --- Edit form rendering ---
//
// Livewire's wire:model on a native <select> relies on client-side JS to set
// the correct option after hydration — the server-rendered HTML has no
// `selected` attribute to fall back on unless one is added explicitly. When
// that client-side sync doesn't fire (e.g. opening edit() for a different
// record reuses the same modal DOM), the browser defaults to the FIRST
// <option> in markup order, which is always "Yes"/"Active" — regardless of
// the real value. These assert the initial server-rendered HTML is correct
// on its own, independent of client-side hydration.

it('renders "No" as the selected option when editing an account with allow_direct_posting=false', function (): void {
    $user = User::factory()->create();
    Permission::findOrCreate('accounts-view-any', 'web');
    Permission::findOrCreate('accounts-update', 'web');
    $user->givePermissionTo(['accounts-view-any', 'accounts-update']);
    $this->actingAs($user);

    $account = Account::factory()->create(['allow_direct_posting' => false]);

    $html = Livewire\Livewire::test('pages.accounts.index')
        ->call('edit', $account->id)
        ->html();

    expect($html)->toContain('0" selected>No')
        ->and($html)->not->toContain('1" selected>Yes')
        // Belt-and-suspenders: the `selected` HTML attribute alone doesn't
        // reliably move a live <select>'s displayed value once the element
        // exists in the DOM (confirmed live: attribute renders correctly,
        // display doesn't follow) — x-init must force the .value property.
        ->and($html)->toContain("x-init=\"\$el.value = '0'\"");
});

it('renders "Inactive" as the selected option when editing an inactive account', function (): void {
    $user = User::factory()->create();
    Permission::findOrCreate('accounts-view-any', 'web');
    Permission::findOrCreate('accounts-update', 'web');
    $user->givePermissionTo(['accounts-view-any', 'accounts-update']);
    $this->actingAs($user);

    $account = Account::factory()->create(['is_active' => false]);

    $html = Livewire\Livewire::test('pages.accounts.index')
        ->call('edit', $account->id)
        ->html();

    // Both selects render the same markup shape ("selected" appears twice for
    // the correct "No"/"Inactive" pairing), so scope the assertion narrowly
    // to the Status select's known-false option rather than a bare substring.
    expect($html)->toContain('0" selected>Inactive');
});

it('keys the status/posting selects by the record being edited, so switching records forces a fresh element', function (): void {
    $user = User::factory()->create();
    Permission::findOrCreate('accounts-view-any', 'web');
    Permission::findOrCreate('accounts-update', 'web');
    $user->givePermissionTo(['accounts-view-any', 'accounts-update']);
    $this->actingAs($user);

    $accountA = Account::factory()->create();
    $accountB = Account::factory()->create();

    $component = Livewire\Livewire::test('pages.accounts.index');

    $htmlA = $component->call('edit', $accountA->id)->html();
    expect($htmlA)->toContain("allow-direct-posting-{$accountA->id}")
        ->toContain("is-active-{$accountA->id}");

    $htmlB = $component->call('edit', $accountB->id)->html();
    expect($htmlB)->toContain("allow-direct-posting-{$accountB->id}")
        ->toContain("is-active-{$accountB->id}")
        ->not->toContain("allow-direct-posting-{$accountA->id}");
});
