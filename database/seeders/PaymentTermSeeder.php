<?php

namespace Database\Seeders;

use App\Modules\Billing\Enums\PaymentTermRule;
use App\Modules\Billing\Models\PaymentTerm;
use Illuminate\Database\Seeder;

class PaymentTermSeeder extends Seeder
{
    public function run(): void
    {
        $terms = [
            [
                'name' => 'Immediate',
                'rule' => PaymentTermRule::SameAsInvoiceDate,
                'days' => null,
                'day_of_month' => null,
            ],
            [
                'name' => '30 Days',
                'rule' => PaymentTermRule::DaysAfterInvoice,
                'days' => 30,
                'day_of_month' => null,
            ],
            [
                'name' => '60 Days',
                'rule' => PaymentTermRule::DaysAfterInvoice,
                'days' => 60,
                'day_of_month' => null,
            ],
            [
                'name' => 'EOM',
                'rule' => PaymentTermRule::FirstBusinessDayOfFollowingMonth,
                'days' => null,
                'day_of_month' => null,
            ],
            [
                'name' => '25th of Month',
                'rule' => PaymentTermRule::NthOfFollowingMonth,
                'days' => null,
                'day_of_month' => 25,
            ],
        ];

        foreach ($terms as $term) {
            PaymentTerm::updateOrCreate(
                ['name' => $term['name']],
                $term,
            );
        }
    }
}
