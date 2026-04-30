<?php

namespace Tests\Feature\Documents;

use App\Modules\Core\Models\User;
use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Services\DocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PurchaseInvoiceUploadTest extends TestCase
{
    use RefreshDatabase;

    private function userWithDocumentPermissions(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo(['documents-view-any', 'documents-create']);

        return $user;
    }

    public function test_upload_validation_accepts_pdf(): void
    {
        $this->actingAs($this->userWithDocumentPermissions());
        $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');

        Volt::test('pages.purchase-invoices.index')
            ->set('uploadFile', $file)
            ->call('processUpload')
            ->assertHasNoErrors(['uploadFile']);
    }

    public function test_upload_validation_accepts_docx(): void
    {
        $this->actingAs($this->userWithDocumentPermissions());
        $file = UploadedFile::fake()->create(
            'invoice.docx',
            100,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        );

        Volt::test('pages.purchase-invoices.index')
            ->set('uploadFile', $file)
            ->call('processUpload')
            ->assertHasNoErrors(['uploadFile']);
    }

    public function test_upload_validation_accepts_xlsx(): void
    {
        $this->actingAs($this->userWithDocumentPermissions());
        $file = UploadedFile::fake()->create(
            'invoice.xlsx',
            100,
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        );

        Volt::test('pages.purchase-invoices.index')
            ->set('uploadFile', $file)
            ->call('processUpload')
            ->assertHasNoErrors(['uploadFile']);
    }

    public function test_upload_validation_accepts_csv(): void
    {
        $this->actingAs($this->userWithDocumentPermissions());
        $file = UploadedFile::fake()->create('invoice.csv', 100, 'text/csv');

        Volt::test('pages.purchase-invoices.index')
            ->set('uploadFile', $file)
            ->call('processUpload')
            ->assertHasNoErrors(['uploadFile']);
    }

    public function test_upload_validation_rejects_unsupported_format(): void
    {
        $this->actingAs($this->userWithDocumentPermissions());
        $file = UploadedFile::fake()->create('document.txt', 100, 'text/plain');

        Volt::test('pages.purchase-invoices.index')
            ->set('uploadFile', $file)
            ->call('processUpload')
            ->assertHasErrors(['uploadFile']);
    }

    public function test_docx_upload_calls_create_from_file_on_service(): void
    {
        $this->actingAs($this->userWithDocumentPermissions());

        $document = Document::factory()->purchaseInvoice()->create();

        $this->mock(DocumentService::class, function ($mock) use ($document) {
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
    }
}
