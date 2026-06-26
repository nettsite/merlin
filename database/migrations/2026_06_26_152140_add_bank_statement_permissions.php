<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $permissions = [
            'bank-statements-view-any',
            'bank-statements-view',
            'bank-statements-create',
            'bank-statements-update',
            'bank-statements-delete',
            'bank-templates-view-any',
            'bank-templates-view',
            'bank-templates-create',
            'bank-templates-update',
            'bank-templates-delete',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }
};
