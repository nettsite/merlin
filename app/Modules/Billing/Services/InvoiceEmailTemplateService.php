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
     * @return array<string, string>
     */
    private function mergeTagValues(Document $invoice): array
    {
        return [
            'client_name' => $invoice->party?->displayName ?? '',
            'invoice_number' => $invoice->document_number ?? '',
            'amount_due' => $invoice->currency.' '.number_format((float) $invoice->total, 2),
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
