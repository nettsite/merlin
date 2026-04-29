<?php

namespace App\Policies;

use App\Modules\Core\Models\User;
use App\Modules\Purchasing\Models\PostingRule;

class PostingRulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('posting-rules-view-any');
    }

    public function view(User $user, PostingRule $postingRule): bool
    {
        return $user->can('posting-rules-view');
    }

    public function create(User $user): bool
    {
        return $user->can('posting-rules-create');
    }

    public function update(User $user, PostingRule $postingRule): bool
    {
        return $user->can('posting-rules-update');
    }

    public function delete(User $user, PostingRule $postingRule): bool
    {
        return $user->can('posting-rules-delete');
    }

    public function restore(User $user, PostingRule $postingRule): bool
    {
        return $user->can('posting-rules-restore');
    }

    public function forceDelete(User $user, PostingRule $postingRule): bool
    {
        return $user->can('posting-rules-force-delete');
    }
}
