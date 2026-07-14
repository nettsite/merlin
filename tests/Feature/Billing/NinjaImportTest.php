<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountGroup;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Billing\Enums\RecurringFrequency;
use App\Modules\Billing\Enums\RecurringInvoiceStatus;
use App\Modules\Billing\Models\RecurringInvoice;
use App\Modules\Billing\Settings\BillingSettings;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\PartyRelationship;
use Illuminate\Support\Facades\DB;

// -------------------------------------------------------------------------
// Test infrastructure
// -------------------------------------------------------------------------

function ninjaEnvDir(): string
{
    static $dir = null;

    if ($dir === null) {
        $dir = sys_get_temp_dir().'/merlin-ninja-test-'.getmypid();
        @mkdir($dir, 0755, true);
    }

    return $dir;
}

function ninjaDbPath(): string
{
    return ninjaEnvDir().'/ninja.sqlite';
}

function writeNinjaEnv(): void
{
    file_put_contents(
        ninjaEnvDir().'/.env',
        "DB_CONNECTION=sqlite\nDB_DATABASE=".ninjaDbPath()."\n",
    );
}

function createNinjaSchema(): void
{
    touch(ninjaDbPath());

    DB::purge('ninja');

    config(['database.connections.ninja' => [
        'driver' => 'sqlite',
        'database' => ninjaDbPath(),
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]]);

    $ninja = DB::connection('ninja');

    $ninja->statement('CREATE TABLE IF NOT EXISTS clients (
        id INTEGER PRIMARY KEY,
        name TEXT,
        phone TEXT,
        website TEXT,
        address1 TEXT, address2 TEXT, city TEXT, state TEXT, postal_code TEXT, country_id INTEGER DEFAULT 0,
        shipping_address1 TEXT, shipping_address2 TEXT, shipping_city TEXT, shipping_state TEXT,
        shipping_postal_code TEXT, shipping_country_id INTEGER DEFAULT 0,
        vat_number TEXT, id_number TEXT, settings TEXT DEFAULT "{}",
        is_deleted INTEGER DEFAULT 0
    )');

    $ninja->statement('CREATE TABLE IF NOT EXISTS client_contacts (
        id INTEGER PRIMARY KEY,
        client_id INTEGER,
        first_name TEXT, last_name TEXT, email TEXT,
        phone TEXT, is_primary INTEGER DEFAULT 0, send_email INTEGER DEFAULT 1,
        deleted_at TEXT DEFAULT NULL
    )');

    $ninja->statement('CREATE TABLE IF NOT EXISTS invoices (
        id INTEGER PRIMARY KEY,
        number TEXT, client_id INTEGER, status_id INTEGER,
        date TEXT, due_date TEXT,
        amount REAL DEFAULT 0, balance REAL DEFAULT 0,
        line_items TEXT DEFAULT "[]",
        public_notes TEXT, terms TEXT, footer TEXT,
        uses_inclusive_taxes INTEGER DEFAULT 0,
        tax_name1 TEXT, tax_rate1 REAL DEFAULT 0,
        discount REAL DEFAULT 0, is_amount_discount INTEGER DEFAULT 0,
        is_deleted INTEGER DEFAULT 0
    )');

    $ninja->statement('CREATE TABLE IF NOT EXISTS payments (
        id INTEGER PRIMARY KEY,
        number TEXT, client_id INTEGER, status_id INTEGER DEFAULT 4,
        amount REAL DEFAULT 0, date TEXT, transaction_reference TEXT,
        is_deleted INTEGER DEFAULT 0
    )');

    $ninja->statement('CREATE TABLE IF NOT EXISTS paymentables (
        id INTEGER PRIMARY KEY,
        payment_id INTEGER, paymentable_id INTEGER,
        paymentable_type TEXT, amount REAL DEFAULT 0, refunded REAL DEFAULT 0,
        deleted_at TEXT DEFAULT NULL
    )');

    $ninja->statement('CREATE TABLE IF NOT EXISTS quotes (
        id INTEGER PRIMARY KEY,
        number TEXT, client_id INTEGER, status_id INTEGER DEFAULT 1,
        date TEXT, due_date TEXT,
        amount REAL DEFAULT 0, balance REAL DEFAULT 0,
        line_items TEXT DEFAULT "[]",
        public_notes TEXT, terms TEXT, footer TEXT,
        uses_inclusive_taxes INTEGER DEFAULT 0,
        is_deleted INTEGER DEFAULT 0
    )');

    $ninja->statement('CREATE TABLE IF NOT EXISTS credits (
        id INTEGER PRIMARY KEY,
        number TEXT, client_id INTEGER, status_id INTEGER DEFAULT 1,
        date TEXT, due_date TEXT,
        amount REAL DEFAULT 0, balance REAL DEFAULT 0,
        line_items TEXT DEFAULT "[]",
        public_notes TEXT, terms TEXT, footer TEXT,
        uses_inclusive_taxes INTEGER DEFAULT 0,
        is_deleted INTEGER DEFAULT 0
    )');

    $ninja->statement('CREATE TABLE IF NOT EXISTS recurring_invoices (
        id INTEGER PRIMARY KEY,
        client_id INTEGER, status_id INTEGER DEFAULT 2, frequency_id INTEGER DEFAULT 5,
        date TEXT, next_send_date TEXT,
        line_items TEXT DEFAULT "[]",
        public_notes TEXT, terms TEXT, footer TEXT,
        is_deleted INTEGER DEFAULT 0
    )');
}

