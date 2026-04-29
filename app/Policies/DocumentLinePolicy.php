<?php

namespace App\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Purchasing\Models\DocumentLine;

class DocumentLinePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('document-lines-view-any');
    }

    public function view(User $user, DocumentLine $documentLine): bool
    {
        return $user->can('document-lines-view');
    }

    public function create(User $user): bool
    {
        return $user->can('document-lines-create');
    }

    public function update(User $user, DocumentLine $documentLine): bool
    {
        return $user->can('document-lines-update');
    }

    public function delete(User $user, DocumentLine $documentLine): bool
    {
        return $user->can('document-lines-delete');
    }

    public function restore(User $user, DocumentLine $documentLine): bool
    {
        return $user->can('document-lines-restore');
    }

    public function forceDelete(User $user, DocumentLine $documentLine): bool
    {
        return $user->can('document-lines-force-delete');
    }
}
