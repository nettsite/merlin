<?php

namespace App\Policies;

use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;

class PartyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('parties-view-any');
    }

    public function view(User $user, Party $party): bool
    {
        return $user->can('parties-view');
    }

    public function create(User $user): bool
    {
        return $user->can('parties-create');
    }

    public function update(User $user, Party $party): bool
    {
        return $user->can('parties-update');
    }

    public function delete(User $user, Party $party): bool
    {
        return $user->can('parties-delete');
    }

    public function restore(User $user, Party $party): bool
    {
        return $user->can('parties-restore');
    }

    public function forceDelete(User $user, Party $party): bool
    {
        return $user->can('parties-force-delete');
    }
}
