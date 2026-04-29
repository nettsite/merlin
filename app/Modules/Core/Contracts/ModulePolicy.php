<?php

namespace App\Modules\Core\Contracts;

interface ModulePolicy
{
    public function allows(string $module): bool;
}
