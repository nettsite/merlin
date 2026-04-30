<?php

namespace App\Modules\Core\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Billing\Models\PaymentTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class PartyRelationship extends Model
{
    use HasUuids, LogsActivity, SoftDeletes;

    protected $fillable = [
        'party_id',
        'relationship_type',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    // Metadata getters — expose relationship-specific fields as first-class
    // properties. Getters only: write by merging into $rel->metadata directly
    // (e.g. $rel->mergeMetadata([...])) to avoid Eloquent's Attribute setter
    // storing a raw array in $attributes that then fails the 'array' cast on
    // subsequent access or save.

    protected function defaultPayableAccountId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->metadata['default_payable_account_id'] ?? null,
        );
    }

    protected function defaultReceivableAccountId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->metadata['default_receivable_account_id'] ?? null,
        );
    }

    protected function paymentTermId(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->metadata['payment_term_id'] ?? null,
        );
    }

    /**
     * Merge the given key-value pairs into metadata and save.
     *
     * @param  array<string, mixed>  $data
     */
    public function mergeMetadata(array $data): void
    {
        $this->metadata = array_merge($this->metadata ?? [], $data);
        $this->save();
    }

    // Relations

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function defaultPayableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_payable_account_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function defaultReceivableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'default_receivable_account_id');
    }

    /** @return BelongsTo<PaymentTerm, $this> */
    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class, 'payment_term_id');
    }

    /** @return HasMany<ContactAssignment, $this> */
    public function contactAssignments(): HasMany
    {
        return $this->hasMany(ContactAssignment::class);
    }

    // Scopes

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('relationship_type', $type);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
