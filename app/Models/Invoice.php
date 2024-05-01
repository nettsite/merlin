<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;


class Invoice extends Document
{
    /**
     * The model's default values for attributes.
     *
     * @var array
     */
    protected $attributes = [
        'role' => 'I',
    ];

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('invoice', function (Builder $builder) {
            $builder->where('role', 'I');
        });
    }

}

