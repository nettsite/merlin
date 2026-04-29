<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $first_name
 * @property string $last_name
 * @property string|null $title
 * @property string|null $email
 * @property string|null $mobile
 * @property string|null $direct_line
 * @property-read string $full_name
 * @property-read string $display_name
 */
class Person extends Model
{
    use LogsActivity, HasUuids;

    protected $table = 'persons';

    // Child CTI table — timestamps live on the parent parties row
    public $timestamps = false;

    protected $fillable = [
        'first_name',
        'last_name',
        'title',
        'email',
        'mobile',
        'direct_line',
    ];

    // Relations

    public function party(): BelongsTo
    {
        // CTI: persons.id IS parties.id (shared primary key)
        return $this->belongsTo(Party::class, 'id', 'id');
    }

    /** @return HasMany<ContactAssignment, $this> */
    public function contactAssignments(): HasMany
    {
        return $this->hasMany(ContactAssignment::class);
    }

    // Accessors

    protected function fullName(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $parts = array_filter([$this->title, $this->first_name, $this->last_name]);

                return implode(' ', $parts);
            },
        );
    }

    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => trim("{$this->first_name} {$this->last_name}"),
        );
    }
}
