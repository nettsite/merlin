<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// Note: defaultPayableAccount() relationship added in Task 3.2 once Account model exists

class PartyRelationship extends Model
{
    use LogsActivity, HasUuids, SoftDeletes;

    protected $fillable = [
        'party_id',
        'relationship_type',
        'is_active',
        'default_payable_account_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    // Relations

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return HasMany<ContactAssignment, $this> */
    public function contactAssignments(): HasMany
    {
        return $this->hasMany(ContactAssignment::class);
    }

    // Scopes

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('relationship_type', $type);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
