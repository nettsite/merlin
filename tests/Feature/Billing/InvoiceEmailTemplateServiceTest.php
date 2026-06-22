<?php

use App\Modules\Billing\Models\BillingEmailTemplate;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Billing\Services\InvoiceEmailTemplateService;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Services\PartyService;
use App\Modules\Purchasing\Models\Document;

function templateClient(): Party
{
    return app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Template Client Ltd',
        'trading_name' => 'Template Client',
        'status' => 'active',
    ], ['client']);
}

function templateDraft(?Party $client = null, array $data = []): Document
{
    $client ??= templateClient();

    return app(BillingService::class)->createDraft($client, array_merge([
        'issue_date' => now()->toDateString(),
    ], $data));
}

beforeEach(function (): void {
    BillingEmailTemplate::where('type', 'invoice')->delete();
});

it('renders merge tags into subject and html', function (): void {
    BillingEmailTemplate::create([
        'type' => 'invoice',
        'name' => 'Test Template',
        'subject' => 'Invoice {{invoice_number}} for {{client_name}}',
        'body' => '<p>{{client_name}} from {{company_name}}</p>',
        'enabled' => true,
    ]);

    $client = templateClient();
    $doc = templateDraft($client);

    $rendered = app(InvoiceEmailTemplateService::class)->render($doc);

    expect($rendered['subject'])->toBe("Invoice {$doc->document_number} for {$client->displayName}")
        ->and($rendered['html'])->toContain($client->displayName)
        ->and($rendered['html'])->toContain(config('app.name'));
});

// --- Phase D shortcodes ---

function shortcodeTemplate(string $body): void
{
    BillingEmailTemplate::create([
        'type' => 'invoice',
        'name' => 'Shortcode Test',
        'subject' => 'Test',
        'body' => $body,
        'enabled' => true,
    ]);
}

it('renders {{invoice_number}} shortcode', function (): void {
    shortcodeTemplate('Ref: {{invoice_number}}');
    $doc = templateDraft();

    $rendered = app(InvoiceEmailTemplateService::class)->render($doc);

    expect($rendered['html'])->toBe("Ref: {$doc->document_number}");
});

it('renders {{amount}} shortcode as formatted total', function (): void {
    shortcodeTemplate('Total: {{amount}}');
    $doc = templateDraft();
    $doc->update(['total' => 1150.00, 'currency' => 'ZAR']);

    $rendered = app(InvoiceEmailTemplateService::class)->render($doc);

    expect($rendered['html'])->toBe('Total: ZAR 1,150.00');
});

it('renders {{due_date}} shortcode', function (): void {
    shortcodeTemplate('Due: {{due_date}}');
    $doc = templateDraft();
    $doc->update(['due_date' => '2026-06-30']);

    $rendered = app(InvoiceEmailTemplateService::class)->render($doc);

    expect($rendered['html'])->toBe('Due: 30 Jun 2026');
});

it('renders {{amount_outstanding}} reflecting partial payment', function (): void {
    shortcodeTemplate('Outstanding: {{amount_outstanding}}');
    $doc = templateDraft();
    $doc->update(['total' => 1000.00, 'balance_due' => 400.00, 'currency' => 'ZAR']);

    $rendered = app(InvoiceEmailTemplateService::class)->render($doc);

    expect($rendered['html'])->toBe('Outstanding: ZAR 400.00');
});

it('renders {{invoice_url}} as a portal link', function (): void {
    shortcodeTemplate('View: {{invoice_url}}');
    $doc = templateDraft();

    $rendered = app(InvoiceEmailTemplateService::class)->render($doc);

    expect($rendered['html'])->toContain('/portal/invoices/'.$doc->id);
});

it('leaves unknown shortcodes untouched', function (): void {
    shortcodeTemplate('Hello {{unknown_tag}}');
    $doc = templateDraft();

    $rendered = app(InvoiceEmailTemplateService::class)->render($doc);

    expect($rendered['html'])->toBe('Hello {{unknown_tag}}');
});

it('throws when no template is configured', function (): void {
    $doc = templateDraft();

    expect(fn () => app(InvoiceEmailTemplateService::class)->render($doc))
        ->toThrow(RuntimeException::class);
});
