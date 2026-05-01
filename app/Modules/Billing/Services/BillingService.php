<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\PaymentTerm;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\Models\Document;
use Carbon\Carbon;

class BillingService
{
    public function __construct(
        private readonly BillingSettings $billingSettings,
        private readonly CurrencySettings $currencySettings,
        private readonly DueDateCalculator $dueDateCalculator,
    ) {}

    /**
     * Create a draft sales invoice for the given client.
     *
     * @param  array<string, mixed>  $data  Accepted keys: issue_date, payment_term_id, notes, reference
     */
    public function createDraft(Party $client, array $data): Document
    {
        $issueDate = isset($data['issue_date'])
            ? Carbon::parse($data['issue_date'])
            : Carbon::today();

        $paymentTermId = $this->resolvePaymentTermId($client, $data['payment_term_id'] ?? null);
        $dueDate = $this->resolveDueDate($issueDate, $paymentTermId);

        return Document::create([
            'document_type' => 'sales_invoice',
            'direction' => 'outbound',
            'status' => 'draft',
            'party_id' => $client->id,
            'receivable_account_id' => $this->billingSettings->default_receivable_account_id,
            'payment_term_id' => $paymentTermId,
            'issue_date' => $issueDate->toDateString(),
            'due_date' => $dueDate?->toDateString(),
            'currency' => $this->currencySettings->base_currency,
            'exchange_rate' => 1.0,
            'source' => 'manual',
            'notes' => $data['notes'] ?? null,
            'reference' => $data['reference'] ?? null,
        ]);
    }

    private function resolvePaymentTermId(Party $client, ?string $explicit): ?string
    {
        if ($explicit !== null && $explicit !== '') {
            return $explicit;
        }

        $clientTermId = $client->relationships()
            ->where('relationship_type', 'client')
            ->first()
            ?->payment_term_id;

        return $clientTermId ?? $this->billingSettings->default_payment_term_id;
    }

    private function resolveDueDate(Carbon $issueDate, ?string $paymentTermId): ?Carbon
    {
        if ($paymentTermId === null) {
            return null;
        }

        $term = PaymentTerm::find($paymentTermId);

        if ($term === null) {
            return null;
        }

        return $this->dueDateCalculator->calculate(
            $issueDate,
            $term,
            $this->billingSettings->billing_period_day,
        );
    }
}
