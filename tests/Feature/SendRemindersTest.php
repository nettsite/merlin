<?php

use App\Modules\Billing\Models\BillingEmailTemplate;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Services\PartyService;
use App\Modules\Purchasing\Models\Document;
use Carbon\Carbon;

// ── Helpers ──────────────────────────────────────────────────────────────────

function reminderClient(): Party
{
    return app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Reminder Client Ltd',
        'status' => 'active',
    ], ['client']);
}

function openInvoiceWithDueDate(Party $client, string $dueDate): Document
{
    return Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'sent',
        'party_id' => $client->id,
        'issue_date' => now()->toDateString(),
        'due_date' => $dueDate,
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'total' => 1000.00,
        'balance_due' => 1000.00,
        'source' => 'manual',
    ]);
}

function reminderTemplate(int $offsetDays, string $name = 'Test Reminder'): BillingEmailTemplate
{
    return BillingEmailTemplate::create([
        'type' => 'reminder',
        'name' => $name,
        'subject' => 'Reminder: {{invoice_number}}',
        'body' => 'Your invoice is due.',
        'offset_days' => $offsetDays,
        'enabled' => true,
    ]);
}

// ── Tests ─────────────────────────────────────────────────────────────────────

it('fires the -3 offset on the correct date when a public holiday sits in the window', function (): void {
    // Easter 2026: Good Friday = Apr 3, Family Day (Easter Mon) = Apr 6.
    // Freeze today to Wednesday Apr 1 2026.
    // addBusinessDays(Apr 8, -3) should walk:
    //   -1 = Apr 7 (Tue), -2 = Apr 2 (Thu→skip Fri Apr3/Sat/Sun/Mon Apr6), -3 = Apr 1
    // So invoice due Apr 8 fires on Apr 1.
    Carbon::setTestNow(Carbon::parse('2026-04-01'));
    reminderTemplate(-3);

    $client = reminderClient();
    openInvoiceWithDueDate($client, '2026-04-08');

    $this->artisan('billing:send-reminders --dry-run')
        ->assertExitCode(0)
        ->expectsOutputToContain('1 invoice(s)');
})->afterEach(function (): void {
    BillingEmailTemplate::where('type', 'reminder')->delete();
    Carbon::setTestNow();
});

it('does not fire for a due date that does not match the -3 offset', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01'));
    reminderTemplate(-3);

    $client = reminderClient();
    openInvoiceWithDueDate($client, '2026-04-07'); // wrong date — addBusinessDays(Apr 7, -3) != Apr 1

    // Command exits 0 without printing any invoice lines (no offset match).
    $this->artisan('billing:send-reminders --dry-run')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('invoice(s)');
})->afterEach(function (): void {
    BillingEmailTemplate::where('type', 'reminder')->delete();
    Carbon::setTestNow();
});

it('skips paid invoices', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01'));
    reminderTemplate(-3);

    $client = reminderClient();
    Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'paid',
        'party_id' => $client->id,
        'issue_date' => now()->toDateString(),
        'due_date' => '2026-04-08',
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'total' => 1000.00,
        'balance_due' => 0.00,
        'source' => 'manual',
    ]);

    $this->artisan('billing:send-reminders --dry-run')
        ->assertExitCode(0)
        ->expectsOutputToContain('No open invoices');
})->afterEach(function (): void {
    BillingEmailTemplate::where('type', 'reminder')->delete();
    Carbon::setTestNow();
});

it('skips invoices with zero balance_due even when status is sent', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-01'));
    reminderTemplate(-3);

    $client = reminderClient();
    Document::create([
        'document_type' => 'sales_invoice',
        'direction' => 'outbound',
        'status' => 'sent',
        'party_id' => $client->id,
        'issue_date' => now()->toDateString(),
        'due_date' => '2026-04-08',
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'total' => 1000.00,
        'balance_due' => 0.00,
        'source' => 'manual',
    ]);

    $this->artisan('billing:send-reminders --dry-run')
        ->assertExitCode(0)
        ->expectsOutputToContain('No open invoices');
})->afterEach(function (): void {
    BillingEmailTemplate::where('type', 'reminder')->delete();
    Carbon::setTestNow();
});

it('fires the +7 overdue reminder on the correct date', function (): void {
    // Freeze today to Apr 16 (Thu). addBusinessDays(Apr 7 Tue, +7):
    // +1=Apr8, +2=Apr9, +3=Apr10, +4=Apr13, +5=Apr14, +6=Apr15, +7=Apr16 — matches today.
    Carbon::setTestNow(Carbon::parse('2026-04-16'));
    reminderTemplate(7);

    $client = reminderClient();
    openInvoiceWithDueDate($client, '2026-04-07');

    $this->artisan('billing:send-reminders --dry-run')
        ->assertExitCode(0)
        ->expectsOutputToContain('1 invoice(s)');
})->afterEach(function (): void {
    BillingEmailTemplate::where('type', 'reminder')->delete();
    Carbon::setTestNow();
});

it('reports no enabled reminder templates when none are configured', function (): void {
    BillingEmailTemplate::where('type', 'reminder')->delete();

    $this->artisan('billing:send-reminders')
        ->assertExitCode(0)
        ->expectsOutputToContain('No enabled reminder templates configured');
});
