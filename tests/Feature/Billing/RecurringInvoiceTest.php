<?php

use App\Modules\Billing\Enums\RecurringFrequency;
use App\Modules\Billing\Enums\RecurringInvoiceStatus;
use App\Modules\Billing\Models\RecurringInvoice;
use App\Modules\Billing\Services\RecurringInvoiceService;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
use App\Modules\Purchasing\Models\Document;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

function riUserWith(array $permissions): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

function recurringClient(): Party
{
    return app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Recurring Client Ltd',
        'status' => 'active',
    ], ['client']);
}

function baseTemplateData(Party $client, string $startDate = '2026-05-01'): array
{
    return [
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Monthly->value,
        'billing_period_day' => 1,
        'start_date' => $startDate,
        'lines' => [
            [
                'description' => 'Monthly service fee',
                'account_id' => '',
                'quantity' => '1',
                'unit_price' => '1000',
                'discount_percent' => '0',
                'tax_rate' => '15',
            ],
        ],
    ];
}

// --- Page access ---

it('redirects unauthenticated users to login', function (): void {
    $this->get('/recurring-invoices')->assertRedirect('/login');
});

it('renders the recurring invoices page', function (): void {
    $this->actingAs(riUserWith(['recurring-invoices-view-any']));

    Volt::test('pages.recurring-invoices.index')
        ->assertOk()
        ->assertSee('Recurring Invoices');
});

// --- createTemplate ---

it('creates template on billing day without pro rata', function (): void {
    Mail::fake();

    $client = recurringClient();

    $template = app(RecurringInvoiceService::class)->createTemplate(
        baseTemplateData($client, '2026-06-01')
    );

    expect($template->next_invoice_date->toDateString())->toBe('2026-06-01')
        ->and(Document::where('metadata->recurring_invoice_id', $template->id)->count())->toBe(0);
});

it('creates pro rata invoice when starting mid-period', function (): void {
    Mail::fake();

    $client = recurringClient();

    $template = app(RecurringInvoiceService::class)->createTemplate(
        baseTemplateData($client, '2026-05-15')
    );

    expect($template->fresh()->next_invoice_date->toDateString())->toBe('2026-06-01');

    $doc = Document::where('metadata->recurring_invoice_id', $template->id)->first();
    expect($doc)->not->toBeNull()
        ->and($doc->issue_date->toDateString())->toBe('2026-05-15')
        ->and($doc->lines->first()?->description)->toContain('pro rata');
});

it('stores lines on template create', function (): void {
    Mail::fake();

    $client = recurringClient();
    $template = app(RecurringInvoiceService::class)->createTemplate(baseTemplateData($client));

    expect($template->lines)->toHaveCount(1)
        ->and($template->lines->first()->description)->toBe('Monthly service fee');
});

// --- generateFromTemplate ---

it('generates a sales invoice from a template', function (): void {
    Mail::fake();

    $client = recurringClient();
    $template = RecurringInvoice::create([
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Monthly,
        'billing_period_day' => 1,
        'start_date' => '2026-05-01',
        'next_invoice_date' => '2026-05-01',
        'status' => RecurringInvoiceStatus::Active,
        'currency' => 'ZAR',
    ]);
    $template->lines()->create([
        'line_number' => 1,
        'description' => 'Service',
        'quantity' => 1,
        'unit_price' => 500,
        'discount_percent' => 0,
        'tax_rate' => 15,
    ]);

    $doc = app(RecurringInvoiceService::class)->generateFromTemplate($template);

    expect($doc)->toBeInstanceOf(Document::class)
        ->and($doc->document_type)->toBe('sales_invoice')
        ->and($doc->metadata['recurring_invoice_id'])->toBe($template->id);
});

it('pro rata generation adjusts the quantity below 1', function (): void {
    Mail::fake();

    $client = recurringClient();
    $template = RecurringInvoice::create([
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Monthly,
        'billing_period_day' => 1,
        'start_date' => '2026-05-15',
        'next_invoice_date' => '2026-06-01',
        'status' => RecurringInvoiceStatus::Active,
        'currency' => 'ZAR',
    ]);
    $template->lines()->create([
        'line_number' => 1,
        'description' => 'Service',
        'quantity' => 1,
        'unit_price' => 1000,
        'discount_percent' => 0,
        'tax_rate' => 15,
    ]);

    $doc = app(RecurringInvoiceService::class)->generateFromTemplate($template, true);

    $line = $doc->lines->first();
    expect((float) $line->quantity)->toBeLessThan(1.0)
        ->and($line->description)->toContain('pro rata');
});

// --- advanceNextDate ---

