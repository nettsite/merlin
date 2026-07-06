<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class NotificationIncident extends Model
{
    use HasUuids;

    protected $fillable = [
        'type',
        'title',
        'message',
        'metadata',
        'triggered_at',
        'seen_at',
        'cleared_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'triggered_at' => 'datetime',
            'seen_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }

    /** @param Builder<NotificationIncident> $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('cleared_at');
    }

    /** @param Builder<NotificationIncident> $query */
    public function scopeUnseen(Builder $query): Builder
    {
        return $query->whereNull('seen_at');
    }

    /** @param Builder<NotificationIncident> $query */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
