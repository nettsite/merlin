<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $permissions = [
            'posting-rules-view-any',
            'posting-rules-view',
            'posting-rules-create',
            'posting-rules-update',
            'posting-rules-delete',
            'posting-rules-restore',
            'posting-rules-force-delete',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $admin = Role::where('name', 'Administrator')->first();
        $accountant = Role::where('name', 'accountant')->first();

        if ($admin) {
            $admin->givePermissionTo($permissions);
        }

        if ($accountant) {
            $accountant->givePermissionTo($permissions);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
