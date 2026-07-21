<?php

use Illuminate\Support\Facades\DB;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Default to whatever bank account is already configured for sales
        // payments (billing.default_contra_account_id), if any — the admin
        // can repoint it (e.g. to Drawings, for payments made from a
        // personal card) once the new setting exists.
        $payload = DB::table('settings')
            ->where('group', 'billing')
            ->where('name', 'default_contra_account_id')
            ->value('payload');

        $default = $payload !== null ? json_decode($payload) : null;

        $this->migrator->add('purchasing.default_payment_contra_account_id', $default);
    }
};
