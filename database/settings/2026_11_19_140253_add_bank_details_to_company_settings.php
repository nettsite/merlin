<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('company.bank_name', '');
        $this->migrator->add('company.bank_account_name', '');
        $this->migrator->add('company.bank_account_number', '');
        $this->migrator->add('company.bank_branch_code', '');
    }
};
