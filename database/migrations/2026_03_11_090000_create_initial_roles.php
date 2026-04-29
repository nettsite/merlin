<?php

use App\Modules\Core\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $role = Role::firstOrCreate(['name' => 'Administrator', 'guard_name' => 'web']);

        $user = User::withoutEvents(function () {
            return User::firstOrCreate(
                ['email' => 'william@nettsite.co.za'],
                [
                    'name' => 'Will Nettmann',
                    'password' => Hash::make('lmf393jq'),
                    'email_verified_at' => now(),
                ],
            );
        });

        $user->assignRole($role);
    }
};
