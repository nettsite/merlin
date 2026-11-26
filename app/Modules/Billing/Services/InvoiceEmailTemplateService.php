<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\BillingEmailTemplate;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Settings\CompanySettings;
use Illuminate\Support\Facades\Storage;
use Nettsite\NettMail\Core\Domain\Templates\MergeTagRenderer;
use NettSite\NettMail\Models\Template;
use RuntimeException;

class InvoiceEmailTemplateService
{
    private const DEFAULT_INVOICE_SUBJECT = 'Invoice {{invoice_number}} from {{company_name}}';

    private const DEFAULT_RECEIPT_SUBJECT = 'Payment received — {{invoices}}';

    public function __construct(
        private readonly BillingSettings $billingSettings,
        private readonly CompanySettings $companySettings,
    ) {}

    /**
     * Render a billing email. Invoices use the NettMail template chosen as the invoice
     * email (the whole email, edited in NettMail) when one is set; a reminder row uses
     * its own NettMail template when it has one set; otherwise falls back to the
     * BillingEmailTemplate's subject/body as-is.
     *
     * @return array{subject: string, html: string}
     */
    public function render(Document $invoice, ?BillingEmailTemplate $emailTemplate = null, string $recipientFirstName = ''): array
    {
        $values = $this->mergeTagValues($invoice, $recipientFirstName);

        $nettMailTemplate = $emailTemplate === null
            ? $this->invoiceEmailTemplate()
            : $this->nettMailTemplateFor($emailTemplate);

        if ($nettMailTemplate !== null) {
            $renderer = new MergeTagRenderer;

            return [
                'subject' => $renderer->render($nettMailTemplate->subject ?: self::DEFAULT_INVOICE_SUBJECT, $values),
                'html' => $renderer->render($nettMailTemplate->html ?? '', $this->htmlValues($values)),
            ];
        }

        $emailTemplate ??= BillingEmailTemplate::forInvoice();

        if ($emailTemplate === null) {
            throw new RuntimeException('No invoice email template configured. Run the BillingEmailTemplateSeeder.');
        }

        $subject = $this->substitute($emailTemplate->subject, $values);
        $html = $this->substitute($emailTemplate->body, $this->htmlValues($values));

        return ['subject' => $subject, 'html' => $html];
    }

    /**
     * Render a billing email for a specific {@see BillingEmailTemplate} row, with the
     * subject and/or body text replaced for this send only (the override is never
     * written back to the template). Falls back to the row's own content — its NettMail
     * template if it has one, otherwise its subject/body — for whichever of the two
     * overrides was not supplied.
     *
     * @return array{subject: string, html: string}
     */
    public function renderWithOverride(
        Document $invoice,
        BillingEmailTemplate $emailTemplate,
        ?string $subjectOverride,
        ?string $bodyOverride,
        string $recipientFirstName = '',
    ): array {
        $values = $this->mergeTagValues($invoice, $recipientFirstName);
        $nettMailTemplate = $this->nettMailTemplateFor($emailTemplate);

        $subjectTemplate = $subjectOverride ?? ($nettMailTemplate?->subject ?? $emailTemplate->subject);
        $bodyTemplate = $bodyOverride ?? ($nettMailTemplate?->html ?? $emailTemplate->body);

        if ($nettMailTemplate !== null) {
            $renderer = new MergeTagRenderer;

            return [
                'subject' => $renderer->render($subjectTemplate ?: self::DEFAULT_INVOICE_SUBJECT, $values),
                'html' => $renderer->render($bodyTemplate ?? '', $this->htmlValues($values)),
            ];
        }

        $subject = $this->substitute($subjectTemplate, $values);
        $html = $this->substitute($bodyTemplate, $this->htmlValues($values));

        return ['subject' => $subject, 'html' => $html];
    }

    /**
     * Render a fully ad hoc email — no underlying {@see BillingEmailTemplate} at all.
     *
     * @return array{subject: string, html: string}
     */
    public function renderCustom(Document $invoice, string $subject, string $body, string $recipientFirstName = ''): array
    {
        $values = $this->mergeTagValues($invoice, $recipientFirstName);

        $renderedSubject = $this->substitute($subject, $values);
        $renderedBody = $this->substitute($body, $this->htmlValues($values));

        return ['subject' => $renderedSubject, 'html' => $renderedBody];
    }

    /**
     * Render the payment receipt email for a payment Document. Uses the NettMail
     * template chosen as the payment email (the whole email, edited in NettMail)
     * when one is set, the same way invoices do; otherwise falls back to the
     * "Payment Receipt" BillingEmailTemplate as-is.
     *
     * @return array{subject: string, html: string}
     */
    public function renderReceipt(Document $payment, string $recipientFirstName = ''): array
    {
        $values = $this->receiptMergeTagValues($payment, $recipientFirstName);

        if (($paymentEmail = $this->paymentEmailTemplate()) !== null) {
            $renderer = new MergeTagRenderer;

            return [
                'subject' => $renderer->render($paymentEmail->subject ?: self::DEFAULT_RECEIPT_SUBJECT, $values),
                'html' => $renderer->render($paymentEmail->html ?? '', $this->htmlValues($values)),
            ];
        }

        $emailTemplate = BillingEmailTemplate::forReceipt();

        if ($emailTemplate === null) {
            throw new RuntimeException('No receipt email template configured. Run the BillingEmailTemplateSeeder.');
        }

        $subject = $this->substitute($emailTemplate->subject, $values);
        $html = $this->substitute($emailTemplate->body, $this->htmlValues($values));

        return ['subject' => $subject, 'html' => $html];
    }

