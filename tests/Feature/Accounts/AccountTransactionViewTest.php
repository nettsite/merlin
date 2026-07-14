<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Services\BillingService;
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
