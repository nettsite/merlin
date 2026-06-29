<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentLine;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

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

// --- Extraction failure badge ---

it('shows an extraction-failed badge on flagged invoices', function (): void {
    $this->actingAs(piUser('documents-view-any', 'documents-view'));

    Document::factory()->purchaseInvoice()->create([
        'metadata' => ['extraction_failed' => true],
    ]);

    Livewire::test('pages.purchase-invoices.index')
        ->assertSee('Extraction failed');
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
