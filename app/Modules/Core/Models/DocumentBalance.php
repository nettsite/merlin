<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The mutable half of a document: status and running balances. Split out
 * of `documents` so that table can be physically append-only — every other
 * column on a document is set once and never touched again. One row per
 * document; document_id is the primary key.
 */
class DocumentBalance extends Model
{
    protected $primaryKey = 'document_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'document_id',
        'status',
        'amount_paid',
        'balance_due',
        'foreign_amount_paid',
        'foreign_balance_due',
        'credits_applied',
    ];

    protected function casts(): array
    {
        return [
            'amount_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'foreign_amount_paid' => 'decimal:2',
            'foreign_balance_due' => 'decimal:2',
            'credits_applied' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
