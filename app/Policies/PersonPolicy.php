<?php

namespace App\Policies;

use App\Modules\Core\Models\Person;
use App\Modules\Core\Models\User;

class PersonPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('persons-view-any');
    }

    public function view(User $user, Person $person): bool
    {
        return $user->can('persons-view');
    }

    public function create(User $user): bool
    {
        return $user->can('persons-create');
    }

    public function update(User $user, Person $person): bool
    {
        return $user->can('persons-update');
    }

    public function delete(User $user, Person $person): bool
    {
        return $user->can('persons-delete');
    }
}