function seedNinjaFixture(): void
{
    $ninja = DB::connection('ninja');

    $ninja->table('clients')->insert([
        'id' => 1,
        'name' => 'Acme Corp',
        'phone' => '021 555 0100',
        'city' => 'Cape Town',
        'country_id' => 710,
        'is_deleted' => 0,
        'settings' => '{"currency_id":3}',
    ]);

    $ninja->table('client_contacts')->insert([
        'id' => 1,
        'client_id' => 1,
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'email' => 'jane@acme.co.za',
        'is_primary' => 1,
        'send_email' => 1,
    ]);

    $lineItems = json_encode([
        ['product_key' => 'DEV', 'notes' => 'Development', 'cost' => 1000.00, 'quantity' => 2, 'tax_rate1' => 15],
        ['product_key' => 'SUP', 'notes' => 'Support',     'cost' => 500.00,  'quantity' => 1, 'tax_rate1' => 0],
    ]);

    $ninja->table('invoices')->insert([
        'id' => 101,
        'number' => 'INV-0042',
        'client_id' => 1,
        'status_id' => 4,
        'date' => '2025-01-15',
        'due_date' => '2025-02-15',
        'amount' => 2800.00,
        'balance' => 0,
        'line_items' => $lineItems,
        'uses_inclusive_taxes' => 0,
        'is_deleted' => 0,
    ]);

    // Ninja invoice-level (amount) discount — not reflected in line_items,
    // must be applied on import or the line total overstates the true amount.
    $ninja->table('invoices')->insert([
        'id' => 102,
        'number' => 'INV-0043',
        'client_id' => 1,
        'status_id' => 4,
        'date' => '2025-01-16',
        'due_date' => '2025-02-16',
        'amount' => 65.00,
        'balance' => 0,
        'line_items' => json_encode([
            ['product_key' => 'co.za', 'notes' => 'Domain renewal', 'cost' => 120.00, 'quantity' => 1, 'tax_rate1' => 0],
        ]),
        'uses_inclusive_taxes' => 0,
        'discount' => 55.00,
        'is_amount_discount' => 1,
        'is_deleted' => 0,
    ]);

    // Per-line (percentage) discounts — separate from the header discount above.
    $ninja->table('invoices')->insert([
        'id' => 103,
        'number' => 'INV-0044',
        'client_id' => 1,
        'status_id' => 2,
        'date' => '2025-01-17',
        'due_date' => '2025-02-17',
        'amount' => 3187.50,
        'balance' => 3187.50,
        'line_items' => json_encode([
            ['product_key' => '', 'notes' => 'VPS rental', 'cost' => 1500.00, 'quantity' => 1, 'discount' => 0, 'is_amount_discount' => false, 'tax_rate1' => 0],
            ['product_key' => '', 'notes' => 'Install',    'cost' => 450.00,  'quantity' => 4, 'discount' => 25, 'is_amount_discount' => false, 'tax_rate1' => 0],
            ['product_key' => '', 'notes' => 'Submit',     'cost' => 450.00,  'quantity' => 1, 'discount' => 25, 'is_amount_discount' => false, 'tax_rate1' => 0],
        ]),
        'uses_inclusive_taxes' => 0,
        'discount' => 0,
        'is_amount_discount' => 0,
        'is_deleted' => 0,
    ]);

    $ninja->table('payments')->insert([
        'id' => 201,
        'number' => 'PAY-0201',
        'client_id' => 1,
        'status_id' => 4,
        'amount' => 2800.00,
        'date' => '2025-02-10',
        'transaction_reference' => 'EFT-123',
        'is_deleted' => 0,
    ]);

    $ninja->table('paymentables')->insert([
        'id' => 1,
        'payment_id' => 201,
        'paymentable_id' => 101,
        'paymentable_type' => 'invoices',
        'amount' => 2800.00,
        'refunded' => 0,
    ]);

    // Ninja's "apply credit" payment type: header amount is 0, but the
    // allocation to the invoice is real (type_id=32 in real Ninja data).
    $ninja->table('payments')->insert([
        'id' => 202,
        'number' => '0866',
        'client_id' => 1,
        'status_id' => 4,
        'amount' => 0.00,
        'date' => '2025-02-11',
        'transaction_reference' => null,
        'is_deleted' => 0,
    ]);

    $ninja->table('paymentables')->insert([
        'id' => 2,
        'payment_id' => 202,
        'paymentable_id' => 103,
        'paymentable_type' => 'invoices',
        'amount' => 3187.50,
        'refunded' => 0,
    ]);

    // A rounding-driven overpayment allocation (100.50 against a 100.00
    // invoice) — must be capped at balance_due, and the payment document's
    // total must reflect what was actually applied (100.00), not 100.50.
    $ninja->table('invoices')->insert([
        'id' => 104,
        'number' => 'INV-0045',
        'client_id' => 1,
        'status_id' => 2,
        'date' => '2025-01-18',
        'due_date' => '2025-02-18',
        'amount' => 100.00,
        'balance' => 0,
        'line_items' => json_encode([
            ['product_key' => '', 'notes' => 'Hosting', 'cost' => 100.00, 'quantity' => 1, 'tax_rate1' => 0],
        ]),
        'uses_inclusive_taxes' => 0,
        'discount' => 0,
        'is_amount_discount' => 0,
        'is_deleted' => 0,
    ]);

    $ninja->table('payments')->insert([
        'id' => 203,
        'number' => 'PAY-0203',
        'client_id' => 1,
        'status_id' => 4,
        'amount' => 100.50,
        'date' => '2025-02-12',
        'transaction_reference' => null,
        'is_deleted' => 0,
    ]);

    $ninja->table('paymentables')->insert([
        'id' => 3,
        'payment_id' => 203,
        'paymentable_id' => 104,
        'paymentable_type' => 'invoices',
        'amount' => 100.50,
        'refunded' => 0,
    ]);

    $ninja->table('recurring_invoices')->insert([
        'id' => 301,
        'client_id' => 1,
        'status_id' => 2,
        'frequency_id' => 5,
        'date' => '2025-01-01',
        'next_send_date' => '2025-02-01',
        'line_items' => json_encode([
            ['product_key' => 'RET', 'notes' => 'Monthly retainer', 'cost' => 3000.00, 'quantity' => 1, 'tax_rate1' => 15],
        ]),
        'is_deleted' => 0,
    ]);
}

