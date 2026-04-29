<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $display_name
 * @property string $full_path
 */
class Account extends Model
{
    use LogsActivity, HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'account_group_id',
        'parent_id',
        'code',
        'name',
        'description',
        'is_active',
        'allow_direct_posting',
        'is_system',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'allow_direct_posting' => 'boolean',
            'is_system' => 'boolean',
            'metadata' => 'array',
        ];
    }

    // Relations

    /** @return BelongsTo<AccountGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(AccountGroup::class, 'account_group_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    /** @return HasMany<Account, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    // Scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePostable(Builder $query): Builder
    {
        return $query->where('allow_direct_posting', true);
    }

    public function scopeExpenses(Builder $query): Builder
    {
        return $query->whereHas(
            'group.type',
            fn (Builder $q) => $q->where('code', '5'),
        );
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        return $query->where(function (Builder $q) use ($term) {
            $q->where('code', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%");
        });
    }

    // Accessors

    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: fn (): string => "{$this->code} — {$this->name}",
        );
    }

    protected function fullPath(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $groupName = $this->group !== null ? $this->group->name : '';

                return $groupName ? "{$groupName} > {$this->name}" : $this->name;
            },
        );
    }
}
