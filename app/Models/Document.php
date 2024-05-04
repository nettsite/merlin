<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $guarded = [];

    /**
     * The database connection that should be used by the model.
     *
     * @var string
     */
    protected $connection = 'tenant';
    protected $table = 'documents';

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Get the total value of the document before discount.
     */
    public function getTotalAmountAttribute(): float
    {
        return $this->transactions->sum(function ($transaction) {
            return $transaction->unit_price * $transaction->quantity;
        });
    }

    /**
     * Get the total discount value of the document.
     */
    public function getTotalDiscountAttribute(): float
    {
        return $this->transactions->sum(function ($transaction) {
            return $transaction->discount_type === 0
                ? $transaction->unit_price * $transaction->quantity * $transaction->discount / 100
                : $transaction->discount;
        });
    } 
    
    /**
     * Get the total value of the document after discount.
     */
    public function getNetAmountAttribute(): float
    {
        return $this->total_amount - $this->total_discount;
    }
    
    


}
