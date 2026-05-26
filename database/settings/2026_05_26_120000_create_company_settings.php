<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('company.name', '');
        $this->migrator->add('company.address_line_1', '');
        $this->migrator->add('company.address_line_2', '');
        $this->migrator->add('company.city', '');
        $this->migrator->add('company.state_province', '');
        $this->migrator->add('company.postal_code', '');
        $this->migrator->add('company.country', '');
        $this->migrator->add('company.phone', '');
        $this->migrator->add('company.email', '');
        $this->migrator->add('company.tax_number', '');
        $this->migrator->add('company.logo_path', null);
    }
};
