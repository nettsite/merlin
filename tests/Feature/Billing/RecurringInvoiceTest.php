<?php

namespace Tests\Feature\Billing;

use App\Modules\Billing\Enums\RecurringFrequency;
use App\Modules\Billing\Enums\RecurringInvoiceStatus;
use App\Modules\Billing\Models\RecurringInvoice;
use App\Modules\Billing\Services\RecurringInvoiceService;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
use App\Modules\Purchasing\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Volt\Volt;
use Tests\TestCase;

class RecurringInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function makeClient(): Party
    {
        return app(PartyService::class)->createBusiness([
            'business_type' => 'company',
            'legal_name' => 'Recurring Client Ltd',
            'status' => 'active',
        ], ['client']);
    }

    private function baseTemplateData(Party $client, string $startDate = '2026-05-01'): array
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

    // -------------------------------------------------------------------------
    // Page access
    // -------------------------------------------------------------------------

    public function test_unauthenticated_redirected_to_login(): void
    {
        $this->get('/recurring-invoices')->assertRedirect('/login');
    }

    public function test_page_renders(): void
    {
        $this->actingAs($this->userWith(['recurring-invoices-view-any']));

        Volt::test('pages.recurring-invoices.index')
            ->assertOk()
            ->assertSee('Recurring Invoices');
    }

    // -------------------------------------------------------------------------
    // createTemplate
    // -------------------------------------------------------------------------

    public function test_create_template_on_billing_day_no_pro_rata(): void
    {
        Mail::fake();

        $client = $this->makeClient();

        $template = app(RecurringInvoiceService::class)->createTemplate(
            $this->baseTemplateData($client, '2026-06-01')
        );

        $this->assertEquals('2026-06-01', $template->next_invoice_date->toDateString());

        // No invoice generated (full period)
        $this->assertEquals(
            0,
            Document::where('metadata->recurring_invoice_id', $template->id)->count()
        );
    }

    public function test_create_template_mid_period_generates_pro_rata_invoice(): void
    {
        Mail::fake();

        $client = $this->makeClient();

        $template = app(RecurringInvoiceService::class)->createTemplate(
            $this->baseTemplateData($client, '2026-05-15')
        );

        // next_invoice_date advanced to June 1 (next billing day)
        $this->assertEquals('2026-06-01', $template->fresh()->next_invoice_date->toDateString());

        // Pro rata invoice created
        $doc = Document::where('metadata->recurring_invoice_id', $template->id)->first();
        $this->assertNotNull($doc);
        $this->assertEquals('2026-05-15', $doc->issue_date->toDateString());

        // Line description has pro rata suffix
        $this->assertStringContainsString('pro rata', $doc->lines->first()?->description ?? '');
    }

    public function test_create_template_stores_lines(): void
    {
        Mail::fake();

        $client = $this->makeClient();
        $template = app(RecurringInvoiceService::class)->createTemplate(
            $this->baseTemplateData($client)
        );

        $this->assertCount(1, $template->lines);
        $this->assertEquals('Monthly service fee', $template->lines->first()->description);
    }

    // -------------------------------------------------------------------------
    // generateFromTemplate
    // -------------------------------------------------------------------------

    public function test_generate_from_template_creates_invoice(): void
    {
        Mail::fake();

        $client = $this->makeClient();
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

        $this->assertInstanceOf(Document::class, $doc);
        $this->assertEquals('sales_invoice', $doc->document_type);
        $this->assertEquals($template->id, $doc->metadata['recurring_invoice_id']);
    }

    public function test_generate_pro_rata_adjusts_quantity(): void
    {
        Mail::fake();

        $client = $this->makeClient();
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
        $this->assertLessThan(1.0, (float) $line->quantity);
        $this->assertStringContainsString('pro rata', $line->description);
    }

    // -------------------------------------------------------------------------
    // advanceNextDate
    // -------------------------------------------------------------------------

    public function test_advance_next_date_monthly(): void
    {
        $client = $this->makeClient();
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

        $this->assertEquals('2026-06-01', $template->fresh()->next_invoice_date->toDateString());
    }

    public function test_advance_next_date_quarterly(): void
    {
        $client = $this->makeClient();
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

        $this->assertEquals('2026-04-01', $template->fresh()->next_invoice_date->toDateString());
    }

    public function test_advance_next_date_annually(): void
    {
        $client = $this->makeClient();
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

        $this->assertEquals('2027-01-01', $template->fresh()->next_invoice_date->toDateString());
    }

    // -------------------------------------------------------------------------
    // completeIfExpired
    // -------------------------------------------------------------------------

    public function test_complete_if_expired_marks_completed_after_end_date(): void
    {
        $client = $this->makeClient();
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

        $this->assertEquals(RecurringInvoiceStatus::Completed, $template->fresh()->status);
    }

    public function test_complete_if_expired_does_nothing_if_no_end_date(): void
    {
        $client = $this->makeClient();
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

        $this->assertEquals(RecurringInvoiceStatus::Active, $template->fresh()->status);
    }

    // -------------------------------------------------------------------------
    // Artisan command
    // -------------------------------------------------------------------------

    public function test_artisan_command_dry_run_does_not_generate(): void
    {
        Mail::fake();

        $client = $this->makeClient();
        RecurringInvoice::create([
            'client_id' => $client->id,
            'frequency' => RecurringFrequency::Monthly,
            'billing_period_day' => 1,
            'start_date' => '2026-04-01',
            'next_invoice_date' => '2026-04-01',
            'status' => RecurringInvoiceStatus::Active,
            'currency' => 'ZAR',
        ]);

        $this->artisan('billing:generate-recurring --dry-run')
            ->assertExitCode(0);

        $this->assertEquals(0, Document::salesInvoices()->count());
    }

    public function test_artisan_command_generates_due_invoices(): void
    {
        Mail::fake();

        $client = $this->makeClient();
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

        $this->artisan('billing:generate-recurring')
            ->assertExitCode(0);

        $this->assertEquals(1, Document::salesInvoices()->count());
        // next_invoice_date advanced
        $this->assertEquals('2026-05-01', $template->fresh()->next_invoice_date->toDateString());
    }

    public function test_artisan_command_skips_non_due_templates(): void
    {
        Mail::fake();

        $client = $this->makeClient();
        RecurringInvoice::create([
            'client_id' => $client->id,
            'frequency' => RecurringFrequency::Monthly,
            'billing_period_day' => 1,
            'start_date' => '2026-06-01',
            'next_invoice_date' => '2026-06-01', // future
            'status' => RecurringInvoiceStatus::Active,
            'currency' => 'ZAR',
        ]);

        $this->artisan('billing:generate-recurring')
            ->expectsOutput('No recurring invoices due.')
            ->assertExitCode(0);

        $this->assertEquals(0, Document::salesInvoices()->count());
    }
}
