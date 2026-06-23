<?php

namespace App\Modules\Billing\Console;

use App\Mail\SalesInvoiceMail;
use App\Modules\Billing\Models\BillingEmailTemplate;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Billing\Services\InvoiceEmailTemplateService;
use App\Modules\Billing\Services\WorkingDayCalculator;
use App\Modules\Core\Models\Document;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class SendReminders extends Command
{
    protected $signature = 'billing:send-reminders {--dry-run : Preview without sending}';

    protected $description = 'Send invoice reminder emails based on enabled reminder templates.';

    public function handle(
        WorkingDayCalculator $workingDays,
        InvoiceEmailTemplateService $templateService,
        BillingService $billingService,
    ): int {
        $isDryRun = (bool) $this->option('dry-run');
        $today = Carbon::today()->toDateString();

        $reminderTemplates = BillingEmailTemplate::reminder()->enabled()->orderBy('offset_days')->get();

        if ($reminderTemplates->isEmpty()) {
            $this->comment('No enabled reminder templates configured.');

            return self::SUCCESS;
        }

        $candidates = Document::salesInvoices()
            ->whereIn('status', ['sent', 'partially_paid'])
            ->where('balance_due', '>', 0)
            ->whereNotNull('due_date')
            ->with('party.business')
            ->get();

        if ($candidates->isEmpty()) {
            $this->comment('No open invoices with due dates.');

            return self::SUCCESS;
        }

        $sent = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($reminderTemplates as $reminder) {
            $offset = $reminder->offset_days;

            $matched = $candidates->filter(
                fn (Document $doc) => $workingDays->addBusinessDays($doc->due_date, $offset)->toDateString() === $today,
            );

            if ($matched->isEmpty()) {
                continue;
            }

            $label = $offset < 0 ? abs($offset).' business days before due' : $offset.' business days after due';
            $this->line("{$reminder->name} (offset {$offset} — {$label}): {$matched->count()} invoice(s)");

            foreach ($matched as $invoice) {
                $clientName = $invoice->party?->business?->display_name ?? $invoice->party_id;
                $this->line("  {$invoice->document_number} — {$clientName}");

                if ($isDryRun) {
                    continue;
                }

                try {
                    $rendered = $templateService->render($invoice, $reminder);
                    $emails = array_column($billingService->resolveRecipients($invoice), 'email');

                    if (empty($emails)) {
                        $this->warn('    No recipients — skipped.');
                        Log::warning("billing:send-reminders — no recipients for {$invoice->document_number}");
                        $skipped++;

                        continue;
                    }

                    foreach ($emails as $email) {
                        Mail::mailer('nettmail')->to($email)->send(
                            new SalesInvoiceMail($invoice, $rendered['subject'], $rendered['html']),
                        );
                    }

                    $this->info('    Sent to: '.implode(', ', $emails));
                    Log::info("billing:send-reminders — sent {$invoice->document_number} ({$reminder->name}) to ".implode(', ', $emails));
                    $sent++;
                } catch (RuntimeException $e) {
                    $this->error("Template error: {$e->getMessage()}");
                    Log::error("billing:send-reminders — template error: {$e->getMessage()}");

                    return self::FAILURE;
                } catch (Throwable $e) {
                    $this->error("    Failed for {$invoice->document_number}: {$e->getMessage()}");
                    Log::error("billing:send-reminders — failed for {$invoice->document_number}: {$e->getMessage()}");
                    $errors++;
                }
            }
        }

        if (! $isDryRun) {
            $this->comment("Done. Sent: {$sent}, Skipped (no recipients): {$skipped}, Errors: {$errors}.");
        }

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
