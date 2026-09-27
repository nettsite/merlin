<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Services\PartyService;
use App\Modules\Core\Services\TypeSafeService;
use App\Modules\Purchasing\DTO\ExtractedInvoice;

class SupplierResolver
{
    /**
     * Experiment-only tunable (see [[project_typesafe_ai_evaluation]] memory).
     * Below this confidence, treat the Jev pick as no match.
     */
    private const JEV_CONFIDENCE_THRESHOLD = 0.7;

    private const JEV_NONE_OPTION = 'none';

    public function __construct(
        private readonly PartyService $partyService,
        private readonly TypeSafeService $typeSafe,
    ) {}

    /**
     * Attempt to match a supplier from extracted invoice data and set party_id on the document.
     *
     * If the document already has a party_id (user pre-selected in attended mode), nothing is done.
     * Otherwise: tax number match → exact name match → Jev fuzzy-name choice (experiment) →
     * create pending supplier.
     */
    public function resolve(Document $document, ExtractedInvoice $extracted): void
    {
        if ($document->party_id !== null) {
            return;
        }

        if ($extracted->supplierTaxNumber && $this->matchByTaxNumber($document, $extracted->supplierTaxNumber)) {
            return;
        }

        if ($extracted->supplierName && $this->matchByName($document, $extracted->supplierName)) {
            return;
        }

        if ($extracted->supplierName && $this->matchByJevChoice($document, $extracted)) {
            return;
        }

        $this->createPendingSupplier($document, $extracted);
    }

    private function matchByTaxNumber(Document $document, string $taxNumber): bool
    {
        $party = Party::whereIn('status', ['active', 'pending'])
            ->whereHas(
                'business',
                fn ($q) => $q->where('tax_number', $taxNumber)
            )->first();

        if (! $party) {
            return false;
        }

        $document->update(['party_id' => $party->id]);

        return true;
    }

    private function matchByName(Document $document, string $name): bool
    {
        $party = Party::whereIn('status', ['active', 'pending'])
            ->whereHas(
                'business',
                fn ($q) => $q->where('trading_name', $name)->orWhere('legal_name', $name)
            )->first();

        if (! $party) {
            return false;
        }

        $document->update(['party_id' => $party->id]);

        return true;
    }

    /**
     * Experimental tier: ask Jev to pick the intended supplier from existing
     * active/pending suppliers, for names that don't match exactly (OCR noise,
     * abbreviations, trading-name variants). Skipped when there are no candidates
     * or more than Choice's 255-option limit allows.
     */
    private function matchByJevChoice(Document $document, ExtractedInvoice $extracted): bool
    {
        $candidates = Party::suppliers()
            ->whereIn('status', ['active', 'pending'])
            ->with('business')
            ->limit(255)
            ->get()
            ->filter(fn (Party $party) => $party->business !== null);

        if ($candidates->isEmpty()) {
            return false;
        }

        $criteria = $candidates
            ->mapWithKeys(fn (Party $party) => [(string) $party->id => $party->displayName])
            ->all();
        $criteria[self::JEV_NONE_OPTION] = 'None of these suppliers issued this invoice.';

        $result = $this->typeSafe->choice(
            state: [
                'extracted_supplier_name' => $extracted->supplierName,
                'extracted_supplier_email' => $extracted->supplierEmail,
                'extracted_supplier_phone' => $extracted->supplierPhone,
            ],
            instructions: 'Which supplier issued this invoice, based on the extracted supplier details in `state`?',
            criteria: $criteria,
        );

        if ($result === null || $result->choice === self::JEV_NONE_OPTION) {
            return false;
        }

        if ($result->confidence < self::JEV_CONFIDENCE_THRESHOLD) {
            return false;
        }

        $party = $candidates->firstWhere('id', $result->choice);

        if ($party === null) {
            return false;
        }

        $document->update(['party_id' => $party->id]);

        return true;
    }

    private function createPendingSupplier(Document $document, ExtractedInvoice $extracted): void
    {
        $legalName = $extracted->supplierName ?? 'Unknown Supplier';

        $pending = $this->partyService->createPendingBusiness([
            'business_type' => 'company',
            'legal_name' => $legalName,
            'trading_name' => $legalName,
            'tax_number' => $extracted->supplierTaxNumber,
            'primary_email' => $extracted->supplierEmail,
            'primary_phone' => $extracted->supplierPhone,
        ]);

        $document->update(['party_id' => $pending->id]);
    }
}
