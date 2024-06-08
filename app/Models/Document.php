<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Spatie\Browsershot\Browsershot;

class Document extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $guarded = [
        'total_amount',
        'total_discount',
        'net_amount',
    ];

    protected $casts = [
        'due_date' => 'date',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array
     */
    protected $appends = [
        'total_amount',
        'total_discount',
        'net_amount'
    ];

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
        return $this->hasMany(Transaction::class, 'document_id', 'id');
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
            return $transaction->discount_type === 1
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

    public static function nextNumber(string $role): string
    {
        $last = static::where('role', $role)->latest('number')->first();
        if ($last) {
            return $last->number + 1;
        }
        return 1;
    }

    public function sendDocument(): void
    {
        $this->createPDF();

        $this->status = 'S';
        $this->save();
    }

    public function createPDF()
    {
        try {
            Browsershot::html(view('document', ['document' => $this])->render())
                ->noSandbox()
                ->waitUntilNetworkIdle()
                ->format('A4')
                ->showBackground()
                ->savePdf(storage_path('public/temp.pdf'));

            // $postRoute = URL::signedRoute('orderinvoices.store', ['order' => $this->order]);
            // Http::attach('invoice', file_get_contents('temp.pdf'), 'invoice.pdf')
            //     ->post($postRoute)
            //     ->throw();
        } catch (\Exception $exception) {
            Log::error($exception);
            dd($exception->getMessage());
        }
    }
}
