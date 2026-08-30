<?php

namespace App\Modules\Core\Models;

use App\Exceptions\PostedDocumentImmutableException;
use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentLine extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'document_id',
        'linked_document_id',
        'line_number',
        'type',
        'description',
        'account_id',
        'product_id',
        'quantity',
        'unit',
        'unit_price',
        'foreign_unit_price',
        'foreign_line_total',
        'foreign_tax_amount',
        'discount_percent',
        'discount_amount',
        'tax_rate',
        'llm_account_suggestion',
        'llm_confidence',
        'notes',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'foreign_unit_price' => 'decimal:4',
            'foreign_line_total' => 'decimal:2',
            'foreign_tax_amount' => 'decimal:2',
            'discount_percent' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'llm_confidence' => 'decimal:4',
            'metadata' => 'array',
        ];
    }

    /**
     * When false, saved/deleted events skip the per-line document total
     * recalculation. Bulk writers (the extraction pipeline) disable this and
     * call Document::recalculateTotals() once after the loop.
     */
    public static bool $recalculatesDocumentTotals = true;

    /**
     * Authoritative line VAT set by the extraction pipeline for VAT-inclusive
     * invoice lines. When non-null, calculateTotals() uses it directly (VAT
     * derived as gross − net) instead of recomputing rate × net, so the stored
     * gross matches the printed invoice amount to the cent. Transient — never
     * persisted.
     */
    public ?float $taxAmountOverride = null;

    protected static function booted(): void
    {
        static::saving(function (DocumentLine $line) {
            $line->guardAgainstPostedMutation();
            $line->calculateTotals();
        });

        static::saved(function (DocumentLine $line) {
            if (static::$recalculatesDocumentTotals && ! $line->trashed()) {
                Document::find($line->document_id)?->recalculateTotals();
            }
        });

        static::deleting(function (DocumentLine $line) {
            $line->guardAgainstPostedMutation();
        });

        static::deleted(function (DocumentLine $line) {
            if (static::$recalculatesDocumentTotals) {
                Document::find($line->document_id)?->recalculateTotals();
            }
        });
    }

    /**
     * Refuses to save/delete a line once its document is issued (see
     * Document::isIssued()) — this is what makes a document's postings
     * actually append-only: without it, editing an issued invoice's line
     * would silently leave every report reading the postings view wrong,
     * with nothing to catch it. Bypassed by saveQuietly() (used by the
     * FX-rate-finalisation and VAT-correction paths, both of which only
     * ever touch lines on invoices confirmed not yet issued) and by the
     * bulk relation delete()s used when reprocessing — both restricted to
     * non-issued documents already, at the service layer that calls them.
     */
    private function guardAgainstPostedMutation(): void
    {
        if ($this->document_id === null) {
            return;
        }

        // Always a fresh query, never the cached document() relation — a
        // line saved once while its document was still a draft caches that
        // status on the instance, and a later save after the document is
        // issued would otherwise read the stale cached copy instead of the
        // status that's actually true at this moment.
        if (Document::find($this->document_id)?->is_issued) {
            throw PostedDocumentImmutableException::forLine($this->document_id);
        }
    }

    // Relations

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function linkedDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'linked_document_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function llmSuggestedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'llm_account_suggestion');
    }

    /** @return HasOne<BankReconciliationMatch, $this> */
    public function reconciliationMatch(): HasOne
    {
        return $this->hasOne(BankReconciliationMatch::class, 'statement_line_id');
    }

    // Calculations

    private function calculateTotals(): void
    {
        $subtotal = (float) $this->quantity * (float) $this->unit_price;

        $discount = (float) $this->discount_amount > 0
            ? (float) $this->discount_amount
            : $subtotal * ((float) $this->discount_percent / 100);

        $this->line_total = round($subtotal - $discount, 2);

        $this->tax_amount = match (true) {
            $this->taxAmountOverride !== null => round($this->taxAmountOverride, 2),
            $this->tax_rate !== null => round($this->line_total * ((float) $this->tax_rate / 100), 2),
            default => 0,
        };
    }
}
