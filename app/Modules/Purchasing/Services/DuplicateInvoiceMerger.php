<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Core\Models\Document;
use Illuminate\Support\Facades\DB;

/**
 * Suppliers sometimes resend the same invoice as a different file — e.g. an
 * "unpaid" copy followed later by a "paid" copy of the identical invoice
 * number. These are the same invoice, not a second one: the resend gets
 * attached as supporting evidence on the original Document rather than
 * creating a duplicate purchase_invoice row.
 */
class DuplicateInvoiceMerger
{
    /**
     * Find an existing purchase invoice for the same supplier and invoice
     * reference as the given (not-yet-lined) Document.
     */
    public function findDuplicate(Document $document): ?Document
    {
        $reference = $document->reference;

        if ($reference === null || $document->party_id === null) {
            return null;
        }

        return Document::purchaseInvoices()
            ->where('id', '!=', $document->id)
            ->where('party_id', $document->party_id)
            ->where(function ($query) use ($reference): void {
                $query->where('document_number', $reference)
                    ->orWhere('reference', $reference);
            })
            ->first();
    }

    /**
     * Fold a duplicate/reissued invoice file into the original: move its
     * media across, log it, and discard the duplicate row (it never got
     * lines created, so there's nothing else to reconcile).
     */
    public function merge(Document $duplicate, Document $canonical): void
    {
        DB::transaction(function () use ($duplicate, $canonical): void {
            $duplicate->getFirstMedia('source_document')?->move($canonical, 'attachments');

            $canonical->activities()->create([
                'activity_type' => 'duplicate_invoice_attached',
                'description' => 'A reissued/duplicate copy of this invoice was received and attached as supporting evidence.',
            ]);

            $duplicate->delete();
        });
    }
}
