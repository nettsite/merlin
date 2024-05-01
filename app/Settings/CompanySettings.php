<?php

namespace App\Settings;

use Spatie\LaravelSettings\Settings;

class CompanySettings extends Settings
{
    public string|null $name;
    public string|null $email;

    public static function group(): string
    {
        return 'company';
    }
}