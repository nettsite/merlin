<?php

use App\Mail\SalesInvoiceMail;
use App\Modules\Billing\Enums\RecurringFrequency;
use App\Modules\Billing\Enums\RecurringInvoiceStatus;
use App\Modules\Billing\Models\BillingEmailTemplate;
use App\Modules\Billing\Models\RecurringInvoice;
use App\Modules\Billing\Services\RecurringInvoiceService;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\Models\Document;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;

beforeEach(function (): void {
    BillingEmailTemplate::firstOrCreate(
        ['type' => 'invoice', 'name' => 'Default Invoice'],
        ['subject' => 'Invoice {{invoice_number}}', 'body' => '<p>Please find your invoice attached.</p>', 'enabled' => true],
    );
});

// Tests in this file use absolute 2026 dates; freeze the clock so "due"
// comparisons against now() never rot as the real calendar advances.
beforeEach(function (): void {
    Carbon::setTestNow('2026-05-15 09:00:00');
});

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

it('stores an explicitly requested template currency', function (): void {
    Mail::fake();

    $client = recurringClient();

    $template = app(RecurringInvoiceService::class)->createTemplate(
        array_merge(baseTemplateData($client, '2026-06-01'), ['currency' => 'usd'])
    );

    expect($template->currency)->toBe('USD');
});

it('defaults template currency to the configured base currency', function (): void {
    Mail::fake();

    $currencySettings = app(CurrencySettings::class);
    $currencySettings->base_currency = 'EUR';
    $currencySettings->save();

    $client = recurringClient();

    $template = app(RecurringInvoiceService::class)->createTemplate(
        baseTemplateData($client, '2026-06-01')
    );

    expect($template->currency)->toBe('EUR');
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

it('advances next_invoice_date in the same transaction as generation', function (): void {
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

    app(RecurringInvoiceService::class)->generateFromTemplate($template);

    expect($template->fresh()->next_invoice_date->toDateString())->toBe('2026-06-01');
});

it('returns the existing invoice instead of generating a duplicate for the same period', function (): void {
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

    $existing = Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'draft',
        'party_id' => $client->id,
        'issue_date' => '2026-05-01',
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'source' => 'manual',
        'metadata' => ['recurring_invoice_id' => $template->id],
    ]);

    $doc = app(RecurringInvoiceService::class)->generateFromTemplate($template);

    expect($doc->id)->toBe($existing->id)
        ->and(Document::salesInvoices()->count())->toBe(1)
        // Self-heal: the period is covered, so the schedule advances anyway.
        ->and($template->fresh()->next_invoice_date->toDateString())->toBe('2026-06-01');
});

it('clamps next_invoice_date to the month length for billing day 31', function (): void {
    Mail::fake();

    $client = recurringClient();

    $template = app(RecurringInvoiceService::class)->createTemplate(
        array_merge(baseTemplateData($client, '2026-02-10'), ['billing_period_day' => 31])
    );

    // Feb 28 is a Saturday → working-day adjusted to Feb 27 (Friday)
    expect($template->next_invoice_date->toDateString())->toBe('2026-02-27');
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

    // 2027-01-01 is New Year's Day → working-day adjusted to Dec 31 2026
    expect($template->fresh()->next_invoice_date->toDateString())->toBe('2026-12-31');
});

// --- Phase B: working-day-adjusted schedule ---

it('monthly template anchored on sunday fires on preceding friday', function (): void {
    $client = recurringClient();
    // 2026-01-25 is a Sunday; latestWorkingDayOnOrBefore → 2026-01-23 (Friday)
    $template = RecurringInvoice::create([
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Monthly,
        'billing_period_day' => 25,
        'start_date' => '2026-01-25',
        'next_invoice_date' => '2026-01-23',
        'next_period_anchor' => '2026-01-25',
        'status' => RecurringInvoiceStatus::Active,
        'currency' => 'ZAR',
    ]);

    app(RecurringInvoiceService::class)->advanceNextDate($template);

    $fresh = $template->fresh();
    // Anchor advances to Feb 25 (Wednesday) — no drift
    expect($fresh->next_period_anchor->toDateString())->toBe('2026-02-25')
        // Run date is Feb 25 itself (working day)
        ->and($fresh->next_invoice_date->toDateString())->toBe('2026-02-25');
});

