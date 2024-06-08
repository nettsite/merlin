<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $guarded = ['total_amount', 'total_discount', 'net_amount','transaction_id'];

     /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'total_amount',
    ];

    /**
     * The database connection that should be used by the model.
     *
     * @var string
     */
    protected $connection = 'tenant';

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function getTotalAmountAttribute(): string
    {
        $unitPrice = $this->unit_price;
        $quantity = $this->quantity;
        $discount = $this->discount;
        $discountType = $this->discount_type;

        if ($discountType === 1) {
            $totalDiscount = $unitPrice * $quantity * ($discount / 100);
        } else {
            $totalDiscount = $discount;
        }

        $totalAmount = ($unitPrice * $quantity) - $totalDiscount;

        return number_format($totalAmount,2,'.',' ');
    }
}
