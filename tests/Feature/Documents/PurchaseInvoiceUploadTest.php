<?php

use App\Modules\Core\Models\User;
use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Services\DocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Volt\Volt;

function userWithDocumentPermissions(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(['documents-view-any', 'documents-create']);

    return $user;
}

// ---------------------------------------------------------------------------
// Single-file regression (via array path)
// ---------------------------------------------------------------------------

it('accepts a single pdf upload', function (): void {
    $this->actingAs(userWithDocumentPermissions());
    $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');

    Volt::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$file])
        ->call('processUpload')
        ->assertHasNoErrors(['uploadFiles']);
});

it('accepts a single docx upload', function (): void {
    $this->actingAs(userWithDocumentPermissions());
    $file = UploadedFile::fake()->create(
        'invoice.docx',
        100,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    );

    Volt::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$file])
        ->call('processUpload')
        ->assertHasNoErrors(['uploadFiles']);
});

it('accepts a single xlsx upload', function (): void {
    $this->actingAs(userWithDocumentPermissions());
    $file = UploadedFile::fake()->create(
        'invoice.xlsx',
        100,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    Volt::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$file])
        ->call('processUpload')
        ->assertHasNoErrors(['uploadFiles']);
});

it('accepts a single csv upload', function (): void {
    $this->actingAs(userWithDocumentPermissions());
    $file = UploadedFile::fake()->create('invoice.csv', 100, 'text/csv');

    Volt::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$file])
        ->call('processUpload')
        ->assertHasNoErrors(['uploadFiles']);
});

it('routes single upload through DocumentService::createFromFile', function (): void {
    $this->actingAs(userWithDocumentPermissions());

    $document = Document::factory()->purchaseInvoice()->create();

    $this->mock(DocumentService::class, function ($mock) use ($document): void {
        $mock->shouldReceive('createFromFile')
            ->once()
            ->andReturn(['document' => $document, 'duplicate' => false]);
    });

    $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');

    Volt::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$file])
        ->call('processUpload');
});

// ---------------------------------------------------------------------------
// Multi-file
// ---------------------------------------------------------------------------

it('queues multiple files independently', function (): void {
    $this->actingAs(userWithDocumentPermissions());

    $files = [
        UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
        UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
        UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
    ];

    $doc = Document::factory()->purchaseInvoice()->create();

    $this->mock(DocumentService::class, function ($mock) use ($doc): void {
        $mock->shouldReceive('createFromFile')
            ->times(3)
            ->andReturn(['document' => $doc, 'duplicate' => false]);
    });

    $component = Volt::test('pages.purchase-invoices.index')
        ->set('uploadFiles', $files)
        ->call('processUpload');

    $results = $component->get('uploadResults');

    expect($results)->toHaveCount(3)
        ->each->toMatchArray(['status' => 'queued']);
});

it('detects duplicates per file in a batch', function (): void {
    $this->actingAs(userWithDocumentPermissions());

    $doc = Document::factory()->purchaseInvoice()->create();
    $calls = 0;

    $this->mock(DocumentService::class, function ($mock) use ($doc, &$calls): void {
        $mock->shouldReceive('createFromFile')
            ->twice()
            ->andReturnUsing(function () use ($doc, &$calls) {
                $calls++;

                return ['document' => $doc, 'duplicate' => $calls === 1]; // first = duplicate, second = new
            });
    });

    $files = [
        UploadedFile::fake()->create('dup.pdf', 100, 'application/pdf'),
        UploadedFile::fake()->create('new.pdf', 100, 'application/pdf'),
    ];

    $component = Volt::test('pages.purchase-invoices.index')
        ->set('uploadFiles', $files)
        ->call('processUpload');

    $results = $component->get('uploadResults');

    $statuses = collect($results)->pluck('status')->sort()->values()->all();
    expect($statuses)->toBe(['duplicate', 'queued']);
});

it('continues processing remaining files when one throws', function (): void {
    $this->actingAs(userWithDocumentPermissions());

    $good1 = UploadedFile::fake()->create('good1.pdf', 100, 'application/pdf');
    $bad = UploadedFile::fake()->create('bad.xlsx', 100, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $good2 = UploadedFile::fake()->create('good2.pdf', 100, 'application/pdf');

    $callCount = 0;
    $this->mock(DocumentService::class, function ($mock) use (&$callCount): void {
        $mock->shouldReceive('createFromFile')
            ->times(3)
            ->andReturnUsing(function () use (&$callCount) {
                $callCount++;
                if ($callCount === 2) {
                    throw new RuntimeException('Unsupported format');
                }
                $doc = Document::factory()->purchaseInvoice()->create();

                return ['document' => $doc, 'duplicate' => false];
            });
    });

    $component = Volt::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$good1, $bad, $good2])
        ->call('processUpload');

    $results = $component->get('uploadResults');

    expect($results)->toHaveCount(3);
    expect($results[0]['status'])->toBe('queued');
    expect($results[1]['status'])->toBe('error');
    expect($results[2]['status'])->toBe('queued');
});

