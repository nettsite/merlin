<?php

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Purchasing\Models\Document;
use Nettsite\NettMail\Core\Domain\Templates\MergeTagRenderer;
use NettSite\NettMail\Models\Template;
use RuntimeException;

class InvoiceEmailTemplateService
{
    public function __construct(
        private readonly BillingSettings $billingSettings,
    ) {}

    /**
     * Render the sales invoice transactional email from the configured template.
     *
     * @return array{subject: string, html: string}
     */
    public function render(Document $invoice): array
    {
        $template = Template::find($this->billingSettings->invoice_email_template_id);

        if ($template === null) {
            throw new RuntimeException('No invoice email template configured.');
        }

        $values = $this->mergeTagValues($invoice);
        $renderer = new MergeTagRenderer;

        return [
            'subject' => $renderer->render($template->subject ?? '', $values),
            'html' => $renderer->render($template->html ?? '', $values),
        ];
    }

    /**
     * Available shortcodes for use in invoice email templates.
     *
     * @return array<string, string>
     */
    public static function availableShortcodes(): array
    {
        return [
            '{{invoice_number}}' => 'Invoice number (e.g. INV-2026-00042)',
            '{{amount}}' => 'Invoice total (currency + amount)',
            '{{due_date}}' => 'Payment due date (e.g. 30 Jun 2026)',
            '{{amount_outstanding}}' => 'Balance still owed (partial-payment aware)',
            '{{invoice_url}}' => 'Link to view the invoice in the client portal',
            '{{client_name}}' => 'Client display name',
            '{{company_name}}' => 'Your company name',
        ];
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
            'amount_due' => $invoice->currency.' '.number_format((float) $invoice->total, 2),
            'due_date' => $invoice->due_date ? $invoice->due_date->format('d M Y') : '',
            'amount_outstanding' => $invoice->currency.' '.number_format((float) $invoice->balance_due, 2),
            'invoice_url' => url('/portal/invoices/'.$invoice->id),
            'invoice_details_html' => $this->invoiceDetailsHtml($invoice),
            'company_name' => config('app.name'),
        ];
    }

    private function invoiceDetailsHtml(Document $invoice): string
    {
        $items = [];

        if ($invoice->due_date) {
            $items[] = 'Payment Due: '.e($invoice->due_date->format('d M Y'));
        }

        if ($invoice->paymentTerm) {
            $items[] = 'Payment Terms: '.e($invoice->paymentTerm->name);
        }

        if ($invoice->reference) {
            $items[] = 'Reference: '.e($invoice->reference);
        }

        if (empty($items)) {
            return '';
        }

        $listItems = implode('', array_map(fn (string $item): string => "<li>{$item}</li>", $items));

        return "<ul>{$listItems}</ul>";
    }
}
