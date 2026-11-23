<?php

namespace App\Modules\Billing\Services;

use App\Mail\PaymentReceiptMail;
use App\Mail\SalesInvoiceMail;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentRelationship;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\PaymentTerm;
use App\Modules\Core\Models\Person;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;
use App\Modules\Core\Settings\CurrencySettings;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

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
     * @param  array<string, mixed>  $data  Accepted keys: issue_date, payment_term_id, notes, reference, terms, footer
     */
    public function createDraft(Party $client, array $data): Document
    {
        $issueDate = isset($data['issue_date'])
            ? Carbon::parse($data['issue_date'])
            : Carbon::today();

        $paymentTermId = $this->resolvePaymentTermId($client, $data['payment_term_id'] ?? null);
        $dueDate = $this->resolveDueDate($issueDate, $paymentTermId);

        $clientRel = $client->relationships()->where('relationship_type', 'client')->first();
        $receivableAccountId = $clientRel?->default_receivable_account_id
            ?? $this->billingSettings->default_receivable_account_id;

        return Document::create([
            'document_type' => 'sales_invoice',
            'direction' => 'outbound',
            'status' => 'draft',
            'party_id' => $client->id,
            'receivable_account_id' => $receivableAccountId,
            'payment_term_id' => $paymentTermId,
            'issue_date' => $issueDate->toDateString(),
            'due_date' => $dueDate?->toDateString(),
            'currency' => $this->currencySettings->base_currency,
            'exchange_rate' => 1.0,
            'source' => 'manual',
            'notes' => $data['notes'] ?? null,
            'reference' => $data['reference'] ?? null,
            'terms' => $data['terms'] ?? null,
            'footer' => $data['footer'] ?? null,
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

        $templates = app(InvoiceEmailTemplateService::class);

        // Rendered per recipient: {{first_name}} is the contact receiving that copy.
        foreach ($recipientEmails as $email) {
            $firstName = (string) Person::where('email', $email)->value('first_name');
            $rendered = $templates->render($invoice, null, $firstName);

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
     * @param  array{amount: float, date: string, reference?: string|null, contra_account_id?: string|null}  $data
     */
    public function recordPayment(Document $invoice, array $data, ?User $by): Document
    {
        return $this->recordPaymentForInvoices(
            [['invoice' => $invoice, 'amount' => (float) $data['amount']]],
            $data,
        );
    }

    /**
     * Record a single payment that covers one or more sales invoices for the
     * same client — one EFT settling several invoices at once. Creates one
     * payment Document, linked to every invoice via DocumentRelationship,
     * then delegates each invoice's amount/balance update to
     * DocumentService::recordPayment().
     *
     * @param  array<int, array{invoice: Document, amount: float}>  $allocations
     * @param  array{date: string, reference?: string|null, contra_account_id?: string|null}  $data
     */
    public function recordPaymentForInvoices(array $allocations, array $data): Document
    {
        if ($allocations === []) {
            throw new RuntimeException('A payment must cover at least one invoice.');
        }

        $invoices = array_map(fn (array $a): Document => $a['invoice'], $allocations);
        $firstInvoice = $invoices[0];

        if (collect($invoices)->pluck('party_id')->unique()->count() > 1) {
            throw new RuntimeException('All invoices in a single payment must belong to the same client.');
        }

        $date = Carbon::parse($data['date']);
        $reference = $data['reference'] ?? null;
        $bankAccountId = $data['contra_account_id'] ?? $this->billingSettings->default_contra_account_id;
        $total = array_sum(array_map(fn (array $a): float => (float) $a['amount'], $allocations));

        app(DocumentService::class)->assertValidContraAccount($bankAccountId);

        // Transactional so a rejected payment (e.g. overpayment guard in
        // DocumentService) doesn't leave an orphaned payment document behind.
        $payment = DB::transaction(function () use ($allocations, $firstInvoice, $total, $date, $reference, $bankAccountId) {
            $payment = Document::create([
                'document_type' => 'payment',
                'direction' => 'inbound',
                'status' => 'draft',
                'party_id' => $firstInvoice->party_id,
                'issue_date' => $date->toDateString(),
                'currency' => $firstInvoice->currency,
                'exchange_rate' => $firstInvoice->exchange_rate ?? 1.0,
                'subtotal' => $total,
                'tax_total' => 0,
                'total' => $total,
                'receivable_account_id' => $firstInvoice->receivable_account_id,
                'contra_account_id' => $bankAccountId,
                'source' => 'manual',
                'reference' => $reference,
            ]);

            foreach ($allocations as $allocation) {
                $invoice = $allocation['invoice'];
                $amount = (float) $allocation['amount'];

                if ($invoice->document_type !== 'sales_invoice') {
                    throw new RuntimeException("Cannot record a sales payment against a {$invoice->document_type} ({$invoice->document_number}).");
                }

                if (! in_array($invoice->status, ['sent', 'partially_paid'])) {
                    throw new RuntimeException("Cannot record payment against a {$invoice->status} invoice ({$invoice->document_number}).");
                }

                DocumentRelationship::create([
                    'parent_document_id' => $invoice->id,
                    'child_document_id' => $payment->id,
                    'relationship_type' => 'payment_for',
                ]);

                app(DocumentService::class)->recordPayment($invoice, $amount, $date, $reference);
            }

            return $payment;
        });

        // Outside the transaction: a receipt-sending failure must not roll back an
        // already-committed payment.
        $this->sendReceipt($payment);

        return $payment;
    }

    /**
     * Generate a PDF receipt for the payment and store it in the payment_receipt media collection.
     */
    public function generateReceiptPdf(Document $payment): void
    {
        $payment->load('party.business', 'party.addresses');
        $invoices = $payment->parentDocuments()->wherePivot('relationship_type', 'payment_for')->get();

        $pdf = Pdf::loadView('pdf.payment-receipt', [
            'payment' => $payment,
            'invoices' => $invoices,
        ]);

        $payment->clearMediaCollection('payment_receipt');

        $payment->addMediaFromString($pdf->output())
            ->usingFileName('receipt-'.($payment->document_number ?? $payment->id).'.pdf')
            ->usingName('Receipt '.($payment->document_number ?? $payment->id))
            ->toMediaCollection('payment_receipt');
    }

    /**
     * Email the payment receipt to the client's invoice contacts. Logged and
     * swallowed on failure — a receipt that fails to send must not undo an
     * already-recorded payment.
     */
    public function sendReceipt(Document $payment): void
    {
        try {
            $recipientEmails = $this->resolveRecipients($payment);

            if ($recipientEmails === []) {
                return;
            }

            $this->generateReceiptPdf($payment);
            $payment->refresh();

            $templates = app(InvoiceEmailTemplateService::class);

            foreach ($recipientEmails as $recipient) {
                $rendered = $templates->renderReceipt($payment, $recipient['name'] !== '' ? explode(' ', $recipient['name'])[0] : '');

                Mail::mailer('nettmail')->to($recipient['email'])->send(
                    new PaymentReceiptMail($payment, $rendered['subject'], $rendered['html']),
                );
            }

            $payment->activities()->create([
                'activity_type' => 'receipt_sent',
                'description' => 'Payment receipt emailed to '.implode(', ', array_column($recipientEmails, 'email')).'.',
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to send payment receipt.', ['payment_id' => $payment->id, 'exception' => $e]);
        }
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