function ninjaRequiredAccounts(): void
{
    $type = AccountType::firstOrCreate(
        ['code' => '1'],
        ['name' => 'Assets', 'normal_balance' => 'debit'],
    );

    $incomeType = AccountType::firstOrCreate(
        ['code' => '4'],
        ['name' => 'Income', 'normal_balance' => 'credit'],
    );

    $assetGroup = AccountGroup::firstOrCreate(
        ['code' => '10'],
        ['name' => 'Current Assets', 'account_type_id' => $type->id],
    );

    $incomeGroup = AccountGroup::firstOrCreate(
        ['code' => '40'],
        ['name' => 'Revenue', 'account_type_id' => $incomeType->id],
    );

    foreach ([
        ['code' => '1000', 'name' => 'Bank — Operating Account', 'account_group_id' => $assetGroup->id],
        ['code' => '1100', 'name' => 'Accounts Receivable',      'account_group_id' => $assetGroup->id],
        ['code' => '4000', 'name' => 'Sales Revenue',            'account_group_id' => $incomeGroup->id],
    ] as $acct) {
        Account::firstOrCreate(['code' => $acct['code']], $acct);
    }

    // ClientReceivableAccountService creates per-client sub-accounts as
    // children of whichever account is configured here.
    $settings = app(BillingSettings::class);
    $settings->default_receivable_account_id = Account::where('code', '1100')->value('id');
    $settings->save();
}

