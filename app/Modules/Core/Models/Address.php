<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Address extends Model
{
    use LogsActivity, HasUuids, SoftDeletes;

    protected $fillable = [
        'party_id',
        'type',
        'name',
        'line_1',
        'line_2',
        'city',
        'state_province',
        'postal_code',
        'country',
        'is_primary',
        'is_active',
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

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