it('rejects batches over 50 files', function (): void {
    $this->actingAs(userWithDocumentPermissions());

    $files = array_map(
        fn ($i) => UploadedFile::fake()->create("inv{$i}.pdf", 10, 'application/pdf'),
        range(1, 51)
    );

    $component = Volt::test('pages.purchase-invoices.index')
        ->set('uploadFiles', $files)
        ->call('processUpload');

    $component->assertHasErrors(['uploadFiles']);
    expect($component->get('uploadResults'))->toBeEmpty();
    expect(Document::purchaseInvoices()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Modal state
// ---------------------------------------------------------------------------

it('keeps modal open and populates uploadResults after submission', function (): void {
    $this->actingAs(userWithDocumentPermissions());

    $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');

    $component = Volt::test('pages.purchase-invoices.index')
        ->set('showUpload', true)
        ->set('uploadFiles', [$file])
        ->call('processUpload');

    expect($component->get('showUpload'))->toBeTrue();
    expect($component->get('uploadResults'))->not->toBeEmpty();
});

it('resetForMore clears results and returns to form', function (): void {
    $this->actingAs(userWithDocumentPermissions());

    $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');

    $component = Volt::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$file])
        ->call('processUpload');

    expect($component->get('uploadResults'))->not->toBeEmpty();

    $component->call('resetForMore');

    expect($component->get('uploadResults'))->toBeEmpty();
    expect($component->get('uploadFiles'))->toBeEmpty();
});

it('openUpload clears stale results on reopen', function (): void {
    $this->actingAs(userWithDocumentPermissions());

    $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');

    $component = Volt::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$file])
        ->call('processUpload');

    expect($component->get('uploadResults'))->not->toBeEmpty();

    $component->call('openUpload');

    expect($component->get('uploadResults'))->toBeEmpty();
    expect($component->get('uploadFiles'))->toBeEmpty();
});

// ---------------------------------------------------------------------------
// ZIP extraction
// ---------------------------------------------------------------------------

it('extracts and processes PDFs from a ZIP', function (): void {
    $this->actingAs(userWithDocumentPermissions());

    $zipPath = tempnam(sys_get_temp_dir(), 'merlin-zip-').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('invoice1.pdf', '%PDF-1.4 stub');
    $zip->addFromString('invoice2.pdf', '%PDF-1.4 stub');
    $zip->close();

    $document = Document::factory()->purchaseInvoice()->create();

    $this->mock(DocumentService::class, function ($mock) use ($document): void {
        $mock->shouldReceive('createFromFile')
            ->twice()
            ->andReturn(['document' => $document, 'duplicate' => false]);
    });

    $zipFile = UploadedFile::fake()->createWithContent('invoices.zip', file_get_contents($zipPath));

    $component = Volt::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$zipFile])
        ->call('processUpload');

    $results = $component->get('uploadResults');
    expect($results)->toHaveCount(2)
        ->each->toMatchArray(['status' => 'queued']);

    @unlink($zipPath);
});

it('silently skips unsupported files inside a ZIP', function (): void {
    $this->actingAs(userWithDocumentPermissions());

    $zipPath = tempnam(sys_get_temp_dir(), 'merlin-zip-').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('invoice.pdf', '%PDF-1.4 stub');
    $zip->addFromString('readme.txt', 'ignore me');
    $zip->addFromString('.DS_Store', 'mac garbage');
    $zip->close();

    $document = Document::factory()->purchaseInvoice()->create();

    $this->mock(DocumentService::class, function ($mock) use ($document): void {
        $mock->shouldReceive('createFromFile')
            ->once()
            ->andReturn(['document' => $document, 'duplicate' => false]);
    });

    $zipFile = UploadedFile::fake()->createWithContent('mixed.zip', file_get_contents($zipPath));

    $component = Volt::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$zipFile])
        ->call('processUpload');

    $results = $component->get('uploadResults');
    expect($results)->toHaveCount(1)
        ->and($results[0]['status'])->toBe('queued');

    @unlink($zipPath);
});
