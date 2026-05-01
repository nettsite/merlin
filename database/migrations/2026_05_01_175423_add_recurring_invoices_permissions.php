<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'recurring-invoices-view-any',
            'recurring-invoices-view',
            'recurring-invoices-create',
            'recurring-invoices-update',
            'recurring-invoices-delete',
            'recurring-invoices-restore',
            'recurring-invoices-force-delete',
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
