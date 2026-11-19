<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('company.website', '');
        $this->migrator->add('billing.default_invoice_terms', '');
        $this->migrator->add('billing.default_invoice_footer', '');
    }
};
