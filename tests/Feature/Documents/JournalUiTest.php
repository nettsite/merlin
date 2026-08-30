<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService;
use Livewire\Livewire;

function journalUiUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo([
        'documents-view-any',
        'documents-view',
        'documents-create',
        'documents-update',
        'documents-delete',
        'can-post-journals',
    ]);

    return $user;
}

it('creates a journal, adds balanced lines, and posts it', function (): void {
    $this->actingAs(journalUiUser());

    $expense = Account::factory()->expense()->create();
    $asset = Account::factory()->asset()->create();

    $component = Livewire::test('pages.journals.index')
        ->set('createForm.issue_date', '2026-03-01')
        ->set('createForm.reference', 'Accrual adjustment')
        ->call('createJournal')
        ->assertOk();

    $journal = Document::journals()->sole();
    expect($journal->status)->toBe('draft')
        ->and($journal->reference)->toBe('Accrual adjustment');

    $component
        ->call('openAddLine')
        ->set('newLine.description', 'Accrue expense')
        ->set('newLine.account_id', $expense->id)
        ->set('newLine.debit', '500')
        ->set('newLine.credit', '')
        ->call('saveNewLine')
        ->assertOk();

    $component
        ->call('openAddLine')
        ->set('newLine.description', 'Offsetting accrual')
        ->set('newLine.account_id', $asset->id)
        ->set('newLine.debit', '')
        ->set('newLine.credit', '500')
        ->call('saveNewLine')
        ->assertOk();

    expect($journal->lines()->count())->toBe(2);

    $component->call('post')->assertOk();

    expect($journal->fresh()->status)->toBe('posted');
    expect(DB::table('postings')->where('document_id', $journal->id)->count())->toBe(2);
});

it('surfaces a validation error and does not silently drop a line missing a description', function (): void {
    $this->actingAs(journalUiUser());

    $expense = Account::factory()->expense()->create();

    $journal = Document::create([
        'document_type' => 'journal',
        'direction' => 'internal',
        'status' => 'draft',
        'issue_date' => '2026-03-01',
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'source' => 'manual',
    ]);

    Livewire::test('pages.journals.index')
        ->call('openDetail', $journal->id)
        ->call('openAddLine')
        ->set('newLine.description', '')
        ->set('newLine.account_id', $expense->id)
        ->set('newLine.debit', '500')
        ->call('saveNewLine')
        ->assertHasErrors(['newLine.description' => 'required']);

    expect($journal->lines()->count())->toBe(0);
});

it('reverses a posted journal from the UI into a new draft', function (): void {
    $this->actingAs(journalUiUser());

    $expense = Account::factory()->expense()->create();
    $asset = Account::factory()->asset()->create();

    $journal = Document::create([
        'document_type' => 'journal',
        'direction' => 'internal',
        'status' => 'draft',
        'issue_date' => '2026-03-01',
        'currency' => 'ZAR',
        'exchange_rate' => 1.0,
        'source' => 'manual',
    ]);
    $journal->lines()->create([
        'line_number' => 1, 'type' => 'description', 'description' => 'A',
        'account_id' => $expense->id, 'quantity' => 1, 'unit_price' => 500, 'tax_rate' => null,
    ]);
    $journal->lines()->create([
        'line_number' => 2, 'type' => 'description', 'description' => 'B',
        'account_id' => $asset->id, 'quantity' => 1, 'unit_price' => -500, 'tax_rate' => null,
    ]);
    app(DocumentService::class)->postJournal($journal->fresh(), null);

    Livewire::test('pages.journals.index')
        ->call('openDetail', $journal->id)
        ->call('reverse')
        ->assertOk();

    $reversal = Document::journals()->where('id', '!=', $journal->id)->sole();
    expect($reversal->status)->toBe('draft')
        ->and($reversal->lines)->toHaveCount(2);
});
