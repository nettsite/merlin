<?php

namespace App\Modules\Billing\Services;

use App\Modules\Core\Models\Person;
use Illuminate\Support\Facades\Password;

class PortalInviteService
{
    /**
     * Generate a set-password URL for the given Person.
     *
     * The token is stored in portal_password_reset_tokens (72-hour expiry).
     * The URL is included in the invoice email; the Person clicks it to activate
     * portal access and set their password.
     */
    public function generateInviteUrl(Person $person): string
    {
        $token = Password::broker('portal')->createToken($person);

        return route('portal.set-password', [
            'token' => $token,
            'email' => $person->email,
        ]);
    }
}
