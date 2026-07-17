<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\DocumentLine;
use App\Modules\Purchasing\DTO\ExtractedInvoiceLine;

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
        $suggestedAccount = null;

        // 1. History match: same supplier + exact description in a posted document line.
        // Only accounts still directly postable are eligible — a historical line
        // may have posted to an account that's since gained sub-accounts.
        if ($partyId !== null) {
            $accountId = DocumentLine::query()
                ->whereHas('document', fn ($q) => $q->where('party_id', $partyId)
                    ->whereIn('status', Document::POSTED_STATUSES))
                ->whereHas('account', fn ($q) => $q->postable())
                ->where('description', $description)
                ->whereNotNull('account_id')
                ->orderByDesc('created_at')
                ->value('account_id');
        }

        // 2. LLM suggestion — always stored for display regardless of whether
        // history matched. Only used to auto-assign account_id when postable.
        if ($extracted->suggestedAccountCode !== null) {
            $suggestedAccount = Account::where('code', $extracted->suggestedAccountCode)->first();

            if ($suggestedAccount) {
                $llmSuggestionId = $suggestedAccount->id;
                $llmConfidence = $extracted->accountConfidence;
            }
        }

        return [
            'account_id' => $accountId ?? ($suggestedAccount?->allow_direct_posting ? $llmSuggestionId : null),
            'llm_account_suggestion' => $llmSuggestionId,
            'llm_confidence' => $llmConfidence,
        ];
    }
}
