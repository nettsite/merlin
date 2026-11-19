<?php

use App\Console\Commands\CheckModelHealthCommand;
use App\Console\Commands\VerifyAuditTrailCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(CheckModelHealthCommand::class)->dailyAt('05:30')->withoutOverlapping();

Schedule::command(VerifyAuditTrailCommand::class)->dailyAt('05:35')->withoutOverlapping();
