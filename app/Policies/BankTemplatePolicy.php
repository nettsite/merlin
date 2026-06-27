<?php

namespace App\Policies;

use App\Modules\Core\Models\BankTemplate;
use App\Modules\Core\Models\User;

class BankTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('bank-templates-view-any');
    }

    public function view(User $user, BankTemplate $bankTemplate): bool
    {
        return $user->can('bank-templates-view');
    }

    public function create(User $user): bool
    {
        return $user->can('bank-templates-create');
    }

    public function update(User $user, BankTemplate $bankTemplate): bool
    {
        return $user->can('bank-templates-update');
    }

    public function delete(User $user, BankTemplate $bankTemplate): bool
    {
        return $user->can('bank-templates-delete');
    }

    public function restore(User $user, BankTemplate $bankTemplate): bool
    {
        return $user->can('bank-templates-restore');
    }

    public function forceDelete(User $user, BankTemplate $bankTemplate): bool
    {
        return $user->can('bank-templates-force-delete');
    }
}
