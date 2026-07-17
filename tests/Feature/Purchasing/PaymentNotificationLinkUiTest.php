<?php

use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

function pnUser(string ...$permissions): User
{
    $user = User::factory()->create();
    foreach ($permissions as $perm) {
        Permission::findOrCreate($perm, 'web');
        $user->givePermissionTo($perm);
    }

    return $user;
}

it('confirms a suggested payment match from the invoice detail flyout', function (): void {
    $this->actingAs(pnUser('documents-view-any', 'documents-view', 'documents-update'));

    $invoice = Document::factory()->purchaseInvoice()->create();

    $notification = Document::factory()->create([
        'document_type' => 'payment_notification',
        'status' => 'received',
        'party_id' => null,
        'currency' => 'ZAR',
        'total' => 450.0,
        'metadata' => [
            'payee_name' => 'Domains CoZa',
            'suggested_invoice_id' => $invoice->id,
            'match_confidence' => 0.6,
            'match_reason' => 'Payee resembles supplier; dates align',
        ],
    ]);

    Livewire::test('pages.purchase-invoices.index')
        ->set('detailId', $invoice->id)
        ->set('showDetail', true)
        ->assertSee('Possible payment match')
        ->call('confirmSuggestedMatch', $notification->id);

    expect(Document::find($notification->id))->toBeNull()
        ->and($invoice->fresh()->metadata['payment_notification']['payee_name'] ?? null)->toBe('Domains CoZa');
});

it('dismisses a suggested payment match without merging', function (): void {
    $this->actingAs(pnUser('documents-view-any', 'documents-view', 'documents-update'));

    $invoice = Document::factory()->purchaseInvoice()->create();

    $notification = Document::factory()->create([
        'document_type' => 'payment_notification',
        'status' => 'received',
        'party_id' => null,
        'currency' => 'ZAR',
        'total' => 450.0,
        'metadata' => [
            'payee_name' => 'Domains CoZa',
            'suggested_invoice_id' => $invoice->id,
            'match_confidence' => 0.6,
            'match_reason' => 'Payee resembles supplier; dates align',
        ],
    ]);

    Livewire::test('pages.purchase-invoices.index')
        ->set('detailId', $invoice->id)
        ->set('showDetail', true)
        ->call('dismissSuggestedMatch', $notification->id);

    $notification->refresh();

    expect($notification->metadata['suggested_invoice_id'] ?? null)->toBeNull()
        ->and($notification->trashed())->toBeFalse();
});
