<?php

namespace App\Console\Commands;

use App\Exceptions\InvalidFileTypeException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Services\InvoiceProcessingService;
use App\Modules\Purchasing\Services\Pdf\MagikaService;
use App\Modules\Purchasing\Settings\PurchasingSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class WatchInvoiceFolderCommand extends Command
{
    protected $signature = 'invoices:watch
                            {folder? : Override the configured watch folder}
                            {--watch : Run continuously until stopped with Ctrl+C}
                            {--interval=10 : Polling interval in seconds when using --watch}';

    protected $description = 'Process all PDF invoices found in the watch folder';

    public function handle(InvoiceProcessingService $service, MagikaService $magika): int
    {
        $folder = $this->resolveFolder();

        if (! is_dir($folder)) {
            mkdir($folder, 0755, true);
        }

        if ($this->option('watch')) {
            $interval = max(1, (int) $this->option('interval'));
            $this->comment("Watching {$folder} every {$interval}s — Ctrl+C to stop.");

            while (true) { // @phpstan-ignore-line
                $this->scan($folder, $service, $magika);
                sleep($interval);
            }
        }

        $this->scan($folder, $service, $magika);

        return self::SUCCESS;
    }

    protected function scan(string $folder, InvoiceProcessingService $service, MagikaService $magika): void
    {
        $files = array_merge(
            glob($folder.'/*.pdf') ?: [],
            glob($folder.'/*.PDF') ?: [],
        );

        if (empty($files)) {
            return;
        }

        $this->comment('Found '.count($files).' file(s) to process.');

        foreach ($files as $path) {
            $this->processFile($path, $folder, $service, $magika);
        }
    }

    protected function processFile(string $path, string $folder, InvoiceProcessingService $service, MagikaService $magika): void
    {
        $basename = basename($path);

        try {
            $magika->assertIsPdf($path);
        } catch (InvalidFileTypeException $e) {
            Log::warning('invoices:watch — rejected non-PDF file', ['file' => $basename, 'error' => $e->getMessage()]);
            $this->warn("⊘ {$basename}: {$e->getMessage()}");
            $this->moveFailed($path, $folder, 'not-a-pdf', $e);

            return;
        }

        $hash = hash_file('sha256', $path);

        if (Media::where('collection_name', 'source_document')
            ->where('custom_properties->sha256', $hash)
            ->exists()
        ) {
            $this->warn("Duplicate (already processed): {$basename}");
            Log::warning('invoices:watch — duplicate file skipped', ['file' => $basename, 'hash' => $hash]);
            $this->moveFailed($path, $folder, 'already-processed');

            return;
        }

        $document = null;

        try {
            $document = Document::create([
                'document_type' => 'purchase_invoice',
                'direction' => 'inbound',
                'status' => 'received',
                'currency' => app(CurrencySettings::class)->base_currency,
                'exchange_rate' => 1.0,
                'source' => 'watch',
                'payable_account_id' => Account::where('code', app(PurchasingSettings::class)->default_payable_account)->value('id'),
            ]);

            $tmpRelative = 'invoice-uploads/tmp/'.basename($path);
            Storage::disk('local')->put($tmpRelative, file_get_contents($path));

            $document
                ->addMediaFromDisk($tmpRelative, 'local')
                ->withCustomProperties(['sha256' => $hash])
                ->toMediaCollection('source_document');

            $service->process($document);

            unlink($path);

            $this->info("✓ {$basename} → {$document->document_number}");

        } catch (\Throwable $e) {
            // Remove the document so its media record (and SHA256 hash) are also
            // deleted — otherwise the next attempt is blocked by the duplicate check.
            $document?->forceDelete();

            Log::error('invoices:watch — processing failed', [
                'file' => $basename,
                'error' => $e->getMessage(),
            ]);

            $this->error("✗ {$basename}: {$e->getMessage()}");
            $this->moveFailed($path, $folder, 'error', $e);
        }
    }

    protected function moveFailed(string $path, string $folder, string $type, ?\Throwable $e = null): void
    {
        $failedDir = $folder.'/failed';
        @mkdir($failedDir, 0755, true);

        $basename = basename($path);
        $stem = pathinfo($path, PATHINFO_FILENAME);
        $destination = $failedDir.'/'.$basename;

        rename($path, $destination);

        $logContent = match ($type) {
            'already-processed' => "File has already been imported into the system.\nSHA256: ".hash_file('sha256', $destination),
            default => $e
                ? $e->getMessage()."\n\n".$e->getTraceAsString()
                : 'Unknown error',
        };

        file_put_contents($failedDir.'/'.$stem.'.'.$type.'.log', $logContent);
    }

    /**
     * Resolve the folder to watch.
     *
     * The OSS command has zero tenant awareness. In SaaS mode, the
     * SwitchTenantWatchFolderTask (in the private SaaS wrapper) sets
     * documents.watch.folder to a per-tenant subfolder before this runs.
     */
    protected function resolveFolder(): string
    {
        $path = $this->argument('folder')
            ?? config('documents.watch.folder', storage_path('app/invoice-watch'));

        return rtrim((string) $path, '/');
    }
}
