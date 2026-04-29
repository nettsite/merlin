<?php

namespace App\Modules\Purchasing\Jobs;

use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Services\InvoiceProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessInvoicePdf implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly Document $document) {}

    public function handle(InvoiceProcessingService $service): void
    {
        $service->process($this->document);
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ProcessInvoicePdf job failed', [
            'document_id' => $this->document->id,
            'error' => $e->getMessage(),
        ]);

        $this->document->activities()->create([
            'activity_type' => 'llm_extracted',
            'description' => 'Automatic extraction failed: '.$e->getMessage(),
            'metadata' => ['error' => $e->getMessage()],
        ]);
    }
}
