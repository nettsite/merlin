<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Core\Models\Document;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A balanced double-entry posting. Append-only — see JournalService, the
 * only writer. Never update() or delete() a JournalEntry directly; to
 * unwind one, post a reversing entry via JournalService::reverse().
 */
class JournalEntry extends Model
{
    use HasUuids;

    protected $fillable = [
        'document_id',
        'entry_date',
        'source',
        'description',
        'reversed_by_id',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
        ];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_by_id');
    }

    public function isReversed(): bool
    {
        return $this->reversed_by_id !== null;
    }
}
