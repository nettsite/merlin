<?php

namespace App\Policies;

use App\Modules\Core\Models\Business;
use App\Modules\Core\Models\User;

class BusinessPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('businesses-view-any');
    }

    public function view(User $user, Business $business): bool
    {
        return $user->can('businesses-view');
    }

    public function create(User $user): bool
    {
        return $user->can('businesses-create');
    }

    public function update(User $user, Business $business): bool
    {
        return $user->can('businesses-update');
    }

    public function delete(User $user, Business $business): bool
    {
        return $user->can('businesses-delete');
    }
}
