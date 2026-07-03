<?php

use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentLine;
use App\Modules\Core\Models\User;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/**
 * Regression: opening a bank-statement detail must not crash when a line has
 * no running_balance in its metadata (e.g. auto-allocation / credit-split
 * lines). PHP 8.4 throws on the undefined array key otherwise.
 */
function bankStatementUser(string ...$permissions): User
{
    $user = User::factory()->create();
    foreach ($permissions as $perm) {
        Permission::findOrCreate($perm, 'web');
        $user->givePermissionTo($perm);
    }

    return $user;
}

it('renders the detail flyout for a line missing running_balance metadata', function (): void {
    $this->actingAs(bankStatementUser('documents-view-any', 'documents-view'));

    $statement = Document::factory()->create([
        'document_type' => 'bank_statement',
        'status' => 'received',
    ]);

    DocumentLine::factory()->create([
        'document_id' => $statement->id,
        'description' => 'Unallocated receipt',
        'metadata' => null,
    ]);

    Livewire::test('pages.bank-statements.index')
        ->call('openDetail', $statement->id)
        ->assertOk()
        ->assertSee('Unallocated receipt')
        ->assertSee('—');
});
