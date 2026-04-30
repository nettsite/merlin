<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);
        $this->call(ChartOfAccountsSeeder::class);
        $this->call(DefaultAdminUserSeeder::class);
        $this->call(DebtorAccountGroupSeeder::class);
        $this->call(PaymentTermSeeder::class);
    }
}
