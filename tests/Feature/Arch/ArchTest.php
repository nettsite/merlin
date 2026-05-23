<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Business;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\Person;
use App\Modules\Core\Models\User;
use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Models\DocumentLine;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Project hard-rule (CLAUDE.md):
 * "Never use $user->hasRole() anywhere — not in controllers, policies,
 *  middleware, Filament resources, model methods, or tests."
 *
 * Roles are user-configurable at runtime; permissions are the stable contract.
 * Catches reintroduction of hasRole() in app/ before merge.
 */
arch('no hasRole() calls in app code')
    ->expect('App')
    ->not->toUse('hasRole');

/**
 * All concrete Policy classes live in App\Policies. Confirm they expose at least
 * one of the standard authorization methods. A policy class with none of these
 * is dead code or a typo.
 */
test('every Policy class defines at least one standard policy method', function (): void {
    $policyDir = app_path('Policies');
    $files = glob($policyDir.'/*Policy.php');
    expect($files)->not->toBeEmpty();

    $methods = ['viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete'];

    foreach ($files as $file) {
        $class = 'App\\Policies\\'.basename($file, '.php');
        expect(class_exists($class))->toBeTrue("class {$class} should exist");

        $defined = array_filter($methods, fn ($m) => method_exists($class, $m));
        expect($defined)->not->toBeEmpty("{$class} defines no standard policy methods");
    }
});

/**
 * Morph-map enforcement (CLAUDE.md project rule).
 *
 * Stored *_type strings must be short aliases, never FQCNs. If any model that
 * appears in polymorphic relations is missing from enforceMorphMap, namespace
 * refactors silently break activity_log, permissions, media, etc.
 *
 * We assert specific known-morph models are registered. Add new ones here
 * when introducing them.
 */
test('all polymorphic models are registered in the morph map', function (): void {
    $expected = [
        User::class,
        Party::class,
        Person::class,
        Business::class,
        Document::class,
        DocumentLine::class,
        Account::class,
    ];

    $map = Relation::morphMap();
    $registered = array_values($map);

    foreach ($expected as $class) {
        expect(in_array($class, $registered, true))
            ->toBeTrue("{$class} missing from Relation::enforceMorphMap()");
    }
});

/**
 * Catches a tempting-but-wrong import: Spatie\Activitylog\LogOptions does not
 * exist (the real namespace is Spatie\Activitylog\Support\LogOptions). The bad
 * import compiles silently until activity log is invoked.
 */
arch('LogOptions imports use the correct Support namespace')
    ->expect('Spatie\Activitylog\LogOptions')
    ->not->toBeUsed();
