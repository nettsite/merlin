<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('party.types', [
            'Organisation',
            'Individual',
        ]);
        $this->migrator->add('party.countries', [
            'ZA'=>'South Africa',
        ]);
    }

    public function down(): void
    {
        $this->migrator->delete('party.types');
        $this->migrator->delete('party.countries');
    }
};
