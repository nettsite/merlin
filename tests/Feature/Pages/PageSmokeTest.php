<?php

use App\Modules\Core\Models\User;
use Livewire\Volt\Volt;
use Spatie\Permission\Models\Permission;

/**
 * Page smoke tests — assert each Volt page renders without 500s for a user
 * who has the right permission, and is forbidden for one who does not.
 *
 * These don't test business logic — that's done in dedicated tests. They
 * catch regressions where a page boots, queries, or renders incorrectly
 * (missing imports, broken Blade, accidental N+1 explosion crash, etc.).
 */
function permittedUser(string ...$permissions): User
{
    $user = User::factory()->create();
    foreach ($permissions as $perm) {
        Permission::findOrCreate($perm, 'web');
        $user->givePermissionTo($perm);
    }

    return $user;
}

// --- accounts ---

it('accounts page renders for permitted user', function (): void {
    $this->actingAs(permittedUser('accounts-view-any'));

    Volt::test('pages.accounts.index')->assertOk();
});

it('accounts page denies user without permission', function (): void {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.accounts.index')->assertForbidden();
});

// --- account-groups ---

it('account-groups page renders for permitted user', function (): void {
    $this->actingAs(permittedUser('account-groups-view-any'));

    Volt::test('pages.account-groups.index')->assertOk();
});

it('account-groups page denies user without permission', function (): void {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.account-groups.index')->assertForbidden();
});

// --- llm-logs ---

it('llm-logs page renders for permitted user', function (): void {
    $this->actingAs(permittedUser('llm-logs-view-any'));

    Volt::test('pages.llm-logs.index')->assertOk();
});

it('llm-logs page denies user without permission', function (): void {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.llm-logs.index')->assertForbidden();
});

// --- posting-rules ---

it('posting-rules page renders for permitted user', function (): void {
    $this->actingAs(permittedUser('posting-rules-view-any'));

    Volt::test('pages.posting-rules.index')->assertOk();
});

it('posting-rules page denies user without permission', function (): void {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.posting-rules.index')->assertForbidden();
});

// --- roles ---

it('roles page renders for permitted user', function (): void {
    // Roles page is authorized via UserPolicy::viewAny ('users-view-any')
    $this->actingAs(permittedUser('users-view-any'));

    Volt::test('pages.roles.index')->assertOk();
});

it('roles page denies user without permission', function (): void {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.roles.index')->assertForbidden();
});

// --- users ---

it('users page renders for permitted user', function (): void {
    $this->actingAs(permittedUser('users-view-any'));

    Volt::test('pages.users.index')->assertOk();
});

it('users page denies user without permission', function (): void {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.users.index')->assertForbidden();
});

// --- reports/* ---

it('expenses-by-account report renders', function (): void {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.reports.expenses-by-account')->assertOk();
});

it('expenses-by-supplier report renders', function (): void {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.reports.expenses-by-supplier')->assertOk();
});

it('llm-performance report renders for permitted user', function (): void {
    $this->actingAs(permittedUser('view-llm-summary'));

    Volt::test('pages.reports.llm-performance')->assertOk();
});

it('llm-performance report denies user without permission', function (): void {
    $this->actingAs(User::factory()->create());

    Volt::test('pages.reports.llm-performance')->assertForbidden();
});
