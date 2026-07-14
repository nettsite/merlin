<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\PartyRelationship;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;
use App\Modules\Core\Services\PartyService;

function bfClient(string $name, string $receivableAccountId)
{
    $party = app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => $name,
        'trading_name' => $name,
        'status' => 'active',
    ], ['client']);

    $rel = $party->relationships->firstWhere('relationship_type', 'client');
    // Simulate the old behaviour: every client points straight at the shared control account.
    $rel->mergeMetadata(['default_receivable_account_id' => $receivableAccountId]);

    return $party;
}

it('fails without a configured control account', function (): void {
    $this->artisan('accounts:backfill-client-receivables')->assertFailed();
});

it('migrates clients still pointing at the control account and repoints their invoices', function (): void {
    $control = Account::factory()->create();
    $settings = app(BillingSettings::class);
    $settings->default_receivable_account_id = $control->id;
    $settings->save();

    $client = bfClient('Backfill Client', $control->id);

    $invoice = app(BillingService::class)->createDraft($client, ['issue_date' => now()->toDateString()]);
    $invoice->update(['receivable_account_id' => $control->id]);
    $invoice->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Consulting',
        'quantity' => 1,
        'unit_price' => 100.00,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_rate' => 0,
    ]);
    $invoice->recalculateTotals();
    app(DocumentService::class)->markAsSent($invoice, User::factory()->create());

    $this->artisan('accounts:backfill-client-receivables')->assertSuccessful();

    $rel = PartyRelationship::where('party_id', $client->id)->where('relationship_type', 'client')->first();
    $accountId = $rel->metadata['default_receivable_account_id'];

    expect($accountId)->not->toBe($control->id);
    expect(Account::find($accountId)->parent_id)->toBe($control->id);
    expect($invoice->fresh()->receivable_account_id)->toBe($accountId);
});

it('is idempotent — a second run makes no further changes', function (): void {
    $control = Account::factory()->create();
    $settings = app(BillingSettings::class);
    $settings->default_receivable_account_id = $control->id;
    $settings->save();

    bfClient('Idempotent Client', $control->id);

    $this->artisan('accounts:backfill-client-receivables')->assertSuccessful();
    $accountCountAfterFirst = Account::count();

    $this->artisan('accounts:backfill-client-receivables')->assertSuccessful();
    $accountCountAfterSecond = Account::count();

    expect($accountCountAfterSecond)->toBe($accountCountAfterFirst);
});
