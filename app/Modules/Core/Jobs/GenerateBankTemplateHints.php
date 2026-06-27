<?php

namespace App\Modules\Core\Jobs;

use App\Modules\Core\DTO\ExtractedBankStatement;
use App\Modules\Core\Models\BankTemplate;
use App\Modules\Core\Services\LlmService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateBankTemplateHints implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly BankTemplate $template,
        private readonly string $statementText,
        private readonly ExtractedBankStatement $extracted,
        private readonly ?string $userHint,
    ) {}

    public function handle(LlmService $llm): void
    {
        $hints = $llm->generateBankTemplateHints(
            bankName: $this->template->bank_name,
            statementText: $this->statementText,
            extracted: $this->extracted,
            existingHints: $this->template->layout_hints,
            userHint: $this->userHint,
            loggable: $this->template,
        );

        if (! empty(trim($hints))) {
            $this->template->update(['layout_hints' => trim($hints)]);
        }
    }
}
