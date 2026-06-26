<?php

namespace App\Policies;

use App\Modules\Core\Models\LlmLog;
use App\Modules\Core\Models\User;

class LlmLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('llm-logs-view-any');
    }

    public function view(User $user, LlmLog $llmLog): bool
    {
        return $user->can('llm-logs-view');
    }

    public function create(User $user): bool
    {
        return $user->can('llm-logs-create');
    }

    public function update(User $user, LlmLog $llmLog): bool
    {
        return $user->can('llm-logs-update');
    }

    public function delete(User $user, LlmLog $llmLog): bool
    {
        return $user->can('llm-logs-delete');
    }
}
