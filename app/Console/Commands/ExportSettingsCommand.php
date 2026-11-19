<?php

namespace App\Console\Commands;

use App\Modules\Core\Services\SettingsTransferService;
use Illuminate\Console\Command;

class ExportSettingsCommand extends Command
{
    protected $signature = 'settings:export {path : JSON file to write}';

    protected $description = 'Export settings, email templates and the logo to a JSON file for settings:import on another instance';

    public function handle(SettingsTransferService $transfer): int
    {
        $path = $this->argument('path');
        $export = $transfer->export();

        $this->info('Exporting from database '.config('database.connections.'.config('database.default').'.database').'...');

        foreach ($export['settings'] as $group => $values) {
            $this->line("  {$group}: ".count($values).' settings');
        }

        $this->line('  NettMail templates: '.count($export['nettmail_templates']));
        $this->line('  Billing email templates: '.count($export['billing_email_templates']));

        file_put_contents($path, json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        chmod($path, 0600);

        $this->info("Written to {$path}. It contains your banking details; delete it after importing.");

        return self::SUCCESS;
    }
}
