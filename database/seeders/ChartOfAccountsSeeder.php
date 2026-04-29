<?php

namespace Database\Seeders;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountGroup;
use App\Modules\Accounting\Models\AccountType;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        // Account Types (fixed reference, no timestamps)
        $types = [
            ['code' => '1', 'name' => 'Asset', 'normal_balance' => 'debit', 'sort_order' => 10],
            ['code' => '2', 'name' => 'Liability', 'normal_balance' => 'credit', 'sort_order' => 20],
            ['code' => '3', 'name' => 'Equity', 'normal_balance' => 'credit', 'sort_order' => 30],
            ['code' => '4', 'name' => 'Income', 'normal_balance' => 'credit', 'sort_order' => 40],
            ['code' => '5', 'name' => 'Expense', 'normal_balance' => 'debit', 'sort_order' => 50],
        ];

        foreach ($types as $type) {
            AccountType::updateOrCreate(['code' => $type['code']], $type);
        }

        $asset = AccountType::where('code', '1')->first();
        $liability = AccountType::where('code', '2')->first();
        $equity = AccountType::where('code', '3')->first();
        $income = AccountType::where('code', '4')->first();
        $expense = AccountType::where('code', '5')->first();

        // Account Groups
        $groups = [
            ['type' => $asset, 'code' => '10', 'name' => 'Current Assets', 'sort_order' => 10],
            ['type' => $asset, 'code' => '15', 'name' => 'Fixed Assets', 'sort_order' => 20],
            ['type' => $liability, 'code' => '20', 'name' => 'Current Liabilities', 'sort_order' => 10],
            ['type' => $equity, 'code' => '30', 'name' => "Owner's Equity", 'sort_order' => 10],
            ['type' => $income, 'code' => '40', 'name' => 'Revenue', 'sort_order' => 10],
            ['type' => $expense, 'code' => '50', 'name' => 'Cost of Sales', 'sort_order' => 10],
            ['type' => $expense, 'code' => '51', 'name' => 'Communication', 'sort_order' => 20],
            ['type' => $expense, 'code' => '52', 'name' => 'Technology', 'sort_order' => 30],
            ['type' => $expense, 'code' => '53', 'name' => 'Office & Admin', 'sort_order' => 40],
            ['type' => $expense, 'code' => '54', 'name' => 'Sales & Marketing', 'sort_order' => 50],
            ['type' => $expense, 'code' => '55', 'name' => 'Professional Services', 'sort_order' => 60],
            ['type' => $expense, 'code' => '56', 'name' => 'Insurance', 'sort_order' => 70],
            ['type' => $expense, 'code' => '57', 'name' => 'Facilities', 'sort_order' => 80],
            ['type' => $expense, 'code' => '58', 'name' => 'Travel & Entertainment', 'sort_order' => 90],
            ['type' => $expense, 'code' => '59', 'name' => 'Finance Charges', 'sort_order' => 100],
            ['type' => $expense, 'code' => '599', 'name' => 'Miscellaneous', 'sort_order' => 110],
        ];

        foreach ($groups as $g) {
            AccountGroup::updateOrCreate(
                ['code' => $g['code']],
                [
                    'account_type_id' => $g['type']->id,
                    'code' => $g['code'],
                    'name' => $g['name'],
                    'sort_order' => $g['sort_order'],
                ],
            );
        }

        // Helper closure to fetch group by code
        $group = fn (string $code) => AccountGroup::where('code', $code)->firstOrFail();

        // Accounts
        $accounts = [
            // Assets
            ['group' => '10', 'code' => '1000', 'name' => 'Bank — Operating Account'],
            ['group' => '10', 'code' => '1010', 'name' => 'Bank — Savings Account'],
            ['group' => '10', 'code' => '1100', 'name' => 'Accounts Receivable'],
            ['group' => '10', 'code' => '1200', 'name' => 'GST Receivable'],
            ['group' => '15', 'code' => '1500', 'name' => 'Office Equipment'],
            ['group' => '15', 'code' => '1510', 'name' => 'Computer Equipment'],

            // Liabilities
            ['group' => '20', 'code' => '2000', 'name' => 'Accounts Payable'],
            ['group' => '20', 'code' => '2100', 'name' => 'GST Payable'],
            ['group' => '20', 'code' => '2200', 'name' => 'Income Tax Payable'],
            ['group' => '20', 'code' => '2300', 'name' => 'Credit Card — Operating'],

            // Equity
            ['group' => '30', 'code' => '3000', 'name' => "Owner's Equity"],
            ['group' => '30', 'code' => '3100', 'name' => 'Retained Earnings'],
            ['group' => '30', 'code' => '3200', 'name' => 'Current Year Earnings'],

            // Income
            ['group' => '40', 'code' => '4000', 'name' => 'Sales Revenue'],
            ['group' => '40', 'code' => '4100', 'name' => 'Service Revenue'],
            ['group' => '40', 'code' => '4900', 'name' => 'Other Income'],

            // Expenses
            ['group' => '50', 'code' => '5000', 'name' => 'Cost of Goods Sold'],
            ['group' => '51', 'code' => '5100', 'name' => 'Telephone & Internet'],
            ['group' => '51', 'code' => '5110', 'name' => 'Mobile Phones'],
            ['group' => '52', 'code' => '5200', 'name' => 'Software Subscriptions'],
            ['group' => '52', 'code' => '5210', 'name' => 'Cloud Hosting'],
            ['group' => '52', 'code' => '5220', 'name' => 'Domain Names & SSL'],
            ['group' => '53', 'code' => '5300', 'name' => 'Office Supplies'],
            ['group' => '54', 'code' => '5400', 'name' => 'Advertising & Marketing'],
            ['group' => '55', 'code' => '5500', 'name' => 'Professional Services'],
            ['group' => '55', 'code' => '5510', 'name' => 'Accounting & Bookkeeping'],
            ['group' => '55', 'code' => '5520', 'name' => 'Legal Fees'],
            ['group' => '56', 'code' => '5600', 'name' => 'Insurance'],
            ['group' => '57', 'code' => '5700', 'name' => 'Rent & Occupancy'],
            ['group' => '58', 'code' => '5800', 'name' => 'Travel & Entertainment'],
            ['group' => '59', 'code' => '5900', 'name' => 'Bank Charges'],
            ['group' => '59', 'code' => '5910', 'name' => 'Merchant Fees'],
            ['group' => '599', 'code' => '5999', 'name' => 'Miscellaneous Expenses'],
        ];

        foreach ($accounts as $a) {
            Account::updateOrCreate(
                ['code' => $a['code']],
                [
                    'account_group_id' => $group($a['group'])->id,
                    'code' => $a['code'],
                    'name' => $a['name'],
                    'is_system' => true,
                ],
            );
        }
    }
}
