<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Core\Models\Party;
use App\Modules\Core\Services\PartyService;
use App\Modules\Purchasing\DTO\ExtractedInvoice;
use App\Modules\Purchasing\Models\Document;

class SupplierResolver
{
    public function __construct(private readonly PartyService $partyService) {}

    /**
     * Attempt to match a supplier from extracted invoice data and set party_id on the document.
     *
     * If the document already has a party_id (user pre-selected in attended mode), nothing is done.
     * Otherwise: tax number match → name match → create pending supplier.
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
