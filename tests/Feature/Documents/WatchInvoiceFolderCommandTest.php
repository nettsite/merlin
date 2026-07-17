<?php

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\NotificationIncident;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\Pdf\MagikaService;
use App\Modules\Purchasing\Services\InvoiceProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());

    // Payable account required by the command
    Account::factory()->create([
        'code' => '2000',
        'name' => 'Accounts Payable',
        'is_active' => true,
        'allow_direct_posting' => true,
    ]);

    // Fake local disk so media temp copies don't touch the real filesystem
    Storage::fake('local');

    // Real temp dir for the watch folder (command works with real paths)
    $this->watchDir = sys_get_temp_dir().'/merlin-watch-test-'.uniqid();
    mkdir($this->watchDir, 0755, true);

    // Mock InvoiceProcessingService to avoid LLM calls
    $this->serviceMock = Mockery::mock(InvoiceProcessingService::class);
    $this->app->instance(InvoiceProcessingService::class, $this->serviceMock);

    // Force MagikaService into fallback mode so tests don't depend on the binary
    $this->app->bind(MagikaService::class, fn () => new MagikaService('__nonexistent__'));
});

afterEach(function (): void {
    // Remove any leftover files in temp dir
    if (is_dir($this->watchDir)) {
        array_map('unlink', array_filter(glob($this->watchDir.'/*') ?: [], 'is_file'));
        array_map('unlink', glob($this->watchDir.'/failed/*') ?: []);
        @rmdir($this->watchDir.'/failed');
        rmdir($this->watchDir);
    }
});

it('runs against an empty folder without error', function (): void {
    $this->artisan('invoices:watch', ['folder' => $this->watchDir])
        ->assertSuccessful();
});

// --- Invalid purchasing settings gate ---

it('processes nothing and fails when the configured payable control account does not exist', function (): void {
    // Removes the '2000' account the beforeEach created, simulating a fresh
    // install where Settings > Purchasing hasn't been configured yet.
    Account::where('code', '2000')->delete();

    $pdf = $this->watchDir.'/test-invoice.pdf';
    file_put_contents($pdf, '%PDF-1.4 fake content');

    $this->serviceMock->expects('process')->never();

    $this->artisan('invoices:watch', ['folder' => $this->watchDir])
        ->assertFailed();

    // File stays exactly where it is — not moved to failed/, since this is
    // a system config problem, not a problem with the file itself.
    expect(file_exists($pdf))->toBeTrue()
        ->and(file_exists($this->watchDir.'/failed'))->toBeFalse()
        ->and(Document::count())->toBe(0);
});

it('raises an invalid_purchasing_settings incident when the control account does not exist', function (): void {
    Account::where('code', '2000')->delete();

    $this->artisan('invoices:watch', ['folder' => $this->watchDir])
        ->assertFailed();

    expect(NotificationIncident::where('type', 'invalid_purchasing_settings')
        ->whereNull('cleared_at')
        ->exists())->toBeTrue();
});

it('clears the invalid_purchasing_settings incident once the control account is fixed', function (): void {
    Account::where('code', '2000')->forceDelete();
    $this->artisan('invoices:watch', ['folder' => $this->watchDir])->assertFailed();

    expect(NotificationIncident::where('type', 'invalid_purchasing_settings')
        ->whereNull('cleared_at')
        ->exists())->toBeTrue();

    Account::factory()->create(['code' => '2000', 'is_active' => true, 'allow_direct_posting' => true]);

    $this->artisan('invoices:watch', ['folder' => $this->watchDir])->assertSuccessful();

    expect(NotificationIncident::where('type', 'invalid_purchasing_settings')
        ->whereNull('cleared_at')
        ->exists())->toBeFalse();
});

it('also blocks processing when the control account exists but is inactive', function (): void {
    Account::where('code', '2000')->update(['is_active' => false]);

    $pdf = $this->watchDir.'/test-invoice.pdf';
    file_put_contents($pdf, '%PDF-1.4 fake content');

    $this->serviceMock->expects('process')->never();

    $this->artisan('invoices:watch', ['folder' => $this->watchDir])
        ->assertFailed();

    expect(file_exists($pdf))->toBeTrue();
});

it('creates a document and deletes the pdf on success', function (): void {
    $pdf = $this->watchDir.'/test-invoice.pdf';
    file_put_contents($pdf, '%PDF-1.4 fake content');

    $this->serviceMock->expects('process')->once();

    $this->artisan('invoices:watch', ['folder' => $this->watchDir])
        ->assertSuccessful();

    expect(file_exists($pdf))->toBeFalse('PDF should be deleted after successful processing');
    expect(Document::where('source', 'watch')->count())->toBe(1);
});

