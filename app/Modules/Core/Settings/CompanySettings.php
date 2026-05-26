<?php

namespace App\Modules\Core\Settings;

use Spatie\LaravelSettings\Settings;

class CompanySettings extends Settings
{
    public string $name = '';

    public string $address_line_1 = '';

    public string $address_line_2 = '';

    public string $city = '';

    public string $state_province = '';

    public string $postal_code = '';

    public string $country = '';

    public string $phone = '';

    public string $email = '';

    public string $tax_number = '';

    public ?string $logo_path = null;

    public static function group(): string
    {
        return 'company';
    }
}
