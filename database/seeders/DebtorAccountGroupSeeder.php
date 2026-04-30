<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\AccountGroup;
use App\Modules\Accounting\Models\AccountType;
use Illuminate\Database\Seeder;

class DebtorAccountGroupSeeder extends Seeder
{
    public function run(): void
    {
        $asset = AccountType::where('code', '1')->firstOrFail();

        AccountGroup::updateOrCreate(
            ['code' => '11'],
            [
                'account_type_id' => $asset->id,
                'code' => '11',
                'name' => 'Debtors',
                'sort_order' => 15,
            ]
        );
    }
}
