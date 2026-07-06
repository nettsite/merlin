<?php

use App\Modules\Core\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

function userWithDocumentPermissions(): User
{
    $user = User::factory()->create();
    $user->givePermissionTo(['documents-view-any', 'documents-create']);

    return $user;
}

beforeEach(function (): void {
    // Uploads are dropped into this folder for invoices:watch to pick up —
    // point it at an isolated temp dir so tests don't touch the real one.
    $this->watchFolder = sys_get_temp_dir().'/merlin-test-watch-'.uniqid();
    config(['documents.watch.folder' => $this->watchFolder]);
});

afterEach(function (): void {
    if (is_dir($this->watchFolder)) {
        app(Filesystem::class)->deleteDirectory($this->watchFolder);
    }
});

// ---------------------------------------------------------------------------
// Single-file regression
// ---------------------------------------------------------------------------

it('drops a single pdf upload into the watch folder', function (): void {
    $this->actingAs(userWithDocumentPermissions());
    $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');

    Livewire::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$file])
        ->call('processUpload')
        ->assertHasNoErrors(['uploadFiles'])
        ->assertSet('uploadDone', true)
        ->assertSet('uploadedCount', 1);

    expect(file_exists($this->watchFolder.'/invoice.pdf'))->toBeTrue();
});

it('drops a single docx upload into the watch folder', function (): void {
    $this->actingAs(userWithDocumentPermissions());
    $file = UploadedFile::fake()->create(
        'invoice.docx',
        100,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
    );

    Livewire::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$file])
        ->call('processUpload')
        ->assertHasNoErrors(['uploadFiles']);

    expect(file_exists($this->watchFolder.'/invoice.docx'))->toBeTrue();
});

it('drops a single xlsx upload into the watch folder', function (): void {
    $this->actingAs(userWithDocumentPermissions());
    $file = UploadedFile::fake()->create(
        'invoice.xlsx',
        100,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    );

    Livewire::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$file])
        ->call('processUpload')
        ->assertHasNoErrors(['uploadFiles']);

    expect(file_exists($this->watchFolder.'/invoice.xlsx'))->toBeTrue();
});

it('drops a single csv upload into the watch folder', function (): void {
    $this->actingAs(userWithDocumentPermissions());
    $file = UploadedFile::fake()->create('invoice.csv', 100, 'text/csv');

    Livewire::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$file])
        ->call('processUpload')
        ->assertHasNoErrors(['uploadFiles']);

    expect(file_exists($this->watchFolder.'/invoice.csv'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Multi-file
// ---------------------------------------------------------------------------

it('drops multiple files into the watch folder independently', function (): void {
    $this->actingAs(userWithDocumentPermissions());

    $files = [
        UploadedFile::fake()->create('a.pdf', 100, 'application/pdf'),
        UploadedFile::fake()->create('b.pdf', 100, 'application/pdf'),
        UploadedFile::fake()->create('c.pdf', 100, 'application/pdf'),
    ];

    Livewire::test('pages.purchase-invoices.index')
        ->set('uploadFiles', $files)
        ->call('processUpload')
        ->assertSet('uploadedCount', 3);

    expect(file_exists($this->watchFolder.'/a.pdf'))->toBeTrue()
        ->and(file_exists($this->watchFolder.'/b.pdf'))->toBeTrue()
        ->and(file_exists($this->watchFolder.'/c.pdf'))->toBeTrue();
});

it('avoids overwriting an existing unprocessed file with the same name', function (): void {
    $this->actingAs(userWithDocumentPermissions());
    mkdir($this->watchFolder, 0755, true);
    file_put_contents($this->watchFolder.'/invoice.pdf', 'existing unprocessed file');

    $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');

    Livewire::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$file])
        ->call('processUpload');

    expect(glob($this->watchFolder.'/invoice*'))->toHaveCount(2)
        ->and(file_get_contents($this->watchFolder.'/invoice.pdf'))->toBe('existing unprocessed file');
});

it('silently skips unsupported files in the batch', function (): void {
    $this->actingAs(userWithDocumentPermissions());

    $good1 = UploadedFile::fake()->create('good1.pdf', 100, 'application/pdf');
    $unsupported = UploadedFile::fake()->create('.DS_Store', 10, 'application/octet-stream');
    $good2 = UploadedFile::fake()->create('good2.pdf', 100, 'application/pdf');

    Livewire::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$good1, $unsupported, $good2])
        ->call('processUpload')
        ->assertSet('uploadedCount', 2);

    expect(glob($this->watchFolder.'/*'))->toHaveCount(2);
});

