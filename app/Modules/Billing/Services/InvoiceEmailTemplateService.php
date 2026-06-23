<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Models\BillingEmailTemplate;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\Document;
use Nettsite\NettMail\Core\Domain\Templates\MergeTagRenderer;
use NettSite\NettMail\Models\Template;
use RuntimeException;

class InvoiceEmailTemplateService
{
    public function __construct(
        private readonly BillingSettings $billingSettings,
    ) {}

    /**
     * Render a billing email. Uses the invoice template by default; pass a reminder
     * BillingEmailTemplate to render a reminder instead.
     *
     * @return array{subject: string, html: string}
     */
    public function render(Document $invoice, ?BillingEmailTemplate $emailTemplate = null): array
    {
        $emailTemplate ??= BillingEmailTemplate::forInvoice();

        if ($emailTemplate === null) {
            throw new RuntimeException('No invoice email template configured. Run the BillingEmailTemplateSeeder.');
        }

        $values = $this->mergeTagValues($invoice);

        $subject = $this->substitute($emailTemplate->subject, $values);
        $body = $this->substitute($emailTemplate->body, $values);

        $html = $this->wrapInBaseTemplate($body, $values);

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
            '{{invoice_number}}' => 'Invoice number (e.g. INV-2026-00042)',
            '{{amount}}' => 'Invoice total (currency + amount)',
            '{{amount_outstanding}}' => 'Balance still owed (partial-payment aware)',
            '{{due_date}}' => 'Payment due date (e.g. 30 Jun 2026)',
            '{{client_name}}' => 'Client display name',
            '{{company_name}}' => 'Your company name',
            '{{invoice_url}}' => 'Link to view the invoice in the client portal',
            '{{reference}}' => 'Invoice reference (blank if not set)',
        ];
    }

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

    private function substitute(string $template, array $values): string
    {
        $search = array_map(fn (string $k): string => '{{'.$k.'}}', array_keys($values));

        return str_replace($search, array_values($values), $template);
    }

    /**
     * @return array<string, string>
     */
    private function mergeTagValues(Document $invoice): array
    {
        return [
            'client_name' => $invoice->party?->displayName ?? '',
            'invoice_number' => $invoice->document_number ?? '',
            'amount' => $invoice->currency.' '.number_format((float) $invoice->total, 2),
            'amount_outstanding' => $invoice->currency.' '.number_format((float) $invoice->balance_due, 2),
            'due_date' => $invoice->due_date ? $invoice->due_date->format('d M Y') : '',
            'invoice_url' => url('/portal/invoices/'.$invoice->id),
            'company_name' => config('app.name'),
            'reference' => $invoice->reference ?? '',
        ];
    }
}
