<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tax Configuration
    |--------------------------------------------------------------------------
    |
    | South Africa uses a single VAT rate. The rate is stored on each document
    | line at the time of entry so historical records are unaffected by changes
    | made here.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Default Accounts
    |--------------------------------------------------------------------------
    |
    | Account codes used as defaults when creating documents. These must match
    | codes in your chart of accounts.
    |
    */

    // Tax exempt rate is a system constant, not tenant-editable.
    'tax' => [
        'exempt_rate' => 0.00,
    ],

    // Business-editable values (payable account, default tax rate/label,
    // autopost thresholds) are stored in App\Modules\Purchasing\Settings\PurchasingSettings.

    /*
    |--------------------------------------------------------------------------
    | Invoice Watch Folder
    |--------------------------------------------------------------------------
    |
    | The folder monitored by the `invoices:watch` Artisan command. Drop PDFs
    | here and the command will process them through the full LLM pipeline.
    | In SaaS mode the SaaS wrapper appends a per-tenant subfolder automatically.
    |
    */

    'watch' => [
        'folder' => env('INVOICE_WATCH_DIR', storage_path('app/invoice-watch')),
        'interval' => (int) env('INVOICE_WATCH_INTERVAL', 10),
    ],

    'magika' => [
        'binary' => env('MAGIKA_BINARY', 'magika'),
    ],

    'types' => [
        'purchase_invoice' => [
            'label' => 'Purchase Invoice',
            'direction' => 'inbound',
            'prefix' => 'PINV',
            'default_status' => 'received',
            'requires_due_date' => true,
        ],
        'debit_note' => [
            'label' => 'Debit Note',
            'direction' => 'outbound',
            'prefix' => 'DN',
            'default_status' => 'draft',
            'requires_due_date' => false,
        ],
        'sales_invoice' => [
            'label' => 'Sales Invoice',
            'direction' => 'outbound',
            'prefix' => 'SINV',
            'default_status' => 'draft',
            'requires_due_date' => true,
        ],
        'credit_note' => [
            'label' => 'Credit Note',
            'direction' => 'outbound',
            'prefix' => 'CN',
            'default_status' => 'draft',
            'requires_due_date' => false,
        ],
        'purchase_order' => [
            'label' => 'Purchase Order',
            'direction' => 'outbound',
            'prefix' => 'PO',
            'default_status' => 'draft',
            'requires_due_date' => false,
        ],
        'quote' => [
            'label' => 'Quote',
            'direction' => 'outbound',
            'prefix' => 'QT',
            'default_status' => 'draft',
            'requires_due_date' => false,
        ],
    ],

];