it('attaches the source file to the source_document media collection', function (): void {
    $pdf = $this->watchDir.'/test-invoice.pdf';
    file_put_contents($pdf, '%PDF-1.4 fake content');

    $capturedDocument = null;
    $this->serviceMock->expects('process')->once()
        ->andReturnUsing(function (Document $doc) use (&$capturedDocument): void {
            $capturedDocument = $doc;
        });

    $this->artisan('invoices:watch', ['folder' => $this->watchDir])
        ->assertSuccessful();

    expect($capturedDocument)->not->toBeNull()
        ->and($capturedDocument->getFirstMedia('source_document'))
        ->not->toBeNull('Media must be attached to the source_document collection');
});

it('moves a failed pdf to the failed subfolder and writes an error log', function (): void {
    $pdf = $this->watchDir.'/bad-invoice.pdf';
    file_put_contents($pdf, '%PDF-1.4 fake content');

    $this->serviceMock->expects('process')->once()
        ->andThrow(new RuntimeException('LLM extraction failed'));

    $this->artisan('invoices:watch', ['folder' => $this->watchDir])
        ->assertSuccessful();

    expect(file_exists($pdf))->toBeFalse('Original PDF should be moved');
    expect(file_exists($this->watchDir.'/failed/bad-invoice.pdf'))->toBeTrue('PDF should be in failed/');
    expect(file_exists($this->watchDir.'/failed/bad-invoice.error.log'))->toBeTrue('Error log should exist');
    expect(file_get_contents($this->watchDir.'/failed/bad-invoice.error.log'))
        ->toContain('LLM extraction failed');
});

it('moves an already-processed duplicate to the failed subfolder with a log', function (): void {
    $pdf = $this->watchDir.'/duplicate.pdf';
    file_put_contents($pdf, '%PDF-1.4 duplicate content');
    $hash = hash_file('sha256', $pdf);

    $payableId = Account::where('code', '2000')->value('id');

    // Simulate an existing Media record for this file
    Media::create([
        'model_type' => Document::class,
        'model_id' => Document::factory()->purchaseInvoice()->create(['payable_account_id' => $payableId])->id,
        'uuid' => Str::uuid(),
        'collection_name' => 'source_document',
        'name' => 'duplicate',
        'file_name' => 'duplicate.pdf',
        'mime_type' => 'application/pdf',
        'disk' => 'local',
        'conversions_disk' => 'local',
        'size' => 1024,
        'manipulations' => [],
        'custom_properties' => ['sha256' => $hash],
        'generated_conversions' => [],
        'responsive_images' => [],
        'order_column' => 1,
    ]);

    $this->serviceMock->expects('process')->never();

    $this->artisan('invoices:watch', ['folder' => $this->watchDir])
        ->assertSuccessful();

    expect(file_exists($this->watchDir.'/failed/duplicate.pdf'))->toBeTrue('Duplicate should be in failed/');
    expect(file_exists($this->watchDir.'/failed/duplicate.already-processed.log'))->toBeTrue();
});

it('moves an unsupported file to failed/ with an unsupported-format log and creates no document', function (): void {
    $notPdf = $this->watchDir.'/fake-invoice.pdf';
    file_put_contents($notPdf, 'THIS IS NOT A PDF — no magic bytes');

    $this->serviceMock->expects('process')->never();

    $this->artisan('invoices:watch', ['folder' => $this->watchDir])
        ->assertSuccessful();

    expect(file_exists($notPdf))->toBeFalse('Original file should be moved')
        ->and(file_exists($this->watchDir.'/failed/fake-invoice.pdf'))->toBeTrue()
        ->and(file_exists($this->watchDir.'/failed/fake-invoice.unsupported-format.log'))->toBeTrue()
        ->and(Document::count())->toBe(0);
});

it('processes a csv invoice from the watch folder', function (): void {
    $csv = $this->watchDir.'/bank-invoice.csv';
    file_put_contents($csv, "description,quantity,unit_price\nHosting,1,500.00\n");

    $this->serviceMock->expects('process')->once();

    $this->artisan('invoices:watch', ['folder' => $this->watchDir])
        ->assertSuccessful();

    expect(file_exists($csv))->toBeFalse('CSV should be deleted after successful processing');
    expect(Document::where('source', 'watch')->count())->toBe(1);
});
