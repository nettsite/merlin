<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $business_type
 * @property string $legal_name
 * @property string|null $trading_name
 * @property string|null $registration_number
 * @property string|null $tax_number
 * @property string|null $website
 * @property-read string $display_name
 */
class Business extends Model
{
    use LogsActivity, HasUuids;

    // Child CTI table — timestamps live on the parent parties row
    public $timestamps = false;

    protected $fillable = [
        'business_type',
        'legal_name',
        'trading_name',
        'registration_number',
        'tax_number',
        'website',
        'default_currency',
    ];

    // Relations

    public function party(): BelongsTo
    {
        // CTI: businesses.id IS parties.id (shared primary key)
        return $this->belongsTo(Party::class, 'id', 'id');
    }

    // Accessors

    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->trading_name ?? $this->legal_name,
        );
    }
}
