<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->rename('billing.default_bank_account_id', 'billing.default_contra_account_id');
    }
};
