<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentLine;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\PartyService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Spatie\Permission\Models\Permission;

function piSupplier(string $name): Party
{
    return app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => $name,
        'trading_name' => $name,
        'status' => 'active',
    ], ['supplier']);
}

/**
 * UI behaviour tests for the purchase-invoices index Volt page.
 *
 * This page is the most complex in the app (custom upload, inline line edit,
 * status machine buttons, reprocess). The existing PurchaseInvoiceUploadTest
 * only covers upload validation. These cover the rest.
 */
function piUser(string ...$permissions): User
{
    $user = User::factory()->create();
    foreach ($permissions as $perm) {
        Permission::findOrCreate($perm, 'web');
        $user->givePermissionTo($perm);
    }

    return $user;
}

// --- Supplier filter ---

it('filters invoices by supplier', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view'));

    $supplierA = piSupplier('Acme Hosting');
    $supplierB = piSupplier('Beta Traders');

    $invoiceA = Document::factory()->purchaseInvoice()->create(['party_id' => $supplierA->id]);
    $invoiceB = Document::factory()->purchaseInvoice()->create(['party_id' => $supplierB->id]);

    Livewire::test('pages.purchase-invoices.index')
        ->set('supplierFilter', $supplierA->id)
        ->assertSee($invoiceA->document_number)
        // The dropdown itself still legitimately lists both suppliers as
        // options — assert on the table row (document number), not the
        // supplier name, which would still appear in the <option> list.
        ->assertDontSee($invoiceB->document_number);
});

it('only lists suppliers that actually have purchase invoices in the filter dropdown', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view'));

    $supplierWithInvoice = piSupplier('Acme Hosting');
    piSupplier('No Invoices Yet Ltd');

    Document::factory()->purchaseInvoice()->create(['party_id' => $supplierWithInvoice->id]);

    Livewire::test('pages.purchase-invoices.index')
        ->assertSee('Acme Hosting')
        ->assertDontSee('No Invoices Yet Ltd');
});

it('resets pagination when the supplier filter changes', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view'));

    // A single shared payable account avoids exhausting AccountFactory's
    // unique 4-digit code pool across 30 rows.
    $payableAccount = Account::factory()->create();
    Document::factory()->purchaseInvoice()->count(30)->create(['payable_account_id' => $payableAccount->id]);
    $supplier = piSupplier('Acme Hosting');

    Livewire::test('pages.purchase-invoices.index')
        ->call('gotoPage', 2)
        ->assertSet('paginators.page', 2)
        ->set('supplierFilter', $supplier->id)
        ->assertSet('paginators.page', 1);
});

// --- Payment status column & filter ---

it('shows the Unpaid badge for a posted invoice with an outstanding balance', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view'));

    Document::factory()->purchaseInvoice()->create(['status' => 'posted', 'total' => 500, 'balance_due' => 500]);

    // 'Unpaid' is also a filter-dropdown option, always present regardless of
    // data — assert on the actual badge markup (its distinguishing classes),
    // not the bare word.
    $html = Livewire::test('pages.purchase-invoices.index')->html();

    expect($html)->toContain('bg-red-50 text-danger">Unpaid');
});

it('shows the Part Paid and Paid badges for their respective statuses', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view'));

    Document::factory()->purchaseInvoice()->create(['status' => 'partially_paid', 'total' => 500, 'balance_due' => 200]);
    Document::factory()->purchaseInvoice()->create(['status' => 'paid', 'total' => 500, 'balance_due' => 0]);

    $html = Livewire::test('pages.purchase-invoices.index')->html();

    expect($html)->toContain('bg-amber-50 text-amber-700">Part Paid')
        ->toContain('bg-emerald-50 text-emerald-800">Paid');
});

it('shows no payment badge for invoices not yet posted', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view'));

    $doc = Document::factory()->purchaseInvoice()->create(['status' => 'received']);

    // 'Unpaid'/'Part Paid' also appear as filter-chrome text (payment filter
    // option, status tab) regardless of data, so assert on the payment
    // column's actual empty-state markup for this specific row instead of a
    // page-wide text search.
    $html = Livewire::test('pages.purchase-invoices.index')->html();

    expect($html)->toContain('text-ink-muted text-xs">—</span>');
    expect(Document::find($doc->id)->status)->toBe('received');
});

