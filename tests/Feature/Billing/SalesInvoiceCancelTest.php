<?php

use App\Exceptions\InvalidDocumentStateException;
use App\Modules\Billing\Services\BillingService;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentActivity;
use App\Modules\Core\Models\DocumentRelationship;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;
use App\Modules\Core\Services\PartyService;
use Illuminate\Support\Str;
use Livewire\Livewire;

function cancelClient()
{
    return app(PartyService::class)->createBusiness([
        'business_type' => 'company',
        'legal_name' => 'Cancel Test Client Ltd',
        'trading_name' => 'Cancel Test Client',
        'status' => 'active',
    ], ['client']);
}

/**
 * A sent invoice with a single line of the given value, like the imported R6509 when
 * the value is zero. Given a distinctive high document_number — plain small numbers
 * (1, 2, 3, ...) collide with incidental digits elsewhere on the rendered page (tab
 * badge counts, wire:id hashes), making assertSee/assertDontSee on them unreliable.
 */
function sentInvoice(float $amount = 0.0): Document
{
    $doc = app(BillingService::class)->createDraft(cancelClient(), ['issue_date' => now()->toDateString()]);
    $doc->update(['document_number' => (string) random_int(100000, 999999)]);

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
    app(DocumentService::class)->markAsSent($doc, User::factory()->create());

    return $doc->fresh();
}

function cancelUser(array $permissions = ['documents-view-any', 'documents-view', 'documents-update']): User
{
    $user = User::factory()->create();
    $user->givePermissionTo($permissions);

    return $user;
}

// --- Service rules ---

it('cancels a sent zero-value invoice and records who and why', function (): void {
    $invoice = sentInvoice(0);
    $user = User::factory()->create();

    app(DocumentService::class)->cancelInvoice($invoice, $user);

    $activity = DocumentActivity::where('document_id', $invoice->id)->where('activity_type', 'status_changed')->get()
        ->first(fn (DocumentActivity $a): bool => ($a->metadata['to'] ?? null) === 'cancelled');

    expect($invoice->fresh()->status)->toBe('cancelled')
        ->and($activity->user_id)->toBe($user->id)
        ->and($activity->metadata)->toMatchArray(['from' => 'sent', 'to' => 'cancelled']);
});

it('moves no money and creates no documents when cancelling', function (): void {
    $invoice = sentInvoice(0);
    $documents = Document::count();

    app(DocumentService::class)->cancelInvoice($invoice, null);

    $fresh = $invoice->fresh();

    expect((float) $fresh->total)->toBe(0.0)
        ->and((float) $fresh->balance_due)->toBe(0.0)
        ->and((float) $fresh->amount_paid)->toBe(0.0)
        ->and(Document::count())->toBe($documents);

    // The audit-trail check must not flag the status change as an unaudited edit.
    $this->artisan('documents:verify-audit-trail')->assertSuccessful();
});

it('refuses to cancel an invoice that carries value, and tells the user to use a credit note', function (): void {
    $invoice = sentInvoice(1000);

    expect(fn () => app(DocumentService::class)->cancelInvoice($invoice, null))
        ->toThrow(InvalidDocumentStateException::class, 'credit note');

    expect($invoice->fresh()->status)->toBe('sent');
});

it('refuses to cancel a paid, draft or already cancelled invoice, or one with a linked document', function (): void {
    $service = app(DocumentService::class);

    $paid = sentInvoice(500);
    app(BillingService::class)->recordPayment($paid, ['amount' => 500.0, 'date' => now()->toDateString()], null);

    $draft = app(BillingService::class)->createDraft(cancelClient(), ['issue_date' => now()->toDateString()]);

    $cancelled = sentInvoice(0);
    $service->cancelInvoice($cancelled, null);

    $linked = sentInvoice(0);
    DocumentRelationship::create([
        'parent_document_id' => $linked->id,
        'child_document_id' => sentInvoice(0)->id,
        'relationship_type' => 'payment_for',
    ]);

    foreach ([$paid, $draft, $cancelled, $linked] as $document) {
        expect($document->fresh()->canBeCancelled())->toBeFalse()
            ->and(fn () => $service->cancelInvoice($document->fresh(), null))->toThrow(InvalidDocumentStateException::class);
    }

    expect($paid->fresh()->status)->toBe('paid')
        ->and($draft->fresh()->status)->toBe('draft')
        ->and($linked->fresh()->status)->toBe('sent');
});

