<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountType extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'code',
        'name',
        'normal_balance',
        'sort_order',
    ];

    /** @return HasMany<AccountGroup, $this> */
    public function groups(): HasMany
    {
        return $this->hasMany(AccountGroup::class);
    }
}
