<?php

namespace App\Support;

use Carbon\Carbon;

class DueDate
{
    public static function due_on_presentation(Carbon $date): string
    {
        return $date->format('Y-m-d');
    }

    public static function first_of_month(Carbon $date, string $frequency='M'): string
    {
        switch($frequency) {
            case 'M':
                return $date->firstOfMonth()->addMonth()->format('Y-m-d');
            case 'Y':
                return $date->firstOfMonth()->addYear()->addMonth()->format('Y-m-d');
            default:
                return $date->format('Y-m-d');
        }
    }

}