it('treats cancelled as final', function (): void {
    $invoice = sentInvoice(0);
    app(DocumentService::class)->cancelInvoice($invoice, null);

    expect(fn () => app(DocumentService::class)->markAsSent($invoice->fresh(), null))
        ->toThrow(InvalidDocumentStateException::class);
});

// --- Page ---

it('lists zero-total invoices and counts only those still waiting to be cancelled', function (): void {
    $this->actingAs(cancelUser());

    $zero = sentInvoice(0);
    $zeroDone = sentInvoice(0);
    app(DocumentService::class)->cancelInvoice($zeroDone, null);
    $valued = sentInvoice(250);

    Livewire::test('pages.sales-invoices.index')
        ->assertSee($zero->document_number)
        ->assertSee($valued->document_number)
        ->assertViewHas('zeroTotalCount', 1)
        ->set('statusFilter', 'zero_total')
        ->assertSee($zero->document_number)
        ->assertSee($zeroDone->document_number)
        ->assertDontSee($valued->document_number);
});

it('treats Zero total like the other tabs: choosing another tab ends it', function (): void {
    $this->actingAs(cancelUser());

    $zero = sentInvoice(0);
    $valued = sentInvoice(250);

    Livewire::test('pages.sales-invoices.index')
        ->assertSeeHtml("wire:click=\"\$set('statusFilter', 'zero_total')\"")
        ->assertDontSeeHtml('zeroTotalOnly')
        ->set('statusFilter', 'zero_total')
        ->assertSee($zero->document_number)
        ->assertDontSee($valued->document_number)
        // Clicking Unpaid (or any other tab) replaces the Zero total view; nothing keeps filtering underneath.
        ->set('statusFilter', 'unpaid')
        ->assertSee($zero->document_number)
        ->assertSee($valued->document_number)
        ->set('statusFilter', 'zero_total')
        ->set('statusFilter', '')
        ->assertSee($valued->document_number);
});

it('has a Cancelled tab that shows cancelled invoices', function (): void {
    $this->actingAs(cancelUser());

    $cancelled = sentInvoice(0);
    app(DocumentService::class)->cancelInvoice($cancelled, null);
    $open = sentInvoice(300);

    Livewire::test('pages.sales-invoices.index')
        ->assertSee('Cancelled')
        ->set('statusFilter', 'cancelled')
        ->assertSee($cancelled->document_number)
        ->assertDontSee($open->document_number)
        ->set('statusFilter', 'unpaid')
        ->assertDontSee($cancelled->document_number)
        ->assertSee($open->document_number);
});

it('bulk cancels the selected zero-value invoices and skips the rest, saying so', function (): void {
    $this->actingAs(cancelUser());

    $a = sentInvoice(0);
    $b = sentInvoice(0);
    $valued = sentInvoice(400);

    Livewire::test('pages.sales-invoices.index')
        ->set('selectedIds', [$a->id, $b->id, $valued->id])
        ->assertSee('3 selected')
        ->call('openCancelModal')
        ->assertSet('showCancelModal', true)
        ->assertSet('cancelEligible', 2)
        ->assertSet('cancelSkipped', 1)
        ->call('confirmCancel')
        ->assertSet('showCancelModal', false)
        ->assertSet('selectedIds', [])
        ->assertSee('Cancelled 2 invoices.')
        ->assertSee('Skipped 1 that are not zero-value sent invoices');

    expect($a->fresh()->status)->toBe('cancelled')
        ->and($b->fresh()->status)->toBe('cancelled')
        ->and($valued->fresh()->status)->toBe('sent');
});

it('cancels every invoice matching the filter across pages with "select all matching"', function (): void {
    $this->actingAs(cancelUser());

    // The page shows 25 at a time; 27 zero-value invoices span two pages.
    $zero = collect(range(1, 27))->map(fn () => sentInvoice(0));
    $valued = sentInvoice(150);

    Livewire::test('pages.sales-invoices.index')
        ->set('statusFilter', 'zero_total')
        ->call('markSelectAllFiltered')
        ->assertSee('All 27 invoices selected')
        ->call('openCancelModal')
        ->assertSet('cancelEligible', 27)
        ->assertSet('cancelSkipped', 0)
        ->call('confirmCancel');

    expect($zero->every(fn (Document $d) => $d->fresh()->status === 'cancelled'))->toBeTrue()
        ->and($valued->fresh()->status)->toBe('sent');
});

