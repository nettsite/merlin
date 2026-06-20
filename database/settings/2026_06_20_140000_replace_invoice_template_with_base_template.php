<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->delete('billing.invoice_email_template_id');
        $this->migrator->delete('billing.reminder_offsets');
        $this->migrator->add('billing.base_email_template_id', null);
    }
};
