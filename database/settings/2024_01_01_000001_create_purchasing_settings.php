<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('purchasing.default_payable_account', '2000');
        $this->migrator->add('purchasing.tax_default_rate', 15.00);
        $this->migrator->add('purchasing.tax_label', 'VAT');
        $this->migrator->add('purchasing.autopost_confidence', 0.90);
        $this->migrator->add('purchasing.amount_tolerance', 10.0);
        $this->migrator->add('purchasing.description_similarity', 60.0);
    }
};