it('filters invoices by payment state', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view'));

    $unpaid = Document::factory()->purchaseInvoice()->create(['status' => 'posted', 'total' => 500, 'balance_due' => 500]);
    $paid = Document::factory()->purchaseInvoice()->create(['status' => 'paid', 'total' => 500, 'balance_due' => 0]);

    Livewire::test('pages.purchase-invoices.index')
        ->set('paymentFilter', 'unpaid')
        ->assertSee($unpaid->document_number)
        ->assertDontSee($paid->document_number);
});

// --- Extraction failure badge ---

it('shows an extraction-failed badge on flagged invoices', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view'));

    Document::factory()->purchaseInvoice()->create([
        'metadata' => ['extraction_failed' => true],
    ]);

    Livewire::test('pages.purchase-invoices.index')
        ->assertSee('Extraction failed');
});

// --- Files ---

it('shows the source document and attachments in the detail flyout', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view'));

    $doc = Document::factory()->purchaseInvoice()->create();

    Media::create([
        'model_type' => (new Document)->getMorphClass(),
        'model_id' => $doc->id,
        'uuid' => Str::uuid(),
        'collection_name' => 'source_document',
        'name' => 'invoice',
        'file_name' => 'original-invoice.pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'local',
        'conversions_disk' => 'local',
        'size' => 1024,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
        'order_column' => 1,
    ]);

    Media::create([
        'model_type' => (new Document)->getMorphClass(),
        'model_id' => $doc->id,
        'uuid' => Str::uuid(),
        'collection_name' => 'attachments',
        'name' => 'receipt',
        'file_name' => 'payfast-receipt.pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'local',
        'conversions_disk' => 'local',
        'size' => 1024,
        'manipulations' => [],
        'custom_properties' => [],
        'generated_conversions' => [],
        'responsive_images' => [],
        'order_column' => 1,
    ]);

    Livewire::test('pages.purchase-invoices.index')
        ->call('openDetail', $doc->id)
        ->assertSee('original-invoice.pdf')
        ->assertSee('payfast-receipt.pdf');
});

it('hides the Files section when there are no attached files', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view'));

    $doc = Document::factory()->purchaseInvoice()->create();

    Livewire::test('pages.purchase-invoices.index')
        ->call('openDetail', $doc->id)
        ->assertDontSeeText('Files');
});

// --- Inline line editing ---

it('editLine populates editingLine from the existing record', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view', 'documents-update'));

    $doc = Document::factory()->purchaseInvoice()->create();
    $line = DocumentLine::factory()->for($doc)->create([
        'description' => 'Cloud hosting',
        'quantity' => 2,
        'unit_price' => 500,
        'tax_rate' => 15,
    ]);

    Livewire::test('pages.purchase-invoices.index')
        ->set('detailId', $doc->id)
        ->call('editLine', $line->id)
        ->assertSet('editingLineId', $line->id)
        ->assertSet('editingLine.description', 'Cloud hosting')
        ->assertSet('editingLine.quantity', '2.0000')
        ->assertSet('editingLine.unit_price', '500.0000');
});

it('saveLine persists changes when user has update permission', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view', 'documents-update'));

    $doc = Document::factory()->purchaseInvoice()->create();
    $account = Account::factory()->create(['allow_direct_posting' => true]);
    $line = DocumentLine::factory()->for($doc)->create([
        'description' => 'Old desc',
        'quantity' => 1,
        'unit_price' => 100,
    ]);

    Livewire::test('pages.purchase-invoices.index')
        ->set('detailId', $doc->id)
        ->call('editLine', $line->id)
        ->set('editingLine.description', 'New desc')
        ->set('editingLine.account_id', $account->id)
        ->set('editingLine.quantity', '3')
        ->set('editingLine.unit_price', '250')
        ->set('editingLine.tax_rate', '15')
        ->call('saveLine')
        ->assertHasNoErrors()
        ->assertSet('editingLineId', null);

    $line->refresh();
    expect($line->description)->toBe('New desc')
        ->and((float) $line->quantity)->toBe(3.0)
        ->and((float) $line->unit_price)->toBe(250.0)
        ->and((float) $line->tax_rate)->toBe(15.0)
        ->and($line->account_id)->toBe($account->id);
});

it('saveLine rejects negative quantity', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view', 'documents-update'));

    $doc = Document::factory()->purchaseInvoice()->create();
    $line = DocumentLine::factory()->for($doc)->create();

    Livewire::test('pages.purchase-invoices.index')
        ->set('detailId', $doc->id)
        ->call('editLine', $line->id)
        ->set('editingLine.quantity', '-5')
        ->set('editingLine.unit_price', '100')
        ->call('saveLine')
        ->assertHasErrors(['editingLine.quantity']);
});

