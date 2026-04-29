<?php

namespace App\Policies;

use App\Modules\Core\Models\PartyRelationship;
use App\Modules\Core\Models\User;

class PartyRelationshipPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('party-relationships-view-any');
    }

    public function view(User $user, PartyRelationship $partyRelationship): bool
    {
        return $user->can('party-relationships-view');
    }

    public function create(User $user): bool
    {
        return $user->can('party-relationships-create');
    }

    public function update(User $user, PartyRelationship $partyRelationship): bool
    {
        return $user->can('party-relationships-update');
    }

    public function delete(User $user, PartyRelationship $partyRelationship): bool
    {
        return $user->can('party-relationships-delete');
    }

    public function restore(User $user, PartyRelationship $partyRelationship): bool
    {
        return $user->can('party-relationships-restore');
    }

    public function forceDelete(User $user, PartyRelationship $partyRelationship): bool
    {
        return $user->can('party-relationships-force-delete');
    }
}
