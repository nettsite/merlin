<?php

namespace Database\Seeders;

use App\Modules\Billing\Models\BillingEmailTemplate;
use Illuminate\Database\Seeder;

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
                'type' => 'reminder',
                'name' => 'Final Notice',
                'subject' => 'FINAL NOTICE: Invoice {{invoice_number}} — immediate payment required',
                'body' => '<p>Hi {{client_name}},</p><p>This is a final notice for invoice {{invoice_number}} for {{amount_outstanding}}, which is now 14 days overdue.</p><p>If payment is not received within 48 hours, we may be required to take further action to recover the outstanding amount.</p><p>Please contact us immediately if you are unable to settle this invoice.</p>',
                'offset_days' => 14,
                'enabled' => true,
            ],
        ];

        foreach ($templates as $data) {
            BillingEmailTemplate::firstOrCreate(
                ['type' => $data['type'], 'name' => $data['name']],
                $data,
            );
        }
    }
}
