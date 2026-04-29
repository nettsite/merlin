<?php

namespace App\Modules\Purchasing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentRelationship extends Model
{
    use HasUuids;

    protected $fillable = [
        'parent_document_id',
        'child_document_id',
        'relationship_type',
    ];

    /** @return BelongsTo<Document, $this> */
    public function parentDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'parent_document_id');
    }

    /** @return BelongsTo<Document, $this> */
    public function childDocument(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'child_document_id');
    }
}
