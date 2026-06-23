<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\User;
use App\Modules\Core\Services\DocumentService as BaseDocumentService;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\Jobs\ProcessInvoiceDocument;
use App\Modules\Purchasing\Services\Pdf\MagikaService;
use App\Modules\Purchasing\Settings\PurchasingSettings;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class DocumentService extends BaseDocumentService
{
    public function __construct(
        CurrencySettings $currencySettings,
        private readonly PurchasingSettings $purchasingSettings,
        private readonly MagikaService $magika,
    ) {
        parent::__construct($currencySettings);
    }

    /**
     * Create a Document from an uploaded invoice file (PDF, DOCX, XLSX, or CSV).
     *
     * Returns the document and a flag indicating whether the file was a
     * duplicate of an already-uploaded invoice.
     *
     * @param  array<string, mixed>  $data  Accepted keys: party_id (nullable), currency
     * @return array{document: Document, duplicate: bool}
     */
    public function createFromFile(string $absolutePath, array $data): array
    {
        $this->magika->assertIsSupportedFormat($absolutePath);

        $hash = hash_file('sha256', $absolutePath);

        $existing = Media::where('collection_name', 'source_document')
            ->where('custom_properties->sha256', $hash)
            ->first();

        if ($existing !== null) {
            /** @var Document $document */
            $document = Document::findOrFail($existing->model_id);

            return ['document' => $document, 'duplicate' => true];
        }

        $currency = strtoupper($data['currency'] ?? $this->currencySettings->base_currency);

        $document = Document::create([
            'document_type' => 'purchase_invoice',
            'direction' => 'inbound',
            'status' => 'received',
            'currency' => $currency,
            'exchange_rate' => 1.0,
            'party_id' => $data['party_id'] ?? null,
            'source' => 'upload',
            'payable_account_id' => $this->resolvePayableAccountId($data['party_id'] ?? null),
        ]);

        $document
            ->addMedia($absolutePath)
            ->withCustomProperties(['sha256' => $hash])
            ->toMediaCollection('source_document');

        ProcessInvoiceDocument::dispatch($document);

        return ['document' => $document, 'duplicate' => false];
    }

    public function reprocess(Document $doc, User $by): void
    {
        if (! in_array($doc->status, ['received', 'reviewed', 'rejected', 'disputed'])) {
            throw new \InvalidArgumentException("Cannot reprocess a {$doc->status} invoice.");
        }

        DB::transaction(function () use ($doc, $by) {
            $doc->lines()->delete();

            $doc->status = 'received';
            $doc->saveQuietly();

            $this->recordActivity($doc, $by, 'reprocess_queued', 'Invoice queued for reprocessing.');
        });

        ProcessInvoiceDocument::dispatch($doc);
    }

    private function resolvePayableAccountId(?string $partyId): ?string
    {
        if ($partyId !== null) {
            $override = Party::find($partyId)
                ?->relationships()
                ->where('relationship_type', 'supplier')
                ->first()
                ?->default_payable_account_id;

            if ($override !== null) {
                return $override;
            }
        }

        return Account::where('code', $this->purchasingSettings->default_payable_account)->value('id');
    }
}
