<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\RecurringFrequency;
use App\Modules\Billing\Enums\RecurringInvoiceStatus;
use App\Modules\Billing\Models\RecurringInvoice;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\Person;
use App\Modules\Core\Models\User;
use App\Modules\Core\Settings\CurrencySettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RecurringInvoiceService
{
    public function __construct(
        private readonly BillingService $billingService,
        private readonly ProRataCalculator $proRataCalculator,
        private readonly WorkingDayCalculator $workingDays,
        private readonly BillingSettings $billingSettings,
        private readonly CurrencySettings $currencySettings,
    ) {}

    /**
     * Create a recurring invoice template.
     * If the start_date does not fall on the billing period day, a pro-rated
     * first invoice is generated immediately.
     *
     * @param  array<string, mixed>  $data
     */
    public function createTemplate(array $data, ?User $by = null): RecurringInvoice
    {
        $billingPeriodDay = (int) ($data['billing_period_day'] ?? $this->billingSettings->billing_period_day);
        $startDate = Carbon::parse($data['start_date']);

        $frequency = RecurringFrequency::from($data['frequency']);

        // Derive anchor (nominal period date) and run date (working-day adjusted).
        if ($frequency->isDayOfMonthBased()) {
            $anchor = $this->resolveFirstFullPeriodDate($startDate, $billingPeriodDay);
        } else {
            // Weekly/fortnightly: anchor is the start date; no day-of-month concept.
            $anchor = $startDate->copy();
        }

        $nextInvoiceDate = $this->workingDays->latestWorkingDayOnOrBefore($anchor);

        $template = RecurringInvoice::create([
            'client_id' => $data['client_id'],
            'payment_term_id' => $data['payment_term_id'] ?? null,
            'receivable_account_id' => $data['receivable_account_id'] ?? null,
            'contact_ids' => $data['contact_ids'] ?? null,
            'frequency' => $data['frequency'],
            'billing_period_day' => $billingPeriodDay,
            'start_date' => $startDate->toDateString(),
            'end_date' => isset($data['end_date']) ? Carbon::parse($data['end_date'])->toDateString() : null,
            'next_invoice_date' => $nextInvoiceDate->toDateString(),
            'next_period_anchor' => $anchor->toDateString(),
            'status' => RecurringInvoiceStatus::Active,
            'currency' => strtoupper($data['currency'] ?? $this->currencySettings->base_currency),
            'auto_send' => $data['auto_send'] ?? true,
            'notes' => $data['notes'] ?? null,
            'terms' => $data['terms'] ?? null,
            'footer' => $data['footer'] ?? null,
        ]);

        foreach ($data['lines'] ?? [] as $i => $line) {
            $template->lines()->create([
                'line_number' => $i + 1,
                'description' => $line['description'],
                'account_id' => $line['account_id'] ?: null,
                'quantity' => $line['quantity'] ?? 1,
                'unit_price' => $line['unit_price'] ?? 0,
                'discount_percent' => $line['discount_percent'] ?? 0,
                'tax_rate' => $line['tax_rate'] ?? null,
                'notes' => $line['notes'] ?? null,
            ]);
        }

        // Generate pro-rated first invoice if start is mid-period.
        // Weekly/fortnightly have no day-of-month concept so pro-rata never applies.
        if ($frequency->isDayOfMonthBased() && ! $this->proRataCalculator->isFullPeriod($startDate, $billingPeriodDay)) {
            $this->generateFromTemplate($template->fresh('lines'), true, $by);
        }

        return $template->fresh('lines');
    }

    /**
     * Generate a sales invoice from the template.
     *
     * Invoice creation and next_invoice_date advancement commit atomically so
     * a crash can never leave the template due again with an invoice already
     * created (which would double-generate and double-email on the next run).
     * The email is sent after commit; a send failure leaves the invoice
     * created and the schedule advanced.
     */
    public function generateFromTemplate(RecurringInvoice $template, bool $isProRata = false, ?User $by = null): Document
    {
        $template->loadMissing(['client.business', 'lines.account', 'paymentTerm']);

        // Idempotency guard: if an invoice for this template and period
        // already exists, return it instead of generating a duplicate.
        if (! $isProRata) {
            $existing = Document::salesInvoices()
                ->where('metadata->recurring_invoice_id', $template->id)
                ->whereDate('issue_date', $template->next_invoice_date)
                ->first();

            if ($existing !== null) {
                Log::warning("RecurringInvoice [{$template->id}] invoice for {$template->next_invoice_date->toDateString()} already exists ({$existing->document_number}); skipping generation.");

                // Self-heal: advance past the already-generated period so the
                // template doesn't stay due forever.
                $this->advanceNextDate($template);

                return $existing;
            }
        }

        $billingPeriodDay = $template->billing_period_day ?? $this->billingSettings->billing_period_day;

        $proRataData = null;
        if ($isProRata) {
            $proRataData = $this->proRataCalculator->calculate($template->start_date, $billingPeriodDay);
        }

        $issueDate = $isProRata ? $template->start_date : $template->next_invoice_date;

        $doc = DB::transaction(function () use ($template, $isProRata, $proRataData, $issueDate): Document {
            $doc = $this->billingService->createDraft($template->client, [
                'issue_date' => $issueDate->toDateString(),
                'payment_term_id' => $template->payment_term_id,
                'notes' => $template->notes,
            ]);

            // Link document back to this template via metadata
            $doc->update(['metadata' => array_merge($doc->metadata ?? [], [
                'recurring_invoice_id' => $template->id,
            ])]);

            foreach ($template->lines as $i => $line) {
                $quantity = $isProRata
                    ? round((float) $line->quantity * $proRataData['factor'], 4)
                    : (float) $line->quantity;

                $description = $isProRata
                    ? $line->description.' (pro rata '.round($proRataData['factor'] * 100, 1).'%)'
                    : $line->description;

                $doc->lines()->create([
                    'line_number' => $i + 1,
                    'type' => 'service',
                    'description' => $description,
                    'account_id' => $line->account_id,
                    'quantity' => $quantity,
                    'unit_price' => (float) $line->unit_price,
                    'discount_percent' => (float) $line->discount_percent,
                    'tax_rate' => $line->tax_rate !== null ? (float) $line->tax_rate : null,
                ]);
            }

            if (! $isProRata) {
                $this->advanceNextDate($template);
            }

            return $doc;
        });

        if (! $template->auto_send) {
            return $doc->fresh();
        }

        $recipientEmails = $this->resolveTemplateRecipientEmails($template);

        try {
            $this->billingService->sendInvoice($doc->fresh(), $by, $recipientEmails);
        } catch (RuntimeException $e) {
            Log::warning("RecurringInvoice [{$template->id}] send failed: {$e->getMessage()}");
        }

        return $doc->fresh();
    }

    /**
     * Advance the template's next_period_anchor by one frequency period, then
     * derive the working-day-adjusted run date. Advancing from the anchor (not
     * the run date) prevents the schedule from drifting backward over time.
     */
    public function advanceNextDate(RecurringInvoice $template): void
    {
        // Fall back to next_invoice_date for rows backfilled before Phase B.
        $currentAnchor = $template->next_period_anchor ?? $template->next_invoice_date;

        $nextAnchor = match ($template->frequency) {
            RecurringFrequency::Weekly => $currentAnchor->addWeek(),
            RecurringFrequency::Fortnightly => $currentAnchor->addWeeks(2),
            RecurringFrequency::Monthly => $currentAnchor->addMonthNoOverflow(),
            RecurringFrequency::Quarterly => $currentAnchor->addMonthsNoOverflow(3),
            RecurringFrequency::Annually => $currentAnchor->addYearNoOverflow(),
        };

        $template->update([
            'next_period_anchor' => $nextAnchor->toDateString(),
            'next_invoice_date' => $this->workingDays->latestWorkingDayOnOrBefore($nextAnchor)->toDateString(),
        ]);
    }

    /**
     * Mark template completed if its end_date is in the past.
     */
    public function completeIfExpired(RecurringInvoice $template): void
    {
        if ($template->end_date !== null && $template->end_date->isPast()) {
            $template->update(['status' => RecurringInvoiceStatus::Completed]);
        }
    }

    /**
     * Resolve the first full billing period date on or after startDate.
     */
    private function resolveFirstFullPeriodDate(Carbon $startDate, int $billingPeriodDay): Carbon
    {
        if ($startDate->day === $billingPeriodDay) {
            return $startDate->copy();
        }

        // Next occurrence of billingPeriodDay after startDate, clamped to the
        // month's length (day 31 in February must yield Feb 28, not Mar 2/3).
        $candidate = $startDate->copy()->startOfMonth();
        $candidate->day(min($billingPeriodDay, $candidate->daysInMonth));

        if ($candidate->lt($startDate)) {
            $candidate->startOfMonth()->addMonthNoOverflow();
            $candidate->day(min($billingPeriodDay, $candidate->daysInMonth));
        }

        return $candidate;
    }

    /**
     * @return string[]
     */
    private function resolveTemplateRecipientEmails(RecurringInvoice $template): array
    {
        if (! empty($template->contact_ids)) {
            return Person::whereIn('id', $template->contact_ids)
                ->whereNotNull('email')
                ->pluck('email')
                ->all();
        }

        return [];
    }
}
