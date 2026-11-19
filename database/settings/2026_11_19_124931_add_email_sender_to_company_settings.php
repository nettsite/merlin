<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('company.email_from_name', (string) config('mail.mailers.nettmail.from.name'));
        $this->migrator->add('company.email_from_address', (string) config('mail.mailers.nettmail.from.address'));
        $this->migrator->add('company.email_reply_to_address', (string) config('mail.mailers.nettmail.reply_to.address'));
    }
};
