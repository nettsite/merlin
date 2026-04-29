<?php

namespace App\Policies;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\User;

class AccountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('accounts-view-any');
    }

    public function view(User $user, Account $account): bool
    {
        return $user->can('accounts-view');
    }

    public function create(User $user): bool
    {
        return $user->can('accounts-create');
    }

    public function update(User $user, Account $account): bool
    {
        return $user->can('accounts-update');
    }

    public function delete(User $user, Account $account): bool
    {
        return $user->can('accounts-delete');
    }

    public function restore(User $user, Account $account): bool
    {
        return $user->can('accounts-restore');
    }

    public function forceDelete(User $user, Account $account): bool
    {
        return $user->can('accounts-force-delete');
    }
}