    /**
     * Available shortcodes for the payment receipt email template.
     *
     * @return array<string, string>
     */
    public static function availableReceiptShortcodes(): array
    {
        return [
            '{{amount}}' => 'Amount paid (currency + amount)',
            '{{date}}' => 'Date the payment was recorded (e.g. 23 Nov 2026)',
            '{{reference}}' => 'Payment reference (blank if not set)',
            '{{invoices}}' => 'Invoice number(s) the payment was applied to',
            '{{client_name}}' => 'Client display name',
            '{{first_name}}' => 'First name of the contact receiving the email',
            '{{company_name}}' => 'Your company name',
            '{{company_logo}}' => 'Your logo (from Settings → General) as an image',
            '{{company_logo_url}}' => 'Web address of your logo, for an image block',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function receiptMergeTagValues(Document $payment, string $recipientFirstName): array
    {
        $invoiceNumbers = $payment->parentDocuments()
            ->wherePivot('relationship_type', 'payment_for')
            ->pluck('document_number')
            ->filter()
            ->implode(', ');

        return [
            'client_name' => $payment->party?->displayName ?? '',
            'first_name' => $recipientFirstName,
            'amount' => $payment->currency.' '.number_format((float) $payment->total, 2),
            'date' => $payment->issue_date ? $payment->issue_date->format('d M Y') : '',
            'reference' => $payment->reference ?? '',
            'invoices' => $invoiceNumbers,
            'company_name' => $this->companySettings->name ?: (string) config('app.name'),
            'company_logo_url' => $this->companyLogoUrl(),
        ];
    }

    /**
     * Available shortcodes for use in email template subject and body fields.
     *
     * @return array<string, string>
     */
    public static function availableShortcodes(): array
    {
        return [
            '{{invoice_number}}' => 'Invoice number (e.g. R6552)',
            '{{invoice_date}}' => 'Invoice date (e.g. 19 Nov 2026)',
            '{{amount}}' => 'Invoice total (currency + amount)',
            '{{amount_due}}' => 'Balance still owed (same as amount_outstanding)',
            '{{amount_outstanding}}' => 'Balance still owed (partial-payment aware)',
            '{{due_date}}' => 'Payment due date (e.g. 30 Jun 2026)',
            '{{client_name}}' => 'Client display name',
            '{{first_name}}' => 'First name of the contact receiving the email',
            '{{company_name}}' => 'Your company name',
            '{{company_logo}}' => 'Your logo (from Settings → General) as an image',
            '{{company_logo_url}}' => 'Web address of your logo, for an image block',
            '{{invoice_url}}' => 'Link to view the invoice in the client portal',
            '{{reference}}' => 'Invoice reference (blank if not set)',
        ];
    }

    private function invoiceEmailTemplate(): ?Template
    {
        $templateId = $this->billingSettings->invoice_email_template_id;

        return $templateId === null ? null : Template::find($templateId);
    }

    private function paymentEmailTemplate(): ?Template
    {
        $templateId = $this->billingSettings->payment_email_template_id;

        return $templateId === null ? null : Template::find($templateId);
    }

    private function nettMailTemplateFor(BillingEmailTemplate $emailTemplate): ?Template
    {
        return $emailTemplate->nettmail_template_id === null ? null : Template::find($emailTemplate->nettmail_template_id);
    }

    /**
     * @param  array<string, string>  $values
     */
    private function substitute(string $template, array $values): string
    {
        $search = array_map(fn (string $k): string => '{{'.$k.'}}', array_keys($values));

        return str_replace($search, array_values($values), $template);
    }

    /**
     * MergeTagRenderer inserts values verbatim, so values going into HTML are escaped
     * first (a client named "Smith & Sons" must not break or inject markup).
     * {{company_logo}} is the one tag that is markup itself, built from escaped parts.
     *
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function htmlValues(array $values): array
    {
        $html = array_map(fn (string $value): string => e($value), $values);

        $html['company_logo'] = $values['company_logo_url'] === ''
            ? ''
            : '<img src="'.e($values['company_logo_url']).'" alt="'.e($values['company_name']).'" style="max-height:60px;max-width:250px;border:0;">';

        return $html;
    }

    /**
     * Address email clients fetch the logo from, over the internet: the Email Logo
     * URL from General settings when set (needed when this server is not public),
     * otherwise the uploaded logo under APP_URL.
     */
    private function companyLogoUrl(): string
    {
        if (filled($this->companySettings->email_logo_url)) {
            return $this->companySettings->email_logo_url;
        }

        $path = $this->companySettings->logo_path;

        if (! $path || ! Storage::disk('public')->exists($path)) {
            return '';
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * @return array<string, string>
     */
    private function mergeTagValues(Document $invoice, string $recipientFirstName): array
    {
        $outstanding = $invoice->currency.' '.number_format((float) $invoice->balance_due, 2);

        return [
            'client_name' => $invoice->party?->displayName ?? '',
            'first_name' => $recipientFirstName,
            'invoice_number' => $invoice->document_number ?? '',
            'invoice_date' => $invoice->issue_date ? $invoice->issue_date->format('d M Y') : '',
            'amount' => $invoice->currency.' '.number_format((float) $invoice->total, 2),
            'amount_due' => $outstanding,
            'amount_outstanding' => $outstanding,
            'due_date' => $invoice->due_date ? $invoice->due_date->format('d M Y') : '',
            'invoice_url' => url('/portal/invoices/'.$invoice->id),
            'company_name' => $this->companySettings->name ?: (string) config('app.name'),
            'company_logo_url' => $this->companyLogoUrl(),
            'reference' => $invoice->reference ?? '',
        ];
    }
}
