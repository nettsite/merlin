<?php

use App\Modules\Billing\Models\BillingEmailTemplate;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Billing\Services\InvoiceEmailTemplateService;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Services\PartyService;
use App\Modules\Core\Settings\CompanySettings;
use Illuminate\Support\Facades\Storage;
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

it('renders the reference merge tag', function (): void {
    BillingEmailTemplate::create([
        'type' => 'invoice',
        'name' => 'Test Template',
        'subject' => 'Invoice {{invoice_number}}',
        'body' => '<p>Ref: {{reference}}</p>',
        'enabled' => true,
    ]);

    $doc = templateDraft(null, ['reference' => 'PO-123']);

    $rendered = app(InvoiceEmailTemplateService::class)->render($doc);

    expect($rendered['html'])->toContain('Ref: PO-123');
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

// --- Complete invoice email from a NettMail template ---

function nettMailInvoiceEmail(string $html, string $subject = 'Invoice {{invoice_number}} from {{company_name}}'): void
{
    $template = Template::create([
        'name' => 'Invoice Email',
        'type' => TemplateType::Transactional,
        'subject' => $subject,
        'html' => $html,
    ]);

    $settings = app(BillingSettings::class);
    $settings->invoice_email_template_id = $template->id;
    $settings->save();
}

it('uses the chosen NettMail template as the whole invoice email', function (): void {
    shortcodeTemplate('<p>Settings text that must not appear</p>');
    nettMailInvoiceEmail('<p>Dear {{first_name}}, invoice {{invoice_number}} for {{client_name}}: {{amount_due}} due {{due_date}}.</p>');

    $company = app(CompanySettings::class);
    $company->name = 'NettSite';
    $company->save();

    $doc = templateDraft();
    $doc->update(['total' => 450, 'balance_due' => 450, 'currency' => 'ZAR', 'due_date' => '2026-12-01']);

    $rendered = app(InvoiceEmailTemplateService::class)->render($doc->fresh(), null, 'Ramson');

    expect($rendered['subject'])->toBe("Invoice {$doc->document_number} from NettSite")
        ->and($rendered['html'])->toBe("<p>Dear Ramson, invoice {$doc->document_number} for Template Client: ZAR 450.00 due 01 Dec 2026.</p>")
        ->and($rendered['html'])->not->toContain('Settings text');
});

it('escapes merge values in the invoice email html but not in the subject', function (): void {
    nettMailInvoiceEmail('<p>{{client_name}}</p>', 'For {{client_name}}');

    $client = app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Smith & Sons <b>',
        'status' => 'active',
    ], ['client']);

    $rendered = app(InvoiceEmailTemplateService::class)->render(templateDraft($client));

    expect($rendered['html'])->toBe('<p>Smith &amp; Sons &lt;b&gt;</p>')
        ->and($rendered['subject'])->toBe('For Smith & Sons <b>');
});

it('falls back to a default subject when the NettMail template has none', function (): void {
    nettMailInvoiceEmail('<p>Hi</p>', '');
    $doc = templateDraft();

    expect(app(InvoiceEmailTemplateService::class)->render($doc)['subject'])
        ->toBe("Invoice {$doc->document_number} from ".config('app.name'));
});

it('keeps reminders on the Settings text inside the base layout', function (): void {
    nettMailInvoiceEmail('<p>Whole invoice email</p>');

    $layout = Template::create([
        'name' => 'Layout',
        'type' => TemplateType::Transactional,
        'subject' => 'x',
        'html' => '<div class="brand">[email_body]</div>',
    ]);
    $settings = app(BillingSettings::class);
    $settings->base_email_template_id = $layout->id;
    $settings->save();

    $reminder = BillingEmailTemplate::create([
        'type' => 'reminder',
        'name' => 'First Reminder',
        'subject' => 'Reminder {{invoice_number}}',
        'body' => '<p>Please pay {{amount_outstanding}}</p>',
        'offset_days' => 1,
        'enabled' => true,
    ]);

    $rendered = app(InvoiceEmailTemplateService::class)->render(templateDraft(), $reminder);

    expect($rendered['html'])->toContain('<div class="brand">')
        ->toContain('Please pay')
        ->not->toContain('Whole invoice email');
});

it('uses the company name from General settings for {{company_name}}', function (): void {
    shortcodeTemplate('From {{company_name}}');

    $company = app(CompanySettings::class);
    $company->name = 'NettSite';
    $company->save();

    expect(app(InvoiceEmailTemplateService::class)->render(templateDraft())['html'])->toBe('From NettSite');
});

it('renders the company logo as an image and as a url', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('company/logo.png', 'png-bytes');

    $company = app(CompanySettings::class);
    $company->name = 'NettSite & Co';
    $company->logo_path = 'company/logo.png';
    $company->save();

    nettMailInvoiceEmail('{{company_logo}}|<img src="{{company_logo_url}}">');

    $html = app(InvoiceEmailTemplateService::class)->render(templateDraft())['html'];
    $url = Storage::disk('public')->url('company/logo.png');

    expect($html)->toBe('<img src="'.e($url).'" alt="NettSite &amp; Co" style="max-height:60px;max-width:250px;border:0;">|<img src="'.e($url).'">');
});

it('renders the logo tags empty when no logo is set', function (): void {
    nettMailInvoiceEmail('[{{company_logo}}][{{company_logo_url}}]');

    expect(app(InvoiceEmailTemplateService::class)->render(templateDraft())['html'])->toBe('[][]');
});
