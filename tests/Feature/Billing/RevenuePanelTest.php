<?php

use App\Livewire\RevenuePanel;
use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\User;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** Code every line of an already-issued invoice to an income account, as the postings view requires. */
function codeToIncome(Document $invoice): void
{
    $income = Account::whereHas('group.type', fn ($q) => $q->where('code', '4'))
        ->where('allow_direct_posting', true)
        ->first();

    DB::table('document_lines')->where('document_id', $invoice->id)->update(['account_id' => $income->id]);
}

beforeEach(function (): void {
    $this->seed(ChartOfAccountsSeeder::class);
    Carbon::setTestNow('2026-11-20 09:00:00');

    $user = User::factory()->create();
    $user->givePermissionTo(['documents-view-any', 'documents-view']);
    $this->actingAs($user);
});

it('shows month to date, year to date and twelve month labels from the income statement figures', function (): void {
    codeToIncome(widgetInvoice('Alpha Ltd', 1000, '2026-11-05'));
    codeToIncome(widgetInvoice('Beta Ltd', 250.50, '2026-11-20'));

    // Both invoices are due 2026-11-xx: inside this month and this financial year (March start).
    Livewire::test(RevenuePanel::class)
        ->assertSee('Month to date')
        ->assertSee('Year to date')
        ->assertSee('November 2026')
        ->assertSee('Financial year 2026/2027')
        ->assertSee('R1,250.50')
        ->assertSee('Revenue, last 12 months')
        ->assertSeeInOrder(['Dec', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov'])
        ->assertSeeHtml('title="November 2026: R1,250.50"')
        ->assertSeeHtml('title="October 2026: R0.00"');
});

it('shows nothing to someone who may not see invoices', function (): void {
    widgetInvoice('Alpha Ltd', 100, '2026-12-01');

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(RevenuePanel::class)
        ->assertDontSee('Month to date')
        ->assertDontSee('R100.00');
});

it('sits beside the unpaid invoices on the dashboard', function (): void {
    $this->get(route('dashboard'))
        ->assertOk()
        ->assertSeeLivewire('revenue-panel')
        ->assertSeeLivewire('unpaid-invoices-panel');
});
