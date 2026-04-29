<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Purchasing\DTO\ExtractedInvoiceLine;
use App\Modules\Purchasing\Models\DocumentLine;

class AccountResolver
{
    /**
     * Determine the GL account for a line item using history first, then the LLM suggestion.
     *
     * Resolution order:
     * 1. History match — same supplier, same description, posted document → use that account_id directly
     * 2. LLM suggestion — store the suggested account in llm_account_suggestion; account_id stays null
     * 3. Neither — both null; the user must allocate manually
     *
     * Returns attributes ready to be merged into a DocumentLine create/update call.
     *
     * @return array{account_id: string|null, llm_account_suggestion: string|null, llm_confidence: float|null}
     */
    public function resolve(string $description, ?string $partyId, ExtractedInvoiceLine $extracted): array
    {
        $accountId = null;
        $llmSuggestionId = null;
        $llmConfidence = null;

        // 1. History match: same supplier + exact description in a posted document line
        if ($partyId !== null) {
            $accountId = DocumentLine::query()
                ->whereHas('document', fn ($q) => $q->where('party_id', $partyId)->where('status', 'posted'))
                ->where('description', $description)
                ->whereNotNull('account_id')
                ->orderByDesc('created_at')
                ->value('account_id');
        }

        // 2. LLM suggestion — always store regardless of whether history matched
        if ($extracted->suggestedAccountCode !== null) {
            $account = Account::where('code', $extracted->suggestedAccountCode)->first();

            if ($account) {
                $llmSuggestionId = $account->id;
                $llmConfidence = $extracted->accountConfidence;
            }
        }

        return [
            'account_id' => $accountId ?? $llmSuggestionId,
            'llm_account_suggestion' => $llmSuggestionId,
            'llm_confidence' => $llmConfidence,
        ];
    }
}
