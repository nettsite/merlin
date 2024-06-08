<?php

namespace App\Support;

use Spatie\Multitenancy\Models\Tenant;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;

class SwitchTenantStorageTask implements SwitchTenantTask
{
    protected string $originalPath;

    public function __construct()
    {
        $this->originalPath = $_ENV['LARAVEL_STORAGE_PATH'] ?? 'storage';
    }
    public function makeCurrent(Tenant $tenant): void
    {
        $_ENV['LARAVEL_STORAGE_PATH'] = storage_path('merlin/' . $tenant->id);
    }

    public function forgetCurrent(): void
    {
        $_ENV['LARAVEL_STORAGE_PATH'] = $this->originalPath;
    }
}
