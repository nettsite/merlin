<?php

namespace App\Policies;

use App\Modules\Core\Models\DocumentRelationship;
use App\Modules\Core\Models\User;

class DocumentRelationshipPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('document-relationships-view-any');
    }

    public function view(User $user, DocumentRelationship $documentRelationship): bool
    {
        return $user->can('document-relationships-view');
    }

    public function create(User $user): bool
    {
        return $user->can('document-relationships-create');
    }

    public function update(User $user, DocumentRelationship $documentRelationship): bool
    {
        return $user->can('document-relationships-update');
    }

    public function delete(User $user, DocumentRelationship $documentRelationship): bool
    {
        return $user->can('document-relationships-delete');
    }
}
