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

    public string $website = '';

    public string $tax_number = '';

    public ?string $logo_path = null;

    public string $email_from_name = '';

    public string $email_from_address = '';

    public string $email_reply_to_address = '';

    public string $bank_name = '';

    public string $bank_account_name = '';

    public string $bank_account_number = '';

    public string $bank_branch_code = '';

    public static function group(): string
    {
        return 'company';
    }
}
