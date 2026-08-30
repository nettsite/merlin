<?php

use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\User;
use Livewire\Livewire;

/**
 * status/balance_due moved off `documents` onto `document_balances` — every
 * list page's status-tab filter needs a join added at the query builder
 * level (unlike a plain $document->status read, which proxies through
 * transparently). None of these three pages had prior list-level test
 * coverage, so the join fix landed with nothing to catch a regression.
 */
function listFilterUser(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(['documents-view-any', 'documents-view']);

    return $user;
}

it('filters quotes by status without error', function (): void {
    Document::factory()->create(['document_type' => 'quote', 'direction' => 'outbound', 'status' => 'sent']);
    Document::factory()->create(['document_type' => 'quote', 'direction' => 'outbound', 'status' => 'draft']);

    $this->actingAs(listFilterUser());

    Livewire::test('pages.quotes.index')
        ->set('statusFilter', 'sent')
        ->assertOk()
        ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
});

it('filters credit notes by status without error', function (): void {
    Document::factory()->create(['document_type' => 'credit_note', 'direction' => 'outbound', 'status' => 'issued']);
    Document::factory()->create(['document_type' => 'credit_note', 'direction' => 'outbound', 'status' => 'draft']);

    $this->actingAs(listFilterUser());

    Livewire::test('pages.credit-notes.index')
        ->set('statusFilter', 'issued')
        ->assertOk()
        ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
});

it('filters bank statements by status without error', function (): void {
    Document::factory()->create(['document_type' => 'bank_statement', 'direction' => 'inbound', 'status' => 'received']);
    Document::factory()->create(['document_type' => 'bank_statement', 'direction' => 'inbound', 'status' => 'queued']);

    $this->actingAs(listFilterUser());

    Livewire::test('pages.bank-statements.index')
        ->set('statusFilter', 'received')
        ->assertOk()
        ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
});
