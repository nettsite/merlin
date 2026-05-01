<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class ContactAssignment extends Model
{
    use HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'person_id',
        'party_id',
        'party_relationship_id',
        'role',
        'receives_invoices',
        'is_primary',
        'is_active',
        'job_title',
        'department',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'receives_invoices' => 'boolean',
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
