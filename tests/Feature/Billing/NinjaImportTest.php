<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountGroup;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Billing\Enums\RecurringFrequency;
use App\Modules\Billing\Enums\RecurringInvoiceStatus;
use App\Modules\Billing\Models\RecurringInvoice;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentRelationship;
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
    expect($rel->metadata['default_receivable_account_id'])->toBe(Account::where('code', '1100')->value('id'));
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
