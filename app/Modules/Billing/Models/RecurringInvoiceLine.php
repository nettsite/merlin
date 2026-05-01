<?php

namespace App\Modules\Billing\Models;

use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class RecurringInvoiceLine extends Model
{
    use HasUuids, LogsActivity;

    protected $fillable = [
        'recurring_invoice_id',
        'account_id',
        'line_number',
        'description',
        'quantity',
        'unit_price',
        'discount_percent',
        'tax_rate',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'line_number' => 'integer',
        ];
    }

    // Relations

    /** @return BelongsTo<RecurringInvoice, $this> */
    public function recurringInvoice(): BelongsTo
    {
        return $this->belongsTo(RecurringInvoice::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
