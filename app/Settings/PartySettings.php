<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class PartySettings extends Settings
{
    public array $types;
    public array $countries;

    public static function group(): string
    {
        return 'party';
    }
}