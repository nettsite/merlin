<?php

namespace Database\Seeders;

use App\Modules\Core\Models\User;
use Illuminate\Database\Seeder;

class DefaultAdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name' => 'Administrator',
                'password' => 'changeme',
            ]
        );

        $user->assignRole('Administrator');
    }
}