it('monthly anchor does not drift when run date differs from anchor', function (): void {
    // Two advances from a Sunday anchor must keep landing on the 25th, not walk backward.
    $client = recurringClient();
    $template = RecurringInvoice::create([
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Monthly,
        'billing_period_day' => 25,
        'start_date' => '2026-01-25',
        'next_invoice_date' => '2026-01-23',
        'next_period_anchor' => '2026-01-25',
        'status' => RecurringInvoiceStatus::Active,
        'currency' => 'ZAR',
    ]);

    $service = app(RecurringInvoiceService::class);
    $service->advanceNextDate($template);
    $service->advanceNextDate($template->fresh());

    // Two months from Jan 25 = Mar 25 (Wednesday)
    expect($template->fresh()->next_period_anchor->toDateString())->toBe('2026-03-25');
});

it('quarterly anchor on public holiday rolls back run date but next quarter is unaffected', function (): void {
    $client = recurringClient();
    // 2026-09-24 is Heritage Day (Thursday) → run date rolls back to Sep 23 (Wednesday)
    $template = RecurringInvoice::create([
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Quarterly,
        'billing_period_day' => 24,
        'start_date' => '2026-09-24',
        'next_invoice_date' => '2026-09-23',
        'next_period_anchor' => '2026-09-24',
        'status' => RecurringInvoiceStatus::Active,
        'currency' => 'ZAR',
    ]);

    app(RecurringInvoiceService::class)->advanceNextDate($template);

    $fresh = $template->fresh();
    // Next anchor = Dec 24 (Thursday, not a holiday) → run date = Dec 24
    expect($fresh->next_period_anchor->toDateString())->toBe('2026-12-24')
        ->and($fresh->next_invoice_date->toDateString())->toBe('2026-12-24');
});

it('weekly template advances by one week and skips public holiday', function (): void {
    $client = recurringClient();
    // Anchor 2026-04-27 = Freedom Day (Monday) → run date = 2026-04-24 (Friday)
    $template = RecurringInvoice::create([
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Weekly,
        'billing_period_day' => null,
        'start_date' => '2026-04-27',
        'next_invoice_date' => '2026-04-24',
        'next_period_anchor' => '2026-04-27',
        'status' => RecurringInvoiceStatus::Active,
        'currency' => 'ZAR',
    ]);

    app(RecurringInvoiceService::class)->advanceNextDate($template);

    $fresh = $template->fresh();
    // Anchor advances to May 4 (Monday, not a holiday) → run date = May 4
    expect($fresh->next_period_anchor->toDateString())->toBe('2026-05-04')
        ->and($fresh->next_invoice_date->toDateString())->toBe('2026-05-04');
});

it('fortnightly template advances by two weeks', function (): void {
    $client = recurringClient();
    // Anchor 2026-04-27 = Freedom Day (Monday) → run date = 2026-04-24 (Friday)
    $template = RecurringInvoice::create([
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Fortnightly,
        'billing_period_day' => null,
        'start_date' => '2026-04-27',
        'next_invoice_date' => '2026-04-24',
        'next_period_anchor' => '2026-04-27',
        'status' => RecurringInvoiceStatus::Active,
        'currency' => 'ZAR',
    ]);

    app(RecurringInvoiceService::class)->advanceNextDate($template);

    $fresh = $template->fresh();
    // Anchor advances to May 11 (Monday, not a holiday) → run date = May 11
    expect($fresh->next_period_anchor->toDateString())->toBe('2026-05-11')
        ->and($fresh->next_invoice_date->toDateString())->toBe('2026-05-11');
});

it('createTemplate sets next_period_anchor alongside next_invoice_date', function (): void {
    Mail::fake();

    $client = recurringClient();
    // billing_period_day=25, start_date on the 25th itself (full period, no pro-rata)
    $template = app(RecurringInvoiceService::class)->createTemplate(
        array_merge(baseTemplateData($client, '2026-01-25'), ['billing_period_day' => 25])
    );

    // 2026-01-25 is a Sunday → run date Jan 23, anchor Jan 25
    expect($template->next_period_anchor->toDateString())->toBe('2026-01-25')
        ->and($template->next_invoice_date->toDateString())->toBe('2026-01-23');
});

