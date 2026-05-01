<?php

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Models\RecurringInvoice;
use App\Modules\Billing\Services\RecurringInvoiceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateRecurringInvoices extends Command
{
    protected $signature = 'billing:generate-recurring {--dry-run : Preview without generating}';

    protected $description = 'Generate invoices for all active recurring invoice templates that are due.';

    public function handle(RecurringInvoiceService $service): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        $due = RecurringInvoice::active()->due()->with(['client.business', 'lines'])->get();

        if ($due->isEmpty()) {
            $this->comment('No recurring invoices due.');

            return self::SUCCESS;
        }

        $this->info("Found {$due->count()} template(s) due.".($isDryRun ? ' [DRY RUN]' : ''));

        $generated = 0;
        $errors = 0;

        foreach ($due as $template) {
            $clientName = $template->client?->business?->display_name ?? $template->client_id;

            $this->line("  Processing: {$clientName} [{$template->frequency->label()}] next: {$template->next_invoice_date->toDateString()}");

            if ($isDryRun) {
                continue;
            }

            try {
                $doc = $service->generateFromTemplate($template);
                $service->advanceNextDate($template);
                $service->completeIfExpired($template->fresh());

                $this->info("    Generated: {$doc->document_number}");
                Log::info("billing:generate-recurring — generated {$doc->document_number} for template {$template->id}");
                $generated++;
            } catch (Throwable $e) {
                $this->error("    Failed: {$e->getMessage()}");
                Log::error("billing:generate-recurring — failed for template {$template->id}: {$e->getMessage()}");
                $errors++;
            }
        }

        if (! $isDryRun) {
            $this->comment("Done. Generated: {$generated}, Errors: {$errors}.");
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
