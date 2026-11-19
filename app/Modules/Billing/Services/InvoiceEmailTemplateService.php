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

    public function __construct(
        private readonly BillingSettings $billingSettings,
        private readonly CompanySettings $companySettings,
    ) {}

    /**
     * Render a billing email. Invoices use the NettMail template chosen as the invoice
     * email (the whole email, edited in NettMail) when one is set; reminders, and
     * invoices without one, use a BillingEmailTemplate wrapped in the base layout.
     *
     * @return array{subject: string, html: string}
     */
    public function render(Document $invoice, ?BillingEmailTemplate $emailTemplate = null, string $recipientFirstName = ''): array
    {
        $values = $this->mergeTagValues($invoice, $recipientFirstName);

        if ($emailTemplate === null && ($invoiceEmail = $this->invoiceEmailTemplate()) !== null) {
            $renderer = new MergeTagRenderer;

            return [
                'subject' => $renderer->render($invoiceEmail->subject ?: self::DEFAULT_INVOICE_SUBJECT, $values),
                'html' => $renderer->render($invoiceEmail->html ?? '', $this->htmlValues($values)),
            ];
        }

        $emailTemplate ??= BillingEmailTemplate::forInvoice();

        if ($emailTemplate === null) {
            throw new RuntimeException('No invoice email template configured. Run the BillingEmailTemplateSeeder.');
        }

        $subject = $this->substitute($emailTemplate->subject, $values);
        $body = $this->substitute($emailTemplate->body, $this->htmlValues($values));

        $html = $this->wrapInBaseTemplate($body, $this->htmlValues($values));

        return ['subject' => $subject, 'html' => $html];
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

    /**
     * @param  array<string, string>  $values
     */
    private function wrapInBaseTemplate(string $body, array $values): string
    {
        $baseTemplateId = $this->billingSettings->base_email_template_id;

        if ($baseTemplateId === null) {
            return $body;
        }

        $baseTemplate = Template::find($baseTemplateId);

        if ($baseTemplate === null) {
            return $body;
        }

        $style = config('nettmail.email_body_style', 'font-family:Inter,ui-sans-serif,system-ui,sans-serif;font-size:16px;line-height:1.6;color:#1f2937;');
        $styledBody = '<div style="'.$style.'">'.$body.'</div>';

        // [email_body] uses square brackets so GrapesJS/Unlayer does not wrap it
        // in a <code> element the way it does with double-brace expressions.
        $html = str_replace('[email_body]', $styledBody, $baseTemplate->html ?? '');

        return (new MergeTagRenderer)->render($html, $values);
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
