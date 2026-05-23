<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountGroup;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Billing\Models\PaymentTerm;
use App\Modules\Billing\Models\RecurringInvoice;
use App\Modules\Core\Models\Address;
use App\Modules\Core\Models\Business;
use App\Modules\Core\Models\ContactAssignment;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\PartyRelationship;
use App\Modules\Core\Models\Person;
use App\Modules\Core\Models\User;
use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Models\DocumentActivity;
use App\Modules\Purchasing\Models\DocumentLine;
use App\Modules\Purchasing\Models\DocumentRelationship;
use App\Modules\Purchasing\Models\LlmLog;
use App\Modules\Purchasing\Models\PostingRule;
use App\Policies\AccountGroupPolicy;
use App\Policies\AccountPolicy;
use App\Policies\AccountTypePolicy;
use App\Policies\AddressPolicy;
use App\Policies\BusinessPolicy;
use App\Policies\ContactAssignmentPolicy;
use App\Policies\DocumentActivityPolicy;
use App\Policies\DocumentLinePolicy;
use App\Policies\DocumentPolicy;
use App\Policies\DocumentRelationshipPolicy;
use App\Policies\LlmLogPolicy;
use App\Policies\PartyPolicy;
use App\Policies\PartyRelationshipPolicy;
use App\Policies\PaymentTermPolicy;
use App\Policies\PersonPolicy;
use App\Policies\PostingRulePolicy;
use App\Policies\RecurringInvoicePolicy;
use App\Policies\UserPolicy;

/**
 * Map each policy to:
 *  - permission prefix (e.g. "documents" → uses documents-view-any etc.)
 *  - model class for instance-taking methods.
 *
 * Tests assert each policy method:
 *  - returns true when user has the matching permission
 *  - returns false when user does not
 *
 * This catches: typos in permission strings, renamed permissions,
 * policy method that forgot to check anything, or a method silently
 * checking the wrong permission.
 */
dataset('policies', [
    'AccountGroupPolicy' => [AccountGroupPolicy::class, 'account-groups', AccountGroup::class],
    'AccountPolicy' => [AccountPolicy::class, 'accounts', Account::class],
    'AccountTypePolicy' => [AccountTypePolicy::class, 'account-types', AccountType::class],
    'AddressPolicy' => [AddressPolicy::class, 'addresses', Address::class],
    'BusinessPolicy' => [BusinessPolicy::class, 'businesses', Business::class],
    'ContactAssignmentPolicy' => [ContactAssignmentPolicy::class, 'contact-assignments', ContactAssignment::class],
    'DocumentActivityPolicy' => [DocumentActivityPolicy::class, 'document-activities', DocumentActivity::class],
    'DocumentLinePolicy' => [DocumentLinePolicy::class, 'document-lines', DocumentLine::class],
    'DocumentPolicy' => [DocumentPolicy::class, 'documents', Document::class],
    'DocumentRelationshipPolicy' => [DocumentRelationshipPolicy::class, 'document-relationships', DocumentRelationship::class],
    'LlmLogPolicy' => [LlmLogPolicy::class, 'llm-logs', LlmLog::class],
    'PartyPolicy' => [PartyPolicy::class, 'parties', Party::class],
    'PartyRelationshipPolicy' => [PartyRelationshipPolicy::class, 'party-relationships', PartyRelationship::class],
    'PaymentTermPolicy' => [PaymentTermPolicy::class, 'payment-terms', PaymentTerm::class],
    'PersonPolicy' => [PersonPolicy::class, 'persons', Person::class],
    'PostingRulePolicy' => [PostingRulePolicy::class, 'posting-rules', PostingRule::class],
    'RecurringInvoicePolicy' => [RecurringInvoicePolicy::class, 'recurring-invoices', RecurringInvoice::class],
    'UserPolicy' => [UserPolicy::class, 'users', User::class],
]);

function makePermission(string $name): void
{
    \Spatie\Permission\Models\Permission::findOrCreate($name, 'web');
}

function userWith(string ...$permissions): User
{
    $user = User::factory()->create();
    foreach ($permissions as $perm) {
        makePermission($perm);
        $user->givePermissionTo($perm);
    }

    return $user;
}

it('allows viewAny for users with the matching permission', function (string $policyClass, string $prefix): void {
    $policy = new $policyClass;
    $perm = "{$prefix}-view-any";

    expect($policy->viewAny(userWith($perm)))->toBeTrue();
    expect($policy->viewAny(User::factory()->create()))->toBeFalse();
})->with('policies');

it('allows create for users with the matching permission', function (string $policyClass, string $prefix): void {
    $policy = new $policyClass;

    if (! method_exists($policy, 'create')) {
        $this->markTestSkipped("{$policyClass}::create not defined");
    }

    $perm = "{$prefix}-create";

    expect($policy->create(userWith($perm)))->toBeTrue();
    expect($policy->create(User::factory()->create()))->toBeFalse();
})->with('policies');

it('checks instance-taking methods against matching permissions', function (string $policyClass, string $prefix, string $modelClass): void {
    $policy = new $policyClass;
    // Unpersisted model is enough — policy never inspects it.
    $instance = new $modelClass;

    foreach (['view', 'update', 'delete', 'restore', 'forceDelete'] as $method) {
        if (! method_exists($policy, $method)) {
            continue;
        }

        // Permission name: camelCase method → kebab. forceDelete → force-delete.
        $action = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $method));
        $perm = "{$prefix}-{$action}";

        $allowed = userWith($perm);
        $denied = User::factory()->create();

        expect($policy->{$method}($allowed, $instance))
            ->toBeTrue("{$policyClass}::{$method} should allow user with '{$perm}'");
        expect($policy->{$method}($denied, $instance))
            ->toBeFalse("{$policyClass}::{$method} should deny user without '{$perm}'");
    }
})->with('policies');
