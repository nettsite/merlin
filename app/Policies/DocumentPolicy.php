<?php

namespace App\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Purchasing\Models\Document;

class DocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('documents-view-any');
    }

    public function view(User $user, Document $document): bool
    {
        return $user->can('documents-view');
    }

    public function create(User $user): bool
    {
        return $user->can('documents-create');
    }

    public function update(User $user, Document $document): bool
    {
        return $user->can('documents-update');
    }

    public function delete(User $user, Document $document): bool
    {
        return $user->can('documents-delete');
    }

    public function restore(User $user, Document $document): bool
    {
        return $user->can('documents-restore');
    }

    public function forceDelete(User $user, Document $document): bool
    {
        return $user->can('documents-force-delete');
    }
}
