<?php

namespace App\Policies;

use App\Modules\Core\Models\ContactAssignment;
use App\Modules\Core\Models\User;

class ContactAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('contact-assignments-view-any');
    }

    public function view(User $user, ContactAssignment $contactAssignment): bool
    {
        return $user->can('contact-assignments-view');
    }

    public function create(User $user): bool
    {
        return $user->can('contact-assignments-create');
    }

    public function update(User $user, ContactAssignment $contactAssignment): bool
    {
        return $user->can('contact-assignments-update');
    }

    public function delete(User $user, ContactAssignment $contactAssignment): bool
    {
        return $user->can('contact-assignments-delete');
    }

    public function restore(User $user, ContactAssignment $contactAssignment): bool
    {
        return $user->can('contact-assignments-restore');
    }

    public function forceDelete(User $user, ContactAssignment $contactAssignment): bool
    {
        return $user->can('contact-assignments-force-delete');
    }
}
