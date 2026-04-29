<?php

namespace App\Policies;

use App\Modules\Accounting\Models\AccountGroup;
use App\Modules\Core\Models\User;

class AccountGroupPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('account-groups-view-any');
    }

    public function view(User $user, AccountGroup $accountGroup): bool
    {
        return $user->can('account-groups-view');
    }

    public function create(User $user): bool
    {
        return $user->can('account-groups-create');
    }

    public function update(User $user, AccountGroup $accountGroup): bool
    {
        return $user->can('account-groups-update');
    }

    public function delete(User $user, AccountGroup $accountGroup): bool
    {
        return $user->can('account-groups-delete');
    }

    public function restore(User $user, AccountGroup $accountGroup): bool
    {
        return $user->can('account-groups-restore');
    }

    public function forceDelete(User $user, AccountGroup $accountGroup): bool
    {
        return $user->can('account-groups-force-delete');
    }
}
