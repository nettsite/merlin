<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Enums\RecurringFrequency;
use App\Modules\Billing\Enums\RecurringInvoiceStatus;
use App\Modules\Billing\Models\RecurringInvoice;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\Person;
use App\Modules\Core\Models\User;
use App\Modules\Purchasing\Models\Document;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RecurringInvoiceService
{
    public function __construct(
        private readonly BillingService $billingService,
        private readonly ProRataCalculator $proRataCalculator,
        private readonly BillingSettings $billingSettings,
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

        // next_invoice_date is the first full billing period date on or after start
        $nextInvoiceDate = $this->resolveFirstFullPeriodDate($startDate, $billingPeriodDay);

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
            'status' => RecurringInvoiceStatus::Active,
            'currency' => $data['currency'] ?? $this->billingSettings->default_receivable_account_id ? 'ZAR' : 'ZAR',
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

        // Generate pro-rated first invoice if start is mid-period
        if (! $this->proRataCalculator->isFullPeriod($startDate, $billingPeriodDay)) {
            $this->generateFromTemplate($template->fresh('lines'), true, $by);
        }

        return $template->fresh('lines');
    }

    /**
     * Generate a sales invoice from the template.
     */
    public function generateFromTemplate(RecurringInvoice $template, bool $isProRata = false, ?User $by = null): Document
    {
        $template->loadMissing(['client.business', 'lines.account', 'paymentTerm']);

        $billingPeriodDay = $template->billing_period_day ?? $this->billingSettings->billing_period_day;

        $proRataData = null;
        if ($isProRata) {
            $proRataData = $this->proRataCalculator->calculate($template->start_date, $billingPeriodDay);
        }

        $issueDate = $isProRata ? $template->start_date : $template->next_invoice_date;

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

        $recipientEmails = $this->resolveTemplateRecipientEmails($template);

        try {
            $this->billingService->sendInvoice($doc->fresh(), $by, $recipientEmails);
        } catch (RuntimeException $e) {
            Log::warning("RecurringInvoice [{$template->id}] send failed: {$e->getMessage()}");
        }

        return $doc->fresh();
    }

    /**
     * Advance the template's next_invoice_date by one frequency period.
     */
    public function advanceNextDate(RecurringInvoice $template): void
    {
        $next = match ($template->frequency) {
            RecurringFrequency::Monthly => $template->next_invoice_date->addMonthNoOverflow(),
            RecurringFrequency::Quarterly => $template->next_invoice_date->addMonthsNoOverflow(3),
            RecurringFrequency::Annually => $template->next_invoice_date->addYearNoOverflow(),
        };

        $template->update(['next_invoice_date' => $next->toDateString()]);
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

        // Next occurrence of billingPeriodDay after startDate
        $candidate = $startDate->copy()->startOfMonth()->addDays($billingPeriodDay - 1);

        if ($candidate->lt($startDate)) {
            $candidate->addMonthNoOverflow();
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
