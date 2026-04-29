<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Core\Models\Party;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PostingRule extends Model
{
    use LogsActivity, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'party_id',
        'name',
        'description',
        'conditions',
        'actions',
        'is_active',
        'last_matched_at',
        'match_count',
    ];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'actions' => 'array',
            'is_active' => 'boolean',
            'last_matched_at' => 'datetime',
            'match_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): void
    {
        $query->where('is_active', true);
    }
}