it('advances monthly next_invoice_date by one month', function (): void {
    $client = recurringClient();
    $template = RecurringInvoice::create([
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Monthly,
        'billing_period_day' => 1,
        'start_date' => '2026-05-01',
        'next_invoice_date' => '2026-05-01',
        'status' => RecurringInvoiceStatus::Active,
        'currency' => 'ZAR',
    ]);

    app(RecurringInvoiceService::class)->advanceNextDate($template);

    expect($template->fresh()->next_invoice_date->toDateString())->toBe('2026-06-01');
});

it('advances quarterly next_invoice_date by three months', function (): void {
    $client = recurringClient();
    $template = RecurringInvoice::create([
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Quarterly,
        'billing_period_day' => 1,
        'start_date' => '2026-01-01',
        'next_invoice_date' => '2026-01-01',
        'status' => RecurringInvoiceStatus::Active,
        'currency' => 'ZAR',
    ]);

    app(RecurringInvoiceService::class)->advanceNextDate($template);

    expect($template->fresh()->next_invoice_date->toDateString())->toBe('2026-04-01');
});

it('advances annually next_invoice_date by one year', function (): void {
    $client = recurringClient();
    $template = RecurringInvoice::create([
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Annually,
        'billing_period_day' => 1,
        'start_date' => '2026-01-01',
        'next_invoice_date' => '2026-01-01',
        'status' => RecurringInvoiceStatus::Active,
        'currency' => 'ZAR',
    ]);

    app(RecurringInvoiceService::class)->advanceNextDate($template);

    expect($template->fresh()->next_invoice_date->toDateString())->toBe('2027-01-01');
});

// --- completeIfExpired ---

it('marks completed when next_invoice_date passes end_date', function (): void {
    $client = recurringClient();
    $template = RecurringInvoice::create([
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Monthly,
        'billing_period_day' => 1,
        'start_date' => '2025-01-01',
        'end_date' => '2025-12-31',
        'next_invoice_date' => '2026-01-01',
        'status' => RecurringInvoiceStatus::Active,
        'currency' => 'ZAR',
    ]);

    app(RecurringInvoiceService::class)->completeIfExpired($template);

    expect($template->fresh()->status)->toBe(RecurringInvoiceStatus::Completed);
});

it('does nothing if there is no end_date', function (): void {
    $client = recurringClient();
    $template = RecurringInvoice::create([
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Monthly,
        'billing_period_day' => 1,
        'start_date' => '2026-01-01',
        'next_invoice_date' => '2026-01-01',
        'status' => RecurringInvoiceStatus::Active,
        'currency' => 'ZAR',
    ]);

    app(RecurringInvoiceService::class)->completeIfExpired($template);

    expect($template->fresh()->status)->toBe(RecurringInvoiceStatus::Active);
});

// --- Artisan command ---

it('dry-run does not generate invoices', function (): void {
    Mail::fake();

    $client = recurringClient();
    RecurringInvoice::create([
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Monthly,
        'billing_period_day' => 1,
        'start_date' => '2026-04-01',
        'next_invoice_date' => '2026-04-01',
        'status' => RecurringInvoiceStatus::Active,
        'currency' => 'ZAR',
    ]);

    $this->artisan('billing:generate-recurring --dry-run')->assertExitCode(0);

    expect(Document::salesInvoices()->count())->toBe(0);
});

it('command generates due invoices and advances next_invoice_date', function (): void {
    Mail::fake();

    $client = recurringClient();
    $template = RecurringInvoice::create([
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Monthly,
        'billing_period_day' => 1,
        'start_date' => '2026-04-01',
        'next_invoice_date' => '2026-04-01',
        'status' => RecurringInvoiceStatus::Active,
        'currency' => 'ZAR',
    ]);
    $template->lines()->create([
        'line_number' => 1,
        'description' => 'Service',
        'quantity' => 1,
        'unit_price' => 500,
        'discount_percent' => 0,
        'tax_rate' => 15,
    ]);

    $this->artisan('billing:generate-recurring')->assertExitCode(0);

    expect(Document::salesInvoices()->count())->toBe(1)
        ->and($template->fresh()->next_invoice_date->toDateString())->toBe('2026-05-01');
});

it('command skips non-due templates', function (): void {
    Mail::fake();

    $client = recurringClient();
    RecurringInvoice::create([
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Monthly,
        'billing_period_day' => 1,
        'start_date' => '2026-06-01',
        'next_invoice_date' => '2026-06-01',
        'status' => RecurringInvoiceStatus::Active,
        'currency' => 'ZAR',
    ]);

    $this->artisan('billing:generate-recurring')
        ->expectsOutput('No recurring invoices due.')
        ->assertExitCode(0);

    expect(Document::salesInvoices()->count())->toBe(0);
});
