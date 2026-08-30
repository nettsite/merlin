<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Floors the auto-merge threshold at the lowest confidence a real
        // reference match can produce (PaymentNotificationMatcher::
        // DEFINITIVE_CONFIDENCE) — below 0.90 only a name-resemblance or
        // same-day-only guess is reachable, and neither should auto-apply.
        // Only raises an existing value; never lowers one an operator
        // deliberately raised above the old 0.80 default.
        $this->migrator->update('purchasing.payment_match_auto_confidence', fn ($value) => max((float) $value, 0.90));
    }
};