// -------------------------------------------------------------------------
// Tests
// -------------------------------------------------------------------------

beforeEach(function (): void {
    writeNinjaEnv();
    createNinjaSchema();
    seedNinjaFixture();
    ninjaRequiredAccounts();
});

afterEach(function (): void {
    @unlink(ninjaDbPath());
});

it('imports client as an active business party with client relationship', function (): void {
    $this->artisan('ninja:import', ['path' => ninjaEnvDir()])->assertSuccessful();

    $rel = PartyRelationship::query()
        ->where('relationship_type', 'client')
        ->whereJsonContains('metadata->ninja_id', 1)
        ->first();

    expect($rel)->not->toBeNull();

    $party = Party::find($rel->party_id);
    expect($party)->not->toBeNull();
    expect($party->status)->toBe('active');
    expect($party->business->legal_name)->toBe('Acme Corp');

    $clientAccountId = $rel->metadata['default_receivable_account_id'] ?? null;
    expect($clientAccountId)->not->toBeNull();

    $clientAccount = Account::find($clientAccountId);
    expect($clientAccount->parent_id)->toBe(Account::where('code', '1100')->value('id'));
});

it('imports contact with correct ContactAssignment', function (): void {
    $this->artisan('ninja:import', ['path' => ninjaEnvDir()])->assertSuccessful();

    $rel = PartyRelationship::where('relationship_type', 'client')
        ->whereJsonContains('metadata->ninja_id', 1)->first();

    $assignment = $rel->contactAssignments()->first();

    expect($assignment)->not->toBeNull();
    expect($assignment->person->email)->toBe('jane@acme.co.za');
    expect($assignment->is_primary)->toBeTrue();
    expect($assignment->receives_invoices)->toBeTrue();
});

it('imports invoice with correct document number, lines and totals', function (): void {
    $this->artisan('ninja:import', ['path' => ninjaEnvDir()])->assertSuccessful();

    $doc = Document::query()
        ->where('document_type', 'sales_invoice')
        ->whereJsonContains('metadata->ninja_id', 101)
        ->first();

    expect($doc)->not->toBeNull();
    expect($doc->document_number)->toBe('INV-0042');
    expect($doc->lines)->toHaveCount(2);

    // line 1: 2 × 1000 ex-VAT, 15% tax → line_total=2000, tax=300
    $l1 = $doc->lines->firstWhere('line_number', 1);
    expect((float) $l1->line_total)->toBe(2000.0);
    expect((float) $l1->tax_amount)->toBe(300.0);

    // line 2: 1 × 500, 0% tax
    $l2 = $doc->lines->firstWhere('line_number', 2);
    expect((float) $l2->line_total)->toBe(500.0);
    expect((float) $l2->tax_amount)->toBe(0.0);

    // Document totals: subtotal=2500, tax=300, total=2800
    expect((float) $doc->subtotal)->toBe(2500.0);
    expect((float) $doc->tax_total)->toBe(300.0);
    expect((float) $doc->total)->toBe(2800.0);

    // Line account set to revenue
    expect($l1->account_id)->toBe(Account::where('code', '4000')->value('id'));
});

it('applies a Ninja header-level (amount) discount so the invoice total matches Ninja, not the raw line sum', function (): void {
    $this->artisan('ninja:import', ['path' => ninjaEnvDir()])->assertSuccessful();

    $doc = Document::query()
        ->where('document_type', 'sales_invoice')
        ->whereJsonContains('metadata->ninja_id', 102)
        ->first();

    expect($doc)->not->toBeNull();

    $line = $doc->lines->firstWhere('line_number', 1);
    expect((float) $line->unit_price)->toBe(120.0)
        ->and((float) $line->discount_amount)->toBe(55.0)
        ->and((float) $line->line_total)->toBe(65.0);

    // The line item alone sums to 120 — the header discount must bring the
    // document total down to Ninja's true amount of 65, not 120.
    expect((float) $doc->total)->toBe(65.0);
});

