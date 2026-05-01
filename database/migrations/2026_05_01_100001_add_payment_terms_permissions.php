<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'payment-terms-view-any',
            'payment-terms-view',
            'payment-terms-create',
            'payment-terms-update',
            'payment-terms-delete',
            'payment-terms-restore',
            'payment-terms-force-delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $admin = Role::where('name', 'Administrator')->first();

        if ($admin) {
            $admin->givePermissionTo($permissions);
        }
    }

    public function down(): void
    {
        //
    }
};
