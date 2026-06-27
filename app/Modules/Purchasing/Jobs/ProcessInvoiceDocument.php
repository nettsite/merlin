<?php

namespace App\Modules\Purchasing\Jobs;

use App\Exceptions\LlmCreditExhaustedException;
use App\Modules\Core\Models\Document;
use App\Modules\Purchasing\Services\InvoiceProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessInvoiceDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly Document $document) {}

    public function handle(InvoiceProcessingService $service): void
    {
        if (Cache::has('anthropic:credit_exhausted')) {
            $this->fail(new LlmCreditExhaustedException('Anthropic credit exhausted; processing paused.'));

            return;
        }

        // process() appends lines and never clears them. With $tries > 1 a
        // retry after a partial failure would duplicate every extracted line,
        // so drop any lines left behind by a previous attempt first.
        $this->document->lines()->delete();

        try {
            $service->process($this->document);
        } catch (LlmCreditExhaustedException $e) {
            $this->fail($e);

            return;
        }

        if ($this->document->status === 'queued') {
            $this->document->update(['status' => 'received']);
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProcessInvoiceDocument job failed', [
            'document_id' => $this->document->id,
            'error' => $e->getMessage(),
        ]);

        // Flag the document so the UI can distinguish "extraction failed"
        // from "still processing". Cleared on the next successful run.
        $this->document->update([
            'status' => 'received',
            'metadata' => array_merge($this->document->metadata ?? [], [
                'extraction_failed' => true,
            ]),
        ]);

        $this->document->activities()->create([
            'activity_type' => 'extraction_failed',
            'description' => 'Automatic extraction failed: '.$e->getMessage(),
            'metadata' => ['error' => $e->getMessage()],
        ]);
    }
}
