<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records that a bank/credit-card statement line has been reconciled
 * against a document — an existing payment recorded before the statement
 * arrived, or one created on the spot for a bank charge or other line with
 * no prior record. Kept as its own table rather than reusing
 * DocumentLine::linked_document_id so the statement line's own columns
 * never need touching once extracted: matching is a workflow annotation,
 * not a restatement of what the bank said happened.
 */
class BankReconciliationMatch extends Model
{
    use HasUuids;

    protected $fillable = [
        'statement_line_id',
        'document_id',
        'matched_by',
        'matched_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'matched_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<DocumentLine, $this> */
    public function statementLine(): BelongsTo
    {
        return $this->belongsTo(DocumentLine::class, 'statement_line_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /** @return BelongsTo<User, $this> */
    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }
}
