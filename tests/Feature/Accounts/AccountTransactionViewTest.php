<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;
use App\Modules\Core\Services\PartyService;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

function atvUser(): User
{
    $user = User::factory()->create();
    foreach (['accounts-view-any', 'accounts-view'] as $perm) {
        Permission::findOrCreate($perm, 'web');
        $user->givePermissionTo($perm);
    }

    return $user;
}

it('shows a sales invoice header posting for a client receivable account', function (): void {
    $receivableAccount = Account::factory()->create();

    $client = app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'AR Ledger Client',
        'trading_name' => 'AR Ledger Client',
        'status' => 'active',
    ], ['client']);

    $invoice = app(BillingService::class)->createDraft($client, ['issue_date' => now()->toDateString()]);
    $invoice->update(['receivable_account_id' => $receivableAccount->id]);

    $invoice->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Consulting',
        'quantity' => 1,
        'unit_price' => 750.00,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_rate' => 0,
    ]);
    $invoice->recalculateTotals();
    app(DocumentService::class)->markAsSent($invoice, User::factory()->create());

    $this->actingAs(atvUser());

    Livewire::test('pages.accounts.show', ['id' => $receivableAccount->id])
        ->assertOk()
        ->assertSee($invoice->fresh()->document_number)
        ->assertSee('750.00');
});

it('shows a payment posting on both the receivable and bank account ledgers', function (): void {
    $receivableAccount = Account::factory()->create();
    $bankAccount = Account::factory()->create();

    $client = app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Payment Ledger Client',
        'trading_name' => 'Payment Ledger Client',
        'status' => 'active',
    ], ['client']);

    $invoice = app(BillingService::class)->createDraft($client, ['issue_date' => now()->toDateString()]);
    $invoice->update(['receivable_account_id' => $receivableAccount->id]);
    $invoice->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Consulting',
        'quantity' => 1,
        'unit_price' => 300.00,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_rate' => 0,
    ]);
    $invoice->recalculateTotals();
    app(DocumentService::class)->markAsSent($invoice, User::factory()->create());

    app(BillingService::class)->recordPayment($invoice->fresh(), [
        'amount' => 300.00,
        'date' => now()->toDateString(),
        'contra_account_id' => $bankAccount->id,
    ], null);

    $this->actingAs(atvUser());

    Livewire::test('pages.accounts.show', ['id' => $receivableAccount->id])
        ->assertOk()
        ->assertSee('Payment received');

    Livewire::test('pages.accounts.show', ['id' => $bankAccount->id])
        ->assertOk()
        ->assertSee('Payment');
});

it('shows the contra account for a purchase invoice line and filters/sorts by it', function (): void {
    $expenseAccount = Account::factory()->create();
    $payableAccount = Account::factory()->create(['code' => 'AP-01', 'name' => 'Trade Creditors']);
    $otherPayableAccount = Account::factory()->create(['code' => 'AP-02', 'name' => 'Other Creditors']);

    $invoice = Document::factory()->purchaseInvoice()->create([
        'payable_account_id' => $payableAccount->id,
        'issue_date' => now()->toDateString(),
    ]);
    $invoice->lines()->create([
        'line_number' => 1,
        'type' => 'expense',
        'description' => 'Office supplies',
        'quantity' => 1,
        'unit_price' => 200.00,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_rate' => 0,
        'account_id' => $expenseAccount->id,
    ]);
    $invoice->recalculateTotals();

    $this->actingAs(atvUser());

    Livewire::test('pages.accounts.show', ['id' => $expenseAccount->id])
        ->assertOk()
        ->assertSee('Trade Creditors')
        // The contra-account dropdown only offers accounts present in this account's own
        // transactions — never the full chart of accounts.
        ->assertDontSee('Other Creditors')
        ->set('contraAccountId', $otherPayableAccount->id)
        ->assertDontSee('Office supplies')
        ->set('contraAccountId', $payableAccount->id)
        ->assertSee('Office supplies')
        ->call('sort', 'contra_account')
        ->assertSet('sortBy', 'contra_account');
});

it('excludes transactions outside the selected date range', function (): void {
    $expenseAccount = Account::factory()->create();

    $oldInvoice = Document::factory()->purchaseInvoice()->create([
        'issue_date' => now()->subYears(3)->toDateString(),
    ]);
    $oldInvoice->lines()->create([
        'line_number' => 1,
        'type' => 'expense',
        'description' => 'Ancient expense',
        'quantity' => 1,
        'unit_price' => 50.00,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_rate' => 0,
        'account_id' => $expenseAccount->id,
    ]);
    $oldInvoice->recalculateTotals();

    $this->actingAs(atvUser());

    // Default date range is the current financial year, so the old invoice is excluded.
    Livewire::test('pages.accounts.show', ['id' => $expenseAccount->id])
        ->assertOk()
        ->assertDontSee('Ancient expense')
        ->set('dateFrom', $oldInvoice->issue_date->toDateString())
        ->set('dateTo', $oldInvoice->issue_date->toDateString())
        ->assertSee('Ancient expense');
});
