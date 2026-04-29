<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentLine extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'document_id',
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

    protected static function booted(): void
    {
        static::saving(function (DocumentLine $line) {
            $line->calculateTotals();
        });

        static::saved(function (DocumentLine $line) {
            if (! $line->trashed()) {
                Document::find($line->document_id)?->recalculateTotals();
            }
        });

        static::deleted(function (DocumentLine $line) {
            Document::find($line->document_id)?->recalculateTotals();
        });
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

    /** @return BelongsTo<Account, $this> */
    public function llmSuggestedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'llm_account_suggestion');
    }

    // Calculations

    private function calculateTotals(): void
    {
        $subtotal = (float) $this->quantity * (float) $this->unit_price;

        $discount = (float) $this->discount_amount > 0
            ? (float) $this->discount_amount
            : $subtotal * ((float) $this->discount_percent / 100);

        $this->line_total = round($subtotal - $discount, 2);

        $this->tax_amount = $this->tax_rate !== null
            ? round($this->line_total * ((float) $this->tax_rate / 100), 2)
            : 0;
    }
}
