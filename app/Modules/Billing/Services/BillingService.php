<?php

namespace App\Modules\Billing\Services;

use App\Mail\SalesInvoiceMail;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentRelationship;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\PaymentTerm;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;
use App\Modules\Core\Settings\CurrencySettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;

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

    /**
     * Generate a PDF for the invoice and store it in the invoice_pdf media collection.
     */
    public function generatePdf(Document $invoice): void
    {
        $invoice->load(['party.business', 'party.addresses', 'lines.account', 'paymentTerm']);

        $pdf = Pdf::loadView('pdf.sales-invoice', [
            'invoice' => $invoice,
            'lines' => $invoice->lines,
        ]);

        $invoice->clearMediaCollection('invoice_pdf');

        $invoice->addMediaFromString($pdf->output())
            ->usingFileName('invoice-'.($invoice->document_number ?? $invoice->id).'.pdf')
            ->usingName('Invoice '.($invoice->document_number ?? $invoice->id))
            ->toMediaCollection('invoice_pdf');
    }

    /**
     * Send the invoice to recipients, generate PDF, and transition to "sent".
     *
     * @param  string[]  $recipientEmails  Explicit list; resolves from flagged contacts when empty.
     */
    public function sendInvoice(Document $invoice, ?User $by, array $recipientEmails = []): Document
    {
        if (empty($recipientEmails)) {
            $recipientEmails = $this->resolveRecipientEmails($invoice);
        }

        if (empty($recipientEmails)) {
            throw new RuntimeException(
                'No invoice recipients found for '.($invoice->party?->displayName ?? 'this client').'. Add contacts with "receives invoices" enabled.'
            );
        }

        $this->generatePdf($invoice);

        $invoice->refresh();

        $rendered = app(InvoiceEmailTemplateService::class)->render($invoice);

        foreach ($recipientEmails as $email) {
            Mail::mailer('nettmail')->to($email)->send(new SalesInvoiceMail($invoice, $rendered['subject'], $rendered['html']));
        }

        if ($invoice->status === 'draft') {
            app(DocumentService::class)->markAsSent($invoice, $by);
        } else {
            app(DocumentService::class)->recordResend($invoice, $by);
        }

        return $invoice->refresh();
    }

    /**
     * Record a payment against a sales invoice.
     *
     * Creates a payment Document, links it to the invoice via DocumentRelationship,
     * then delegates amount/balance updates to DocumentService::recordPayment().
     *
     * @param  array{amount: float, date: string, reference?: string|null, bank_account_id?: string|null}  $data
     */
    public function recordPayment(Document $invoice, array $data, ?User $by): Document
    {
        if (! in_array($invoice->status, ['sent', 'partially_paid'])) {
            throw new RuntimeException("Cannot record payment against a {$invoice->status} invoice.");
        }

        $amount = (float) $data['amount'];
        $date = Carbon::parse($data['date']);
        $reference = $data['reference'] ?? null;
        $bankAccountId = $data['bank_account_id'] ?? $this->billingSettings->default_bank_account_id;

        // Transactional so a rejected payment (e.g. overpayment guard in
        // DocumentService) doesn't leave an orphaned payment document behind.
        return DB::transaction(function () use ($invoice, $amount, $date, $reference, $bankAccountId) {
            $payment = Document::create([
                'document_type' => 'payment',
                'direction' => 'inbound',
                'status' => 'draft',
                'party_id' => $invoice->party_id,
                'issue_date' => $date->toDateString(),
                'currency' => $invoice->currency,
                'exchange_rate' => $invoice->exchange_rate ?? 1.0,
                'subtotal' => $amount,
                'tax_total' => 0,
                'total' => $amount,
                'bank_account_id' => $bankAccountId,
                'source' => 'manual',
                'reference' => $reference,
            ]);

            DocumentRelationship::create([
                'parent_document_id' => $invoice->id,
                'child_document_id' => $payment->id,
                'relationship_type' => 'payment_for',
            ]);

            app(DocumentService::class)->recordPayment($invoice, $amount, $date, $reference);

            return $payment;
        });
    }

    /**
     * Resolve recipient email addresses from contact assignments flagged receives_invoices=true.
     *
     * @return string[]
     */
    public function resolveRecipients(Document $invoice): array
    {
        if ($invoice->party_id === null) {
            return [];
        }

        return $invoice->party
            ->contactAssignments()
            ->where('receives_invoices', true)
            ->where('is_active', true)
            ->with('person')
            ->get()
            ->map(fn ($ca) => $ca->person?->email !== null
                ? ['name' => $ca->person->full_name, 'email' => $ca->person->email]
                : null
            )
            ->filter()
            ->unique('email')
            ->values()
            ->all();
    }

    private function resolveRecipientEmails(Document $invoice): array
    {
        return array_column($this->resolveRecipients($invoice), 'email');
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
