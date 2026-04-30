<?php

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Enums\RecurringFrequency;
use App\Modules\Billing\Enums\RecurringInvoiceStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class RecurringInvoice extends Model
{
    use HasFactory, HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'client_id',
        'payment_term_id',
        'receivable_account_id',
        'contact_ids',
        'frequency',
        'billing_period_day',
        'start_date',
        'end_date',
        'next_invoice_date',
        'status',
        'currency',
        'notes',
        'terms',
        'footer',
    ];

    protected function casts(): array
    {
        return [
            'frequency' => RecurringFrequency::class,
            'status' => RecurringInvoiceStatus::class,
            'contact_ids' => 'array',
            'billing_period_day' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'next_invoice_date' => 'date',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
