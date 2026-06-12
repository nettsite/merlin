<?php

use Nettsite\NettMail\Core\Domain\Templates\TemplateType;
use NettSite\NettMail\Models\Template;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $template = Template::create([
            'name' => 'Sales Invoice',
            'type' => TemplateType::Transactional,
            'subject' => 'Invoice {{invoice_number}}',
            'html' => $this->defaultHtml(),
            'plain_text' => $this->defaultPlainText(),
        ]);

        $this->migrator->add('billing.invoice_email_template_id', $template->id);
    }

    private function defaultHtml(): string
    {
        return <<<'HTML'
        <div style="font-family: Arial, sans-serif; font-size: 15px; line-height: 1.6; color: #1f2937;">
            <h1 style="font-size: 20px;">Invoice {{invoice_number}}</h1>

            <p>Dear {{client_name}},</p>

            <p>Please find your invoice attached.</p>

            <p><strong>Amount Due:</strong> {{amount_due}}</p>

            {{invoice_details_html}}

            <p>If you have any questions about this invoice, please don't hesitate to get in touch.</p>

            <p>Thanks,<br>{{company_name}}</p>
        </div>
        HTML;
    }

    private function defaultPlainText(): string
    {
        return <<<'TEXT'
        Invoice {{invoice_number}}

        Dear {{client_name}},

        Please find your invoice attached.

        Amount Due: {{amount_due}}

        If you have any questions about this invoice, please don't hesitate to get in touch.

        Thanks,
        {{company_name}}
        TEXT;
    }
};
