<?php

use App\Modules\Billing\Services\BillingService;
use App\Modules\Billing\Services\InvoiceEmailTemplateService;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Services\PartyService;
use App\Modules\Purchasing\Models\Document;
use Nettsite\NettMail\Core\Domain\Templates\TemplateType;
use NettSite\NettMail\Models\Template;

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

it('renders merge tags into subject and html', function (): void {
    $template = Template::create([
        'name' => 'Test Template',
        'type' => TemplateType::Transactional,
        'subject' => 'Invoice {{invoice_number}} for {{client_name}}',
        'html' => '<p>{{client_name}} owes {{amount_due}} from {{company_name}}</p>{{invoice_details_html}}',
    ]);

    $settings = app(BillingSettings::class);
    $settings->invoice_email_template_id = $template->id;
    $settings->save();

    $client = templateClient();
    $doc = templateDraft($client);

    $rendered = app(InvoiceEmailTemplateService::class)->render($doc);

    expect($rendered['subject'])->toBe("Invoice {$doc->document_number} for {$client->displayName}")
        ->and($rendered['html'])->toContain($client->displayName)
        ->and($rendered['html'])->toContain(config('app.name'));
});

it('invoice_details_html includes due date, payment term and reference when present', function (): void {
    $template = Template::create([
        'name' => 'Test Template',
        'type' => TemplateType::Transactional,
        'subject' => 'Invoice {{invoice_number}}',
        'html' => '{{invoice_details_html}}',
    ]);

    $settings = app(BillingSettings::class);
    $settings->invoice_email_template_id = $template->id;
    $settings->save();

    $doc = templateDraft(null, ['reference' => 'PO-123']);
    $doc->due_date = now()->addDays(30)->toDateString();
    $doc->save();
    $doc->load('paymentTerm');

    $rendered = app(InvoiceEmailTemplateService::class)->render($doc);

    expect($rendered['html'])->toContain('Reference: PO-123')
        ->and($rendered['html'])->toContain('Payment Due:');
});

it('invoice_details_html is empty when no optional fields are set', function (): void {
    $template = Template::create([
        'name' => 'Test Template',
        'type' => TemplateType::Transactional,
        'subject' => 'Invoice {{invoice_number}}',
        'html' => '<div>{{invoice_details_html}}</div>',
    ]);

    $settings = app(BillingSettings::class);
    $settings->invoice_email_template_id = $template->id;
    $settings->save();

    $doc = templateDraft();
    $doc->due_date = null;
    $doc->payment_term_id = null;
    $doc->reference = null;
    $doc->save();

    $rendered = app(InvoiceEmailTemplateService::class)->render($doc);

    expect($rendered['html'])->toBe('<div></div>');
});

// --- Phase D shortcodes ---

function shortcodeTemplate(string $html): void
{
    $template = Template::create([
        'name' => 'Shortcode Test',
        'type' => TemplateType::Transactional,
        'subject' => 'Test',
        'html' => $html,
    ]);

    $settings = app(BillingSettings::class);
    $settings->invoice_email_template_id = $template->id;
    $settings->save();
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
    $settings = app(BillingSettings::class);
    $settings->invoice_email_template_id = null;
    $settings->save();

    $doc = templateDraft();

    expect(fn () => app(InvoiceEmailTemplateService::class)->render($doc))
        ->toThrow(RuntimeException::class);
});
