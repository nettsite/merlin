<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Purchasing\Models\Document;
use App\Modules\Purchasing\Models\DocumentLine;
use App\Modules\Purchasing\Models\PostingRule;
use App\Modules\Purchasing\Settings\PurchasingSettings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PostingRuleService
{
    public function __construct(
        private readonly DocumentService $documentService,
        private readonly PurchasingSettings $purchasingSettings,
    ) {}

    /**
     * Evaluate all active posting rules against the document.
     * Executes the first matching rule's actions.
     */
    public function evaluateAndPost(Document $document): void
    {
        if (! $this->isEligible($document)) {
            return;
        }

        $rules = PostingRule::active()
            ->where(fn ($q) => $q
                ->whereNull('party_id')
                ->orWhere('party_id', $document->party_id)
            )
            ->orderByDesc('match_count')
            ->get();

        foreach ($rules as $rule) {
            if ($this->matches($rule, $document)) {
                $this->execute($rule, $document);

                return;
            }
        }

        // No explicit rule matched — fall back to pattern-based posting.
        $this->attemptPatternPost($document);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * A document is only eligible for autonomous posting when:
     * - It has a supplier
     * - All lines have an account assigned
     * - llm_confidence meets the configured autopost threshold
     */
    private function isEligible(Document $document): bool
    {
        if ($document->party_id === null) {
            return false;
        }

        $unallocatedLines = $document->lines()->whereNull('account_id')->exists();

        if ($unallocatedLines) {
            return false;
        }

        $confidence = (float) ($document->llm_confidence ?? 0);
        $floor = $this->purchasingSettings->autopost_confidence;

        return $confidence >= $floor;
    }

    private function matches(PostingRule $rule, Document $document): bool
    {
        /** @var array<string, mixed> $conditions */
        $conditions = $rule->conditions;

        // Confidence threshold (rule-level, in addition to the hard floor)
        if (isset($conditions['min_confidence'])) {
            if ((float) ($document->llm_confidence ?? 0) < (float) $conditions['min_confidence']) {
                return false;
            }
        }

        // Total amount range
        if (isset($conditions['total_range'])) {
            $range = $conditions['total_range'];
            $total = (float) $document->total;

            if (isset($range['min']) && $total < (float) $range['min']) {
                return false;
            }

            if (isset($range['max']) && $total > (float) $range['max']) {
                return false;
            }
        }

        // Line description keyword matching — at least one line must contain at least one keyword
        if (! empty($conditions['line_description_contains'])) {
            $keywords = array_map('strtolower', (array) $conditions['line_description_contains']);
            $descriptions = $document->lines()->pluck('description')->map(fn ($d) => strtolower($d));

            $anyMatch = $descriptions->some(
                fn (string $desc) => collect($keywords)->some(fn (string $kw) => str_contains($desc, $kw))
            );

            if (! $anyMatch) {
                return false;
            }
        }

        return true;
    }

    private function execute(PostingRule $rule, Document $document): void
    {
        /** @var array<string, mixed> $actions */
        $actions = $rule->actions;
        $ruleName = $rule->name;

        Log::debug('PostingRuleService: rule matched', [
            'rule_id' => $rule->id,
            'rule_name' => $ruleName,
            'document_id' => $document->id,
        ]);

        $reason = "Matched posting rule \"{$ruleName}\"";

        if (! empty($actions['auto_approve'])) {
            // Step through all intermediate states to reach approved
            if ($document->status === 'received') {
                $this->documentService->markAsReviewedAutonomously($document, $reason);
                $document->refresh();
            }

            if ($document->status === 'reviewed') {
                $this->documentService->approveAutonomously($document, $reason);
                $document->refresh();
            }
        }

        if (! empty($actions['auto_post']) && $document->status === 'approved') {
            $this->documentService->postAutonomously($document, $reason);
        }

        $rule->increment('match_count');
        $rule->update(['last_matched_at' => now()]);
    }

    /**
     * Pattern-based auto-posting: compare this invoice against the most recently
     * posted invoice from the same supplier. Posts autonomously when:
     *   - a previous posted invoice exists for this supplier
     *   - currencies match
     *   - line counts match exactly (any extra line blocks auto-post)
     *   - every line matches a unique previous line by account code + fuzzy
     *     description + amount within the configured tolerance
     *
     * Foreign-currency invoices compare foreign amounts so exchange-rate drift
     * does not falsely block auto-post.
     */
    private function attemptPatternPost(Document $document): void
    {
        $previous = Document::where('party_id', $document->party_id)
            ->where('document_type', $document->document_type)
            ->where('status', 'posted')
            ->where('id', '!=', $document->id)
            ->latest('updated_at')
            ->with('lines')
            ->first();

        if (! $previous) {
            return;
        }

        if ($document->currency !== $previous->currency) {
            return;
        }

        /** @var Collection<int, DocumentLine> $currentLines */
        $currentLines = $document->lines;

        /** @var Collection<int, DocumentLine> $previousLines */
        $previousLines = $previous->lines;

        if ($currentLines->count() !== $previousLines->count()) {
            Log::debug('PostingRuleService: pattern post blocked — line count mismatch', [
                'document_id' => $document->id,
                'current' => $currentLines->count(),
                'previous' => $previousLines->count(),
                'previous_doc' => $previous->document_number,
            ]);

            return;
        }

        $tolerance = $this->purchasingSettings->amount_tolerance;
        $simThreshold = $this->purchasingSettings->description_similarity;
        $isForeign = $document->is_foreign_currency;

        $matchedIds = [];

        foreach ($currentLines as $currentLine) {
            $lineMatched = false;

            foreach ($previousLines as $previousLine) {
                if (in_array($previousLine->id, $matchedIds, true)) {
                    continue;
                }

                if ($currentLine->account_id !== $previousLine->account_id) {
                    continue;
                }

                similar_text(
                    (string) ($currentLine->description ?? ''),
                    (string) ($previousLine->description ?? ''),
                    $similarity
                );

                if ($similarity < $simThreshold) {
                    continue;
                }

                $currentAmount = $isForeign
                    ? (float) $currentLine->foreign_line_total
                    : (float) $currentLine->line_total;

                $previousAmount = $isForeign
                    ? (float) $previousLine->foreign_line_total
                    : (float) $previousLine->line_total;

                if ($previousAmount != 0.0) {
                    $diffPct = abs($currentAmount - $previousAmount) / abs($previousAmount) * 100;

                    if ($diffPct > $tolerance) {
                        continue;
                    }
                }

                $matchedIds[] = $previousLine->id;
                $lineMatched = true;
                break;
            }

            if (! $lineMatched) {
                Log::debug('PostingRuleService: pattern post blocked — unmatched line', [
                    'document_id' => $document->id,
                    'line' => $currentLine->description,
                    'previous_doc' => $previous->document_number,
                ]);

                return;
            }
        }

        $reason = "Pattern match against {$previous->document_number} "
            ."({$currentLines->count()} line(s) within {$tolerance}% tolerance)";

        Log::debug('PostingRuleService: pattern post approved', [
            'document_id' => $document->id,
            'previous_doc' => $previous->document_number,
        ]);

        if ($document->status === 'received') {
            $this->documentService->markAsReviewedAutonomously($document, $reason);
            $document->refresh();
        }

        if ($document->status === 'reviewed') {
            $this->documentService->approveAutonomously($document, $reason);
            $document->refresh();
        }

        if ($document->status === 'approved') {
            $this->documentService->postAutonomously($document, $reason);
        }
    }
}