it('rejects batches over 50 files', function (): void {
    $this->actingAs(userWithDocumentPermissions());

    $files = array_map(
        fn ($i) => UploadedFile::fake()->create("inv{$i}.pdf", 10, 'application/pdf'),
        range(1, 51)
    );

    $component = Livewire::test('pages.purchase-invoices.index')
        ->set('uploadFiles', $files)
        ->call('processUpload');

    $component->assertHasErrors(['uploadFiles'])
        ->assertSet('uploadDone', false);

    expect(is_dir($this->watchFolder) ? glob($this->watchFolder.'/*') : [])->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Modal state
// ---------------------------------------------------------------------------

it('keeps modal open and shows confirmation after submission', function (): void {
    $this->actingAs(userWithDocumentPermissions());

    $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');

    $component = Livewire::test('pages.purchase-invoices.index')
        ->set('showUpload', true)
        ->set('uploadFiles', [$file])
        ->call('processUpload');

    expect($component->get('showUpload'))->toBeTrue();
    expect($component->get('uploadDone'))->toBeTrue();
});

it('resetForMore clears state and returns to the form', function (): void {
    $this->actingAs(userWithDocumentPermissions());

    $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');

    $component = Livewire::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$file])
        ->call('processUpload');

    expect($component->get('uploadDone'))->toBeTrue();

    $component->call('resetForMore');

    expect($component->get('uploadDone'))->toBeFalse();
    expect($component->get('uploadFiles'))->toBeEmpty();
});

it('openUpload clears stale state on reopen', function (): void {
    $this->actingAs(userWithDocumentPermissions());

    $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');

    $component = Livewire::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$file])
        ->call('processUpload');

    expect($component->get('uploadDone'))->toBeTrue();

    $component->call('openUpload');

    expect($component->get('uploadDone'))->toBeFalse();
    expect($component->get('uploadFiles'))->toBeEmpty();
});

// ---------------------------------------------------------------------------
// ZIP extraction
// ---------------------------------------------------------------------------

it('extracts and drops PDFs from a ZIP into the watch folder', function (): void {
    $this->actingAs(userWithDocumentPermissions());

    $zipPath = tempnam(sys_get_temp_dir(), 'merlin-zip-').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('invoice1.pdf', '%PDF-1.4 stub');
    $zip->addFromString('invoice2.pdf', '%PDF-1.4 stub');
    $zip->close();

    $zipFile = UploadedFile::fake()->createWithContent('invoices.zip', file_get_contents($zipPath));

    $component = Livewire::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$zipFile])
        ->call('processUpload');

    expect($component->get('uploadedCount'))->toBe(2)
        ->and(file_exists($this->watchFolder.'/invoice1.pdf'))->toBeTrue()
        ->and(file_exists($this->watchFolder.'/invoice2.pdf'))->toBeTrue();

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

    $zipFile = UploadedFile::fake()->createWithContent('mixed.zip', file_get_contents($zipPath));

    $component = Livewire::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$zipFile])
        ->call('processUpload');

    expect($component->get('uploadedCount'))->toBe(1);
    expect(glob($this->watchFolder.'/*'))->toHaveCount(1);

    @unlink($zipPath);
});

it('rejects a ZIP containing unsafe (zip slip) entry paths', function (): void {
    $this->actingAs(userWithDocumentPermissions());

    $zipPath = tempnam(sys_get_temp_dir(), 'merlin-zip-').'.zip';
    $zip = new ZipArchive;
    $zip->open($zipPath, ZipArchive::CREATE);
    $zip->addFromString('../../etc/evil.pdf', '%PDF-1.4 stub');
    $zip->close();

    $zipFile = UploadedFile::fake()->createWithContent('evil.zip', file_get_contents($zipPath));

    $component = Livewire::test('pages.purchase-invoices.index')
        ->set('uploadFiles', [$zipFile])
        ->call('processUpload');

    $component->assertHasErrors(['uploadFiles']);

    @unlink($zipPath);
});
