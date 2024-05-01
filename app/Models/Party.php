<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Support\Address;

class Party extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $guarded = [];

    /**
     * The database connection that should be used by the model.
     *
     * @var string
     */
    protected $connection = 'tenant';

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class);
    }

    protected function full_address(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes) => new Address(
                $attributes['address'],
                $attributes['city'],
                $attributes['province'],
                $attributes['country_code'],
                $attributes['postal_code'],
            ),
        );
    }
}
