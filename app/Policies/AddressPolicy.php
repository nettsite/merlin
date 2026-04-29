<?php

namespace App\Policies;

use App\Modules\Core\Models\Address;
use App\Modules\Core\Models\User;

class AddressPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('addresses-view-any');
    }

    public function view(User $user, Address $address): bool
    {
        return $user->can('addresses-view');
    }

    public function create(User $user): bool
    {
        return $user->can('addresses-create');
    }

    public function update(User $user, Address $address): bool
    {
        return $user->can('addresses-update');
    }

    public function delete(User $user, Address $address): bool
    {
        return $user->can('addresses-delete');
    }

    public function restore(User $user, Address $address): bool
    {
        return $user->can('addresses-restore');
    }

    public function forceDelete(User $user, Address $address): bool
    {
        return $user->can('addresses-force-delete');
    }
}
