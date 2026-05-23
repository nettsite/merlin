<?php

use App\Modules\Core\Models\User;
use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Services\DocumentService;
use Illuminate\Http\UploadedFile;
use Livewire\Volt\Volt;

function userWithDocumentPermissions(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(['documents-view-any', 'documents-create']);

    return $user;
}

it('accepts pdf uploads', function (): void {
    $this->actingAs(userWithDocumentPermissions());
    $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');

    Volt::test('pages.purchase-invoices.index')
        ->set('uploadFile', $file)
        ->call('processUpload')
        ->assertHasNoErrors(['uploadFile']);
});

it('accepts docx uploads', function (): void {
    $this->actingAs(userWithDocumentPermissions());
    $file = UploadedFile::fake()->create(
        'invoice.docx',
        100,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    );

    Volt::test('pages.purchase-invoices.index')
        ->set('uploadFile', $file)
        ->call('processUpload')
        ->assertHasNoErrors(['uploadFile']);
});

it('accepts xlsx uploads', function (): void {
    $this->actingAs(userWithDocumentPermissions());
    $file = UploadedFile::fake()->create(
        'invoice.xlsx',
        100,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    Volt::test('pages.purchase-invoices.index')
        ->set('uploadFile', $file)
        ->call('processUpload')
        ->assertHasNoErrors(['uploadFile']);
});

it('accepts csv uploads', function (): void {
    $this->actingAs(userWithDocumentPermissions());
    $file = UploadedFile::fake()->create('invoice.csv', 100, 'text/csv');

    Volt::test('pages.purchase-invoices.index')
        ->set('uploadFile', $file)
        ->call('processUpload')
        ->assertHasNoErrors(['uploadFile']);
});

it('rejects unsupported upload formats', function (): void {
    $this->actingAs(userWithDocumentPermissions());
    $file = UploadedFile::fake()->create('document.txt', 100, 'text/plain');

    Volt::test('pages.purchase-invoices.index')
        ->set('uploadFile', $file)
        ->call('processUpload')
        ->assertHasErrors(['uploadFile']);
});

it('routes docx uploads through DocumentService::createFromFile', function (): void {
    $this->actingAs(userWithDocumentPermissions());

    $document = Document::factory()->purchaseInvoice()->create();

    $this->mock(DocumentService::class, function ($mock) use ($document): void {
        $mock->shouldReceive('createFromFile')
            ->once()
            ->andReturn(['document' => $document, 'duplicate' => false]);
    });

    $file = UploadedFile::fake()->create(
        'invoice.docx',
        100,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    );

    Volt::test('pages.purchase-invoices.index')
        ->set('uploadFile', $file)
        ->call('processUpload');
});
