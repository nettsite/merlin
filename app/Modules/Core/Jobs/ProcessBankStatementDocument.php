<?php

namespace App\Modules\Core\Jobs;

use App\Exceptions\LlmCreditExhaustedException;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Services\BankStatementProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class ProcessBankStatementDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public readonly Document $document,
        public readonly ?string $userHint = null,
    ) {}

    public function handle(BankStatementProcessingService $service): void
    {
        if (Cache::has('anthropic:credit_exhausted')) {
            $this->fail(new LlmCreditExhaustedException('Anthropic credit exhausted; processing paused.'));

            return;
        }

        // process() appends lines; clear any from a previous failed attempt first.
        $this->document->lines()->delete();

        try {
            $service->process($this->document, $this->userHint);
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
        Log::error('ProcessBankStatementDocument job failed', [
            'document_id' => $this->document->id,
            'error' => $e->getMessage(),
        ]);

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
