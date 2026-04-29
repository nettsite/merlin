<?php

namespace App\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Purchasing\Models\DocumentActivity;

class DocumentActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('document-activities-view-any');
    }

    public function view(User $user, DocumentActivity $documentActivity): bool
    {
        return $user->can('document-activities-view');
    }

    public function create(User $user): bool
    {
        return $user->can('document-activities-create');
    }

    public function update(User $user, DocumentActivity $documentActivity): bool
    {
        return $user->can('document-activities-update');
    }

    public function delete(User $user, DocumentActivity $documentActivity): bool
    {
        return $user->can('document-activities-delete');
    }
}
