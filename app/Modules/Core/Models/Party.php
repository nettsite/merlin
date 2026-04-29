<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Party extends Model
{
    use LogsActivity, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'party_type',
        'status',
        'primary_email',
        'primary_phone',
        'notes',
    ];

    // Relations

    /** @return HasOne<Person, $this> */
    public function person(): HasOne
    {
        // CTI: persons.id IS parties.id (shared primary key)
        return $this->hasOne(Person::class, 'id', 'id');
    }

    /** @return HasOne<Business, $this> */
    public function business(): HasOne
    {
        // CTI: businesses.id IS parties.id (shared primary key)
        return $this->hasOne(Business::class, 'id', 'id');
    }

    /** @return HasMany<PartyRelationship, $this> */
    public function relationships(): HasMany
    {
        return $this->hasMany(PartyRelationship::class);
    }

    /** @return HasMany<ContactAssignment, $this> */
    public function contactAssignments(): HasMany
    {
        return $this->hasMany(ContactAssignment::class);
    }

    /** @return HasMany<Address, $this> */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    // Helpers

    public function addRelationship(string $type, array $metadata = []): PartyRelationship
    {
        return $this->relationships()->create([
            'relationship_type' => $type,
            'metadata' => $metadata ?: null,
        ]);
    }

    public function assignContact(Person $person, array $options = []): ContactAssignment
    {
        return $this->contactAssignments()->create(array_merge(
            ['person_id' => $person->id],
            $options,
        ));
    }

    // Scopes

    public function scopeSuppliers(Builder $query): Builder
    {
        return $query->whereHas(
            'relationships',
            fn (Builder $q) => $q->where('relationship_type', 'supplier')->where('is_active', true),
        );
    }

    public function scopeClients(Builder $query): Builder
    {
        return $query->whereHas(
            'relationships',
            fn (Builder $q) => $q->where('relationship_type', 'client')->where('is_active', true),
        );
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    // Accessors

    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                if ($this->party_type === 'business') {
                    return $this->business !== null ? $this->business->display_name : '';
                }

                return $this->person !== null ? $this->person->full_name : '';
            },
        );
    }
}
