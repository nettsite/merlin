<?php

namespace Database\Seeders;

use App\Modules\Billing\Models\BillingEmailTemplate;
use Illuminate\Database\Seeder;
use Nettsite\NettMail\Core\Domain\Templates\TemplateType;
use NettSite\NettMail\Models\Template;

class BillingEmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'type' => 'invoice',
                'name' => 'Invoice',
                'subject' => 'Invoice {{invoice_number}} from {{company_name}}',
                'body' => '<p>Hi {{client_name}},</p><p>Please find your invoice attached to this email.</p><ul><li>Invoice Number: {{invoice_number}}</li><li>Amount Due: {{amount}}</li><li>Due Date: {{due_date}}</li></ul><p>If you have any questions, please don\'t hesitate to get in touch.</p><p>Thank you for your business.</p>',
                'offset_days' => null,
                'enabled' => true,
            ],
            [
                'type' => 'reminder',
                'name' => 'Early Reminder',
                'subject' => 'Reminder: Invoice {{invoice_number}} is due on {{due_date}}',
                'body' => '<p>Hi {{client_name}},</p><p>This is a friendly reminder that invoice {{invoice_number}} for {{amount_outstanding}} is due on {{due_date}}.</p><p>If you have already arranged payment, please disregard this message.</p><p>Thank you.</p>',
                'offset_days' => -3,
                'enabled' => true,
            ],
            [
                'type' => 'reminder',
                'name' => 'First Reminder',
                'subject' => 'Invoice {{invoice_number}} was due yesterday',
                'body' => '<p>Hi {{client_name}},</p><p>Invoice {{invoice_number}} for {{amount_outstanding}} was due yesterday and remains unpaid.</p><p>Please arrange payment at your earliest convenience. If you have already paid, please let us know so we can update our records.</p><p>Thank you.</p>',
                'offset_days' => 1,
                'enabled' => true,
            ],
            [
                'type' => 'reminder',
                'name' => 'Second Reminder',
                'subject' => 'OVERDUE: Invoice {{invoice_number}} — 7 days past due',
                'body' => '<p>Hi {{client_name}},</p><p>Invoice {{invoice_number}} for {{amount_outstanding}} is now 7 days overdue.</p><p>Please arrange payment immediately to avoid further action. If there is a query on this invoice, please contact us urgently.</p>',
                'offset_days' => 7,
                'enabled' => true,
            ],
            [
                'type' => 'receipt',
                'name' => 'Payment Receipt',
                'subject' => 'Payment received — {{invoices}}',
                'body' => '<p>Hi {{client_name}},</p><p>Thank you, we\'ve received your payment of {{amount}} on {{date}}.</p><ul><li>Reference: {{reference}}</li><li>For invoice(s): {{invoices}}</li></ul><p>Your receipt is attached to this email.</p><p>Thank you for your business.</p>',
                'offset_days' => null,
                'enabled' => true,
            ],
            [
                'type' => 'reminder',
                'name' => 'Final Notice',
                'subject' => 'FINAL NOTICE: Invoice {{invoice_number}} — immediate payment required',
                'body' => '<p>Hi {{client_name}},</p><p>This is a final notice for invoice {{invoice_number}} for {{amount_outstanding}}, which is now 14 days overdue.</p><p>If payment is not received within 48 hours, we may be required to take further action to recover the outstanding amount.</p><p>Please contact us immediately if you are unable to settle this invoice.</p>',
                'offset_days' => 14,
                'enabled' => true,
            ],
        ];

        $billingEmailTemplates = [];

        foreach ($templates as $data) {
            $billingEmailTemplates[$data['name']] = BillingEmailTemplate::firstOrCreate(
                ['type' => $data['type'], 'name' => $data['name']],
                $data,
            );
        }

        $this->seedReminderNettMailTemplates($billingEmailTemplates);
    }

    /**
     * Reminders have no legacy fallback content any more — they always send through
     * a NettMail template. Seeds one per reminder stage, with wording escalating from
     * a friendly heads-up to a firm final notice, and wires it onto the matching
     * BillingEmailTemplate row so reminders work out of the box. Never overwrites a
     * template already chosen for a row (idempotent re-seed).
     *
     * @param  array<string, BillingEmailTemplate>  $billingEmailTemplates  Keyed by name.
     */
    private function seedReminderNettMailTemplates(array $billingEmailTemplates): void
    {
        $style = 'font-family:Inter,ui-sans-serif,system-ui,sans-serif;font-size:16px;line-height:1.6;color:#1f2937;';

        $nettMailTemplates = [
            'Early Reminder' => [
                'name' => 'Reminder — Early (before due)',
                'subject' => 'Just a heads-up: invoice {{invoice_number}} is due {{due_date}}',
                'html' => "<div style=\"{$style}\">"
                    .'<p>Hi {{first_name}},</p>'
                    .'<p>Just a quick heads-up that invoice {{invoice_number}} for {{amount_outstanding}} is coming up for payment on {{due_date}}.</p>'
                    .'<p>No action needed if payment is already arranged — this is just a friendly reminder.</p>'
                    .'<p>Thanks,<br>{{company_name}}</p>'
                    .'</div>',
            ],
            'First Reminder' => [
                'name' => 'Reminder — First overdue notice',
                'subject' => 'Invoice {{invoice_number}} is now overdue',
                'html' => "<div style=\"{$style}\">"
                    .'<p>Hi {{first_name}},</p>'
                    .'<p>Invoice {{invoice_number}} for {{amount_outstanding}} was due on {{due_date}} and appears to still be outstanding.</p>'
                    .'<p>If you\'ve already taken care of this, please disregard — otherwise, we\'d appreciate payment at your earliest convenience.</p>'
                    .'<p>Thanks,<br>{{company_name}}</p>'
                    .'</div>',
            ],
            'Second Reminder' => [
                'name' => 'Reminder — Second notice (overdue)',
                'subject' => 'Overdue: invoice {{invoice_number}} needs your attention',
                'html' => "<div style=\"{$style}\">"
                    .'<p>Hi {{first_name}},</p>'
                    .'<p>Invoice {{invoice_number}} for {{amount_outstanding}} is now significantly overdue (was due {{due_date}}).</p>'
                    .'<p>Please arrange payment urgently. If there\'s a query holding this up, let us know straight away so we can sort it out.</p>'
                    .'<p>Regards,<br>{{company_name}}</p>'
                    .'</div>',
            ],
            'Final Notice' => [
                'name' => 'Reminder — Final notice',
                'subject' => 'Final notice: invoice {{invoice_number}} remains unpaid',
                'html' => "<div style=\"{$style}\">"
                    .'<p>Hi {{first_name}},</p>'
                    .'<p><strong>This is a final notice.</strong> Invoice {{invoice_number}} for {{amount_outstanding}}, due {{due_date}}, remains unpaid.</p>'
                    .'<p>Please settle this invoice immediately. If payment is not received shortly, we may need to take further action to recover the amount owed.</p>'
                    .'<p>Please contact us right away if you are unable to pay.</p>'
                    .'<p>Regards,<br>{{company_name}}</p>'
                    .'</div>',
            ],
        ];

        foreach ($nettMailTemplates as $reminderName => $data) {
            $billingEmailTemplate = $billingEmailTemplates[$reminderName] ?? null;

            if ($billingEmailTemplate === null || $billingEmailTemplate->nettmail_template_id !== null) {
                continue;
            }

            $nettMailTemplate = Template::firstOrCreate(
                ['name' => $data['name']],
                ['type' => TemplateType::Transactional, 'subject' => $data['subject'], 'html' => $data['html']],
            );

            $billingEmailTemplate->update(['nettmail_template_id' => $nettMailTemplate->id]);
        }
    }
}
