<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('currency.base_currency', 'ZAR');
        $this->migrator->add('currency.locale', 'en_ZA');
    }
};