it('cancelLine clears editing state without saving', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view', 'documents-update'));

    $doc = Document::factory()->purchaseInvoice()->create();
    $line = DocumentLine::factory()->for($doc)->create(['description' => 'Original']);

    Livewire::test('pages.purchase-invoices.index')
        ->set('detailId', $doc->id)
        ->call('editLine', $line->id)
        ->set('editingLine.description', 'Changed but not saved')
        ->call('cancelLine')
        ->assertSet('editingLineId', null)
        ->assertSet('editingLine', []);

    expect($line->fresh()->description)->toBe('Original');
});

// --- Status transitions via UI ---

it('markReviewed advances status when user has can-review-invoices', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view', 'can-review-invoices'));

    $doc = Document::factory()->purchaseInvoice()->create(['status' => 'received']);

    Livewire::test('pages.purchase-invoices.index')
        ->set('detailId', $doc->id)
        ->call('markReviewed')
        ->assertHasNoErrors();

    expect($doc->fresh()->status)->toBe('reviewed');
});

it('markReviewed is forbidden without can-review-invoices', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view'));

    $doc = Document::factory()->purchaseInvoice()->create(['status' => 'received']);

    Livewire::test('pages.purchase-invoices.index')
        ->set('detailId', $doc->id)
        ->call('markReviewed')
        ->assertForbidden();

    expect($doc->fresh()->status)->toBe('received');
});

it('approve transitions to approved with can-authorise-invoices', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view', 'can-authorise-invoices'));

    $doc = Document::factory()->purchaseInvoice()->create(['status' => 'reviewed']);

    Livewire::test('pages.purchase-invoices.index')
        ->set('detailId', $doc->id)
        ->call('approve')
        ->assertHasNoErrors();

    expect($doc->fresh()->status)->toBe('approved');
});

it('post transitions to posted with can-post-invoices', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view', 'can-post-invoices'));

    $doc = Document::factory()->purchaseInvoice()->create(['status' => 'approved']);

    Livewire::test('pages.purchase-invoices.index')
        ->set('detailId', $doc->id)
        ->call('post')
        ->assertHasNoErrors();

    expect($doc->fresh()->status)->toBe('posted');
});

it('confirmDispute requires a reason', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view', 'can-review-invoices'));

    $doc = Document::factory()->purchaseInvoice()->create(['status' => 'received']);

    Livewire::test('pages.purchase-invoices.index')
        ->set('detailId', $doc->id)
        ->call('confirmDispute')
        ->assertHasErrors(['actionReason']);
});

it('confirmDispute moves doc to disputed when reason supplied', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view', 'can-review-invoices'));

    $doc = Document::factory()->purchaseInvoice()->create(['status' => 'received']);

    Livewire::test('pages.purchase-invoices.index')
        ->set('detailId', $doc->id)
        ->set('actionReason', 'Amount looks wrong')
        ->call('confirmDispute')
        ->assertHasNoErrors();

    expect($doc->fresh()->status)->toBe('disputed');
});

// --- Reprocess ---

it('confirmReprocess dispatches the job and resets status', function (): void {
    Queue::fake();

    $this->actingAs(piUser('documents-view-any', 'documents-view', 'can-reprocess-invoices'));

    $doc = Document::factory()->purchaseInvoice()->create(['status' => 'disputed']);
    DocumentLine::factory()->for($doc)->count(2)->create();

    Livewire::test('pages.purchase-invoices.index')
        ->set('detailId', $doc->id)
        ->call('confirmReprocess')
        ->assertHasNoErrors();

    // Status reset + lines cleared (reprocess invariant).
    expect($doc->fresh()->status)->toBe('queued')
        ->and($doc->fresh()->lines()->count())->toBe(0);
});

it('openReprocessConfirm is forbidden without permission', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view'));

    $doc = Document::factory()->purchaseInvoice()->create(['status' => 'received']);

    Livewire::test('pages.purchase-invoices.index')
        ->set('detailId', $doc->id)
        ->call('openReprocessConfirm')
        ->assertForbidden();
});

// --- Quick row actions ---

it('quickMarkReviewed is a no-op when doc is not in an eligible status', function (): void {
    $this->actingAs(piUser('documents-view-any', 'can-review-invoices'));

    $doc = Document::factory()->purchaseInvoice()->create(['status' => 'approved']);

    Livewire::test('pages.purchase-invoices.index')
        ->call('quickMarkReviewed', $doc->id)
        ->assertHasNoErrors();

    // Status unchanged — guard inside method blocks invalid transition silently.
    expect($doc->fresh()->status)->toBe('approved');
});
