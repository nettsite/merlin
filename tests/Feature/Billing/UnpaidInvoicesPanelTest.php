<?php

use App\Livewire\UnpaidInvoicesPanel;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;
use App\Modules\Core\Services\PartyService;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-20 09:00:00');

    $this->actingAs(widgetUser());
});

function widgetUser(array $permissions = ['documents-view-any', 'documents-view']): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

/** A sales invoice for a new client, sent unless told otherwise. */
function widgetInvoice(string $client, float $amount, ?string $due = null, bool $send = true): Document
{
    $party = app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => $client,
        'trading_name' => $client,
        'status' => 'active',
    ], ['client']);

    $doc = app(BillingService::class)->createDraft($party, ['issue_date' => '2026-11-01']);

    if ($due !== null) {
        $doc->update(['due_date' => $due]);
    }

    $doc->lines()->create([
        'line_number' => 1,
        'type' => 'service',
        'description' => 'Hosting',
        'quantity' => 1,
        'unit_price' => $amount,
        'discount_percent' => 0,
        'discount_amount' => 0,
        'tax_rate' => 0,
    ]);
    $doc->recalculateTotals();

    if ($send) {
        app(DocumentService::class)->markAsSent($doc, User::factory()->create());
    }

    return $doc->fresh();
}

function widgetPay(Document $invoice, float $amount): void
{
    app(BillingService::class)->recordPayment($invoice, ['amount' => $amount, 'date' => '2026-11-15'], null);
}

it('shows the total outstanding and lists client, invoice number, due date and amount', function (): void {
    $overdue = widgetInvoice('Alpha Ltd', 1000, '2026-11-10');
    widgetInvoice('Beta Ltd', 2500.50, '2026-12-01');

    $partlyPaid = widgetInvoice('Gamma Ltd', 800, '2026-11-25');
    widgetPay($partlyPaid, 300);

    Livewire::test(UnpaidInvoicesPanel::class)
        ->assertSee('Unpaid invoices')
        ->assertSee('R4,000.50')
        ->assertSee('3 invoices outstanding')
        ->assertSee('Alpha Ltd')
        ->assertSee($overdue->document_number)
        ->assertSee('10 Nov 2026')
        ->assertSee('R1,000.00')
        // A part-paid invoice counts and lists only what is still owed.
        ->assertSee('Gamma Ltd')
        ->assertSee('R500.00')
        ->assertDontSee('R800.00');
});

it('leaves out drafts, paid, cancelled and zero-value invoices, and purchase invoices', function (): void {
    widgetInvoice('Valid Ltd', 100, '2026-12-01');

    widgetInvoice('Draft Ltd', 400, '2026-12-01', send: false);

    $paid = widgetInvoice('Paid Ltd', 600, '2026-12-01');
    widgetPay($paid, 600);

    widgetInvoice('Zero Ltd', 0, '2026-12-01');

    $cancelled = widgetInvoice('Cancelled Ltd', 0, '2026-12-01');
    app(DocumentService::class)->cancelInvoice($cancelled, null);

    $purchase = Document::factory()->purchaseInvoice()->create(['total' => 999]);

    Livewire::test(UnpaidInvoicesPanel::class)
        ->assertSee('Valid Ltd')
        ->assertSee('R100.00')
        ->assertSee('1 invoice outstanding')
        ->assertDontSee('Draft Ltd')
        ->assertDontSee('Paid Ltd')
        ->assertDontSee('Zero Ltd')
        ->assertDontSee('Cancelled Ltd')
        ->assertDontSee($purchase->document_number);
});

it('lists the most overdue first, caps the list at ten, and still totals every unpaid invoice', function (): void {
    foreach (range(1, 12) as $day) {
        widgetInvoice("Dated {$day} Ltd", 100, sprintf('2026-12-%02d', $day));
    }

    widgetInvoice('Undated Ltd', 100);

    Livewire::test(UnpaidInvoicesPanel::class)
        ->assertSee('R1,300.00')
        ->assertSee('13 invoices outstanding')
        ->assertSeeInOrder(['Dated 1 Ltd', 'Dated 2 Ltd', 'Dated 3 Ltd', 'Dated 9 Ltd', 'Dated 10 Ltd'])
        ->assertSee('and 3 more')
        ->assertDontSee('Dated 11 Ltd')
        ->assertDontSee('Dated 12 Ltd')
        // No due date sorts last, so it is the first to fall off the list.
        ->assertDontSee('Undated Ltd');
});

it('shows a due date in red only once it is past', function (): void {
    widgetInvoice('Today Ltd', 100, '2026-11-20');
    widgetInvoice('Later Ltd', 100, '2026-12-05');

    Livewire::test(UnpaidInvoicesPanel::class)
        ->assertDontSeeHtml('text-danger');

    widgetInvoice('Late Ltd', 100, '2026-11-19');

    Livewire::test(UnpaidInvoicesPanel::class)
        ->assertSeeHtml('font-medium text-danger');
});

it('says so when nothing is unpaid', function (): void {
    widgetInvoice('Paid Ltd', 600, '2026-12-01', send: true);
    widgetPay(Document::where('document_type', 'sales_invoice')->first(), 600);

    Livewire::test(UnpaidInvoicesPanel::class)
        ->assertSee('No unpaid invoices.')
        ->assertSee('R0.00')
        ->assertSee('0 invoices outstanding')
        ->assertDontSee('View all');
});

it('links to the Unpaid tab of the sales invoices', function (): void {
    widgetInvoice('Alpha Ltd', 100, '2026-12-01');

    Livewire::test(UnpaidInvoicesPanel::class)
        ->assertSeeHtml(e(route('sales-invoices.index', ['status' => 'unpaid'])));
});

it('shows nothing to someone who may not see invoices', function (): void {
    widgetInvoice('Alpha Ltd', 100, '2026-12-01');

    $this->actingAs(widgetUser([]));

    Livewire::test(UnpaidInvoicesPanel::class)
        ->assertDontSee('Unpaid invoices')
        ->assertDontSee('Alpha Ltd')
        ->assertDontSee('R100.00');
});

it('appears on the dashboard for people who may see invoices, and not for others', function (): void {
    widgetInvoice('Alpha Ltd', 100, '2026-12-01');

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSeeLivewire('unpaid-invoices-panel')
        ->assertSee('Unpaid invoices')
        ->assertSee('Alpha Ltd')
        ->assertDontSee("You're logged in!");

    $this->actingAs(widgetUser([]));

    $this->get(route('dashboard'))
        ->assertOk()
        ->assertDontSee('Unpaid invoices')
        ->assertDontSee('Alpha Ltd');
});

it('lets a link open a specific sales invoices tab, ignoring anything it does not know', function (): void {
    Livewire::withQueryParams(['status' => 'unpaid'])
        ->test('pages.sales-invoices.index')
        ->assertSet('statusFilter', 'unpaid');

    expect(session('si.statusFilter'))->toBe('unpaid');

    session(['si.statusFilter' => 'paid']);

    Livewire::withQueryParams(['status' => '<script>alert(1)</script>'])
        ->test('pages.sales-invoices.index')
        ->assertSet('statusFilter', 'paid');

    Livewire::withQueryParams(['status' => ['unpaid']])
        ->test('pages.sales-invoices.index')
        ->assertSet('statusFilter', 'paid');
});
