<?php

namespace App\Policies;

use App\Modules\Accounting\Models\AccountType;
use App\Modules\Core\Models\User;

class AccountTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('account-types-view-any');
    }

    public function view(User $user, AccountType $accountType): bool
    {
        return $user->can('account-types-view');
    }

    public function create(User $user): bool
    {
        return $user->can('account-types-create');
    }

    public function update(User $user, AccountType $accountType): bool
    {
        return $user->can('account-types-update');
    }

    public function delete(User $user, AccountType $accountType): bool
    {
        return $user->can('account-types-delete');
    }
}
