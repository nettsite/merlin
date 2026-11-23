<?php

namespace Database\Seeders;

use App\Modules\Core\Models\User;
use Illuminate\Database\Seeder;

class DefaultAdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('email', 'admin@example.com')->exists()) {
            return;
        }

        $user = User::create([
            'name' => 'Administrator',
            'email' => 'admin@example.com',
            'password' => 'changeme',
        ]);

        $user->assignRole('Administrator');
    }
}
