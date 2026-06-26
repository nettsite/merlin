<?php

namespace App\Console\Commands;

use App\Modules\Core\Services\ModelHealthService;
use Illuminate\Console\Command;

class CheckModelHealthCommand extends Command
{
    protected $signature = 'models:health-check';

    protected $description = 'Probe every configured Anthropic model and email if any is unavailable';

    public function handle(ModelHealthService $health): int
    {
        $failed = false;

        foreach ($health->ladder() as $model) {
            $this->info("Probing {$model}...");
            $error = $health->probe($model);

            if ($error === null) {
                // Healthy now — clear any stale "down" mark so a future failure re-alerts.
                $health->clearDown($model);
                $this->info('  ok');

                continue;
            }

            $failed = true;
            // Marks down + emails once (cache-guarded against repeat alerts).
            $health->recordUnavailable($model, $error);
            $this->error("  UNAVAILABLE: {$error}");
        }

        if ($failed) {
            $this->error('One or more models are unavailable; an alert has been emailed.');

            return self::FAILURE;
        }

        $this->info('All configured models are healthy.');

        return self::SUCCESS;
    }
}