it('re-checks eligibility on confirm, so two people cancelling the same invoice cannot break it', function (): void {
    $this->actingAs(cancelUser());

    $invoice = sentInvoice(0);

    $component = Livewire::test('pages.sales-invoices.index')
        ->set('selectedIds', [$invoice->id])
        ->call('openCancelModal')
        ->assertSet('cancelEligible', 1);

    // Someone else cancels it between opening the dialog and confirming.
    app(DocumentService::class)->cancelInvoice($invoice->fresh(), null);

    $component->call('confirmCancel')
        ->assertSee('Cancelled 0 invoices.')
        ->assertSee('Skipped 1');

    expect($invoice->fresh()->status)->toBe('cancelled')
        ->and(DocumentActivity::where('document_id', $invoice->id)->get()
            ->filter(fn (DocumentActivity $a): bool => ($a->metadata['to'] ?? null) === 'cancelled')->count())->toBe(1);
});

it('forbids cancelling without update permission and hides the button', function (): void {
    $this->actingAs(cancelUser(['documents-view-any', 'documents-view']));

    $invoice = sentInvoice(0);

    Livewire::test('pages.sales-invoices.index')
        ->set('selectedIds', [$invoice->id])
        ->assertSee('1 selected')
        ->assertDontSeeHtml('wire:click="openCancelModal"')
        ->call('openCancelModal')
        ->assertForbidden();

    Livewire::test('pages.sales-invoices.index')
        ->set('selectedIds', [$invoice->id])
        ->call('confirmCancel')
        ->assertForbidden();

    expect($invoice->fresh()->status)->toBe('sent');
});

it('ignores tampered ids and never touches anything but sales invoices', function (): void {
    $this->actingAs(cancelUser());

    $invoice = sentInvoice(0);
    $purchase = Document::factory()->purchaseInvoice()->create();

    Livewire::test('pages.sales-invoices.index')
        ->set('selectedIds', [$invoice->id, 'not-a-uuid', (string) Str::uuid(), $purchase->id, "' OR 1=1 --"])
        ->call('openCancelModal')
        ->assertSet('cancelEligible', 1)
        ->assertSet('cancelSkipped', 0)
        ->call('confirmCancel');

    expect($invoice->fresh()->status)->toBe('cancelled')
        ->and($purchase->fresh()->status)->toBe('received');
});

it('clears the selection when the list is filtered differently', function (): void {
    $this->actingAs(cancelUser());

    $invoice = sentInvoice(0);

    Livewire::test('pages.sales-invoices.index')
        ->set('selectedIds', [$invoice->id])
        ->set('statusFilter', 'zero_total')
        ->assertSet('selectedIds', []);

    Livewire::test('pages.sales-invoices.index')
        ->call('markSelectAllFiltered')
        ->set('statusFilter', 'sent')
        ->assertSet('selectAllFiltered', false);

    Livewire::test('pages.sales-invoices.index')
        ->set('selectedIds', [$invoice->id])
        ->set('search', 'Cancel')
        ->assertSet('selectedIds', []);
});

it('offers nothing to do with a cancelled invoice in its detail view', function (): void {
    $this->actingAs(cancelUser(['documents-view-any', 'documents-view', 'documents-update', 'can-send-sales-invoices', 'can-record-payments']));

    $invoice = sentInvoice(0);

    // The dialogs' text is always in the page (hidden), so look for the action buttons' handlers.
    Livewire::test('pages.sales-invoices.index')
        ->call('openDetail', $invoice->id)
        ->assertSeeHtml('wire:click="openSendModal"')
        ->assertSeeHtml('wire:click="openPaymentModal"');

    app(DocumentService::class)->cancelInvoice($invoice->fresh(), null);

    Livewire::test('pages.sales-invoices.index')
        ->call('openDetail', $invoice->id)
        ->assertSee('Cancelled')
        ->assertDontSeeHtml('wire:click="openSendModal"')
        ->assertDontSeeHtml('wire:click="openPaymentModal"')
        ->assertDontSeeHtml('wire:click="createCreditNoteForInvoice"');
});
