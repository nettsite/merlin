<?php

namespace App\Modules\Billing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class BillingEmailTemplate extends Model
{
    use HasUuids, LogsActivity;

    protected $fillable = [
        'type',
        'name',
        'subject',
        'body',
        'offset_days',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'offset_days' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    public function scopeReminder(Builder $query): Builder
    {
        return $query->where('type', 'reminder');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public static function forInvoice(): ?self
    {
        return static::where('type', 'invoice')->first();
    }
}