it('applies each line\'s own percentage discount so the invoice total matches Ninja', function (): void {
    $this->artisan('ninja:import', ['path' => ninjaEnvDir()])->assertSuccessful();

    $doc = Document::query()
        ->where('document_type', 'sales_invoice')
        ->whereJsonContains('metadata->ninja_id', 103)
        ->first();

    expect($doc)->not->toBeNull();
    expect($doc->lines)->toHaveCount(3);

    $l1 = $doc->lines->firstWhere('line_number', 1);
    $l2 = $doc->lines->firstWhere('line_number', 2);
    $l3 = $doc->lines->firstWhere('line_number', 3);

    expect((float) $l1->line_total)->toBe(1500.0)
        ->and((float) $l2->line_total)->toBe(1350.0)
        ->and((float) $l3->line_total)->toBe(337.5);

    // Raw cost×quantity sums to 4200 — line discounts must bring the
    // document total down to Ninja's true amount of 3187.50.
    expect((float) $doc->total)->toBe(3187.5);
});

it('applies a zero-header-amount "apply credit" payment using its real allocation', function (): void {
    $this->artisan('ninja:import', ['path' => ninjaEnvDir()])->assertSuccessful();

    $invoice = Document::query()
        ->where('document_type', 'sales_invoice')
        ->whereJsonContains('metadata->ninja_id', 103)
        ->first();

    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe('paid')
        ->and((float) $invoice->balance_due)->toBe(0.0);

    $payment = Document::where('document_type', 'payment')
        ->whereJsonContains('metadata->ninja_id', 202)
        ->first();

    expect($payment)->not->toBeNull()
        ->and((float) $payment->total)->toBe(3187.5);
});

it('caps an overpayment at balance_due and records only the applied amount on the payment document', function (): void {
    $this->artisan('ninja:import', ['path' => ninjaEnvDir()])->assertSuccessful();

    $invoice = Document::query()
        ->where('document_type', 'sales_invoice')
        ->whereJsonContains('metadata->ninja_id', 104)
        ->first();

    expect($invoice)->not->toBeNull()
        ->and($invoice->status)->toBe('paid')
        ->and((float) $invoice->amount_paid)->toBe(100.0)
        ->and((float) $invoice->balance_due)->toBe(0.0);

    $payment = Document::where('document_type', 'payment')
        ->whereJsonContains('metadata->ninja_id', 203)
        ->first();

    expect($payment)->not->toBeNull()
        ->and((float) $payment->total)->toBe(100.0);
});

it('imports recurring invoice with correct frequency and status', function (): void {
    $this->artisan('ninja:import', ['path' => ninjaEnvDir()])->assertSuccessful();

    $rel = PartyRelationship::where('relationship_type', 'client')
        ->whereJsonContains('metadata->ninja_id', 1)->first();

    $recurring = RecurringInvoice::where('client_id', $rel->party_id)
        ->where('notes', 'like', '%[ninja_id:301]%')
        ->first();

    expect($recurring)->not->toBeNull();
    expect($recurring->frequency)->toBe(RecurringFrequency::Monthly);
    expect($recurring->status)->toBe(RecurringInvoiceStatus::Active);
    expect($recurring->lines)->toHaveCount(1);
    expect((float) $recurring->lines->first()->unit_price)->toBe(3000.0);
});

it('stores ninja_id in metadata for idempotency', function (): void {
    $this->artisan('ninja:import', ['path' => ninjaEnvDir()])->assertSuccessful();

    $doc = Document::where('document_type', 'sales_invoice')
        ->whereJsonContains('metadata->ninja_id', 101)->first();

    expect($doc->metadata['ninja_id'])->toBe(101);
});

it('is idempotent — second run creates no duplicates', function (): void {
    $this->artisan('ninja:import', ['path' => ninjaEnvDir()])->assertSuccessful();

    $countsBefore = [
        'parties' => Party::count(),
        'invoices' => Document::where('document_type', 'sales_invoice')->count(),
        'payments' => Document::where('document_type', 'payment')->count(),
        'recurring' => RecurringInvoice::count(),
    ];

    $this->artisan('ninja:import', ['path' => ninjaEnvDir()])->assertSuccessful();

    expect(Party::count())->toBe($countsBefore['parties']);
    expect(Document::where('document_type', 'sales_invoice')->count())->toBe($countsBefore['invoices']);
    expect(Document::where('document_type', 'payment')->count())->toBe($countsBefore['payments']);
    expect(RecurringInvoice::count())->toBe($countsBefore['recurring']);
});

it('dry-run creates no records', function (): void {
    $this->artisan('ninja:import', ['path' => ninjaEnvDir(), '--dry-run' => true])->assertSuccessful();

    expect(Party::count())->toBe(0);
    expect(Document::count())->toBe(0);
    expect(RecurringInvoice::count())->toBe(0);
});