it('weekly template created via createTemplate skips pro-rata', function (): void {
    Mail::fake();

    $client = recurringClient();
    $template = app(RecurringInvoiceService::class)->createTemplate([
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Weekly->value,
        'start_date' => '2026-05-15',
        'lines' => [
            ['description' => 'Weekly service', 'account_id' => '', 'quantity' => '1', 'unit_price' => '200', 'discount_percent' => '0', 'tax_rate' => '15'],
        ],
    ]);

    // No pro-rata invoice generated for weekly templates
    expect(Document::where('metadata->recurring_invoice_id', $template->id)->count())->toBe(0);
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

// --- auto_send ---

it('keeps the generated invoice as a draft when auto_send is disabled', function (): void {
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
        'auto_send' => false,
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

    expect($doc->status)->toBe('draft');
    Mail::assertNothingOutgoing();
});

it('emails the generated invoice when auto_send is enabled and a recipient exists', function (): void {
    Mail::fake();

    $client = recurringClient();

    $personParty = app(PartyService::class)->createPerson([
        'first_name' => 'Invoice',
        'last_name' => 'Recipient',
        'email' => 'recurring@example.com',
        'status' => 'active',
    ]);
    $client->assignContact($personParty->person, [
        'role' => 'billing',
        'receives_invoices' => true,
        'is_active' => true,
    ]);

    $template = RecurringInvoice::create([
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Monthly,
        'billing_period_day' => 1,
        'start_date' => '2026-05-01',
        'next_invoice_date' => '2026-05-01',
        'status' => RecurringInvoiceStatus::Active,
        'currency' => 'ZAR',
        'auto_send' => true,
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

    expect($doc->status)->toBe('sent');
    Mail::assertSent(SalesInvoiceMail::class, fn ($m) => $m->hasTo('recurring@example.com'));
});

// --- Pause / activate ---

it('pauses an active template and reactivates it', function (): void {
    $this->actingAs(riUserWith(['recurring-invoices-view-any', 'recurring-invoices-update']));

    $client = recurringClient();
    $template = RecurringInvoice::create([
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Monthly,
        'billing_period_day' => 1,
        'start_date' => '2026-05-01',
        'next_invoice_date' => '2026-06-01',
        'status' => RecurringInvoiceStatus::Active,
        'currency' => 'ZAR',
    ]);

    Volt::test('pages.recurring-invoices.index')
        ->call('pause', $template->id)
        ->assertOk();

    expect($template->fresh()->status)->toBe(RecurringInvoiceStatus::Paused);

    Volt::test('pages.recurring-invoices.index')
        ->call('activate', $template->id)
        ->assertOk();

    expect($template->fresh()->status)->toBe(RecurringInvoiceStatus::Active);
});

it('forbids pause and activate without update permission', function (): void {
    $this->actingAs(riUserWith(['recurring-invoices-view-any']));

    $client = recurringClient();
    $template = RecurringInvoice::create([
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Monthly,
        'billing_period_day' => 1,
        'start_date' => '2026-05-01',
        'next_invoice_date' => '2026-06-01',
        'status' => RecurringInvoiceStatus::Active,
        'currency' => 'ZAR',
    ]);

    Volt::test('pages.recurring-invoices.index')
        ->call('pause', $template->id)
        ->assertForbidden();

    expect($template->fresh()->status)->toBe(RecurringInvoiceStatus::Active);

    $template->update(['status' => RecurringInvoiceStatus::Paused]);

    Volt::test('pages.recurring-invoices.index')
        ->call('activate', $template->id)
        ->assertForbidden();

    expect($template->fresh()->status)->toBe(RecurringInvoiceStatus::Paused);
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

    // 2026-05-01 is Workers' Day → working-day adjusted to Apr 30 (Thursday)
    expect(Document::salesInvoices()->count())->toBe(1)
        ->and($template->fresh()->next_invoice_date->toDateString())->toBe('2026-04-30');
});

it('command run twice on the same day generates only one invoice', function (): void {
    Mail::fake();

    // Due exactly on the frozen "today" (2026-05-15): the first run generates
    // and advances to 2026-06-15; the second run must find nothing due.
    $client = recurringClient();
    $template = RecurringInvoice::create([
        'client_id' => $client->id,
        'frequency' => RecurringFrequency::Monthly,
        'billing_period_day' => 15,
        'start_date' => '2026-04-15',
        'next_invoice_date' => '2026-05-15',
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
    $this->artisan('billing:generate-recurring')->assertExitCode(0);

    expect(Document::salesInvoices()->count())->toBe(1)
        ->and($template->fresh()->next_invoice_date->toDateString())->toBe('2026-06-15');
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
