<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ContactAssignment extends Model
{
    use LogsActivity, HasUuids, SoftDeletes;

    protected $fillable = [
        'person_id',
        'party_id',
        'party_relationship_id',
        'role',
        'is_primary',
        'is_active',
        'job_title',
        'department',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    // Relations

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function partyRelationship(): BelongsTo
    {
        return $this->belongsTo(PartyRelationship::class);
    }
}
