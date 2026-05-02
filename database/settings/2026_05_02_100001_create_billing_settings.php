<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('billing.billing_period_day', 1);
        $this->migrator->add('billing.default_receivable_account_id', null);
        $this->migrator->add('billing.default_bank_account_id', null);
        $this->migrator->add('billing.default_payment_term_id', null);
        $this->migrator->add('billing.tax_liability_account_id', null);
    }
};
