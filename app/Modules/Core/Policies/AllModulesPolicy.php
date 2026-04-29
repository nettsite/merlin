<?php

namespace App\Modules\Core\Policies;

use App\Modules\Core\Contracts\ModulePolicy;

/**
 * FOSS implementation — all modules are always available.
 *
 * The SaaS override (TenantModulePolicy) lives in nettsite/merlin-saas
 * and is bound in MerlinSaaSServiceProvider, replacing this binding.
 */
class AllModulesPolicy implements ModulePolicy
{
    public function allows(string $module): bool
    {
        return true;
    }
}
