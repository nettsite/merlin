<?php

namespace App\Console\Commands;

use App\Modules\Core\Services\SettingsTransferService;
use Illuminate\Console\Command;

class ImportSettingsCommand extends Command
{
    protected $signature = 'settings:import
        {path : JSON file written by settings:export}
        {--dry-run : Show what would change without writing}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Import settings, email templates and the logo from a settings:export file (overwrites existing values)';

    public function handle(SettingsTransferService $transfer): int
    {
        $path = $this->argument('path');

        if (! is_readable($path)) {
            $this->error("Cannot read {$path}.");

            return self::FAILURE;
        }

        $payload = json_decode((string) file_get_contents($path), true);

        if (! is_array($payload)) {
            $this->error("{$path} is not valid JSON.");

            return self::FAILURE;
        }

        $database = config('database.connections.'.config('database.default').'.database');
        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! $this->option('force') && ! $this->confirm("Overwrite settings and email templates in database \"{$database}\"?")) {
            $this->comment('Cancelled.');

            return self::SUCCESS;
        }

        $report = $transfer->import($payload, $dryRun);

        $this->info(($dryRun ? '[DRY RUN] Would apply' : 'Applied').' to '.$database.':');

        foreach ($report['applied'] as $item) {
            $this->line("  {$item}");
        }

        foreach ($report['skipped'] as $item) {
            $this->warn("  Skipped {$item}");
        }

        $this->comment(count($report['applied']).' applied, '.count($report['skipped']).' skipped.');

        return self::SUCCESS;
    }
}
