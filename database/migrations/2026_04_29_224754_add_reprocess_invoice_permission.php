<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Permission::firstOrCreate(['name' => 'can-reprocess-invoices', 'guard_name' => 'web']);

        $admin = Role::where('name', 'Administrator')->first();
        $accountant = Role::where('name', 'accountant')->first();

        if ($admin) {
            $admin->givePermissionTo('can-reprocess-invoices');
        }

        if ($accountant) {
            $accountant->givePermissionTo('can-reprocess-invoices');
        }
    }
};
