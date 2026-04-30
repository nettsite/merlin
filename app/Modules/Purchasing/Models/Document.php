<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Models\Party;
use App\Modules\Core\Models\Person;
use App\Modules\Core\Settings\CurrencySettings;
use App\Traits\HasDocumentNumber;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * @property Carbon|CarbonImmutable|null $due_date
 * @property Carbon|CarbonImmutable $issue_date
 * @property-read bool $is_foreign_currency
 * @property-read bool $is_overdue
 * @property-read bool $is_paid
 * @property-read int $days_overdue
 */
class Document extends Model implements HasMedia
{
    use HasDocumentNumber, HasFactory, HasUuids, InteractsWithMedia, LogsActivity, SoftDeletes;

    protected $fillable = [
        'document_type',
        'direction',
        'document_number',
        'reference',
        'party_id',
        'contact_id',
        'billing_address_id',
        'status',
        'issue_date',
        'due_date',
        'currency',
        'exchange_rate',
        'exchange_rate_date',
        'exchange_rate_provisional',
        'subtotal',
        'tax_total',
        'total',
        'amount_paid',
        'balance_due',
        'foreign_subtotal',
        'foreign_tax_total',
        'foreign_total',
        'foreign_amount_paid',
        'foreign_balance_due',
        'notes',
        'terms',
        'footer',
        'payable_account_id',
        'source',
        'llm_confidence',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'exchange_rate_date' => 'date',
            'exchange_rate_provisional' => 'boolean',
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'balance_due' => 'decimal:2',
            'foreign_subtotal' => 'decimal:2',
            'foreign_tax_total' => 'decimal:2',
            'foreign_total' => 'decimal:2',
            'foreign_amount_paid' => 'decimal:2',
            'foreign_balance_due' => 'decimal:2',
            'llm_confidence' => 'decimal:4',
            'metadata' => 'array',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('source_document')->singleFile();
        $this->addMediaCollection('attachments');
    }

    // Relations

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'contact_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payable_account_id');
    }

    /** @return HasMany<DocumentLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(DocumentLine::class)->orderBy('line_number');
    }

    /** @return HasMany<DocumentActivity, $this> */
    public function activities(): HasMany
    {
        return $this->hasMany(DocumentActivity::class)->latest();
    }

    /** @return BelongsToMany<Document, $this> */
    public function parentDocuments(): BelongsToMany
    {
        return $this->belongsToMany(
            Document::class,
            'document_relationships',
            'child_document_id',
            'parent_document_id',
        )->withPivot('relationship_type')->withTimestamps();
    }

    /** @return BelongsToMany<Document, $this> */
    public function childDocuments(): BelongsToMany
    {
        return $this->belongsToMany(
            Document::class,
            'document_relationships',
            'parent_document_id',
            'child_document_id',
        )->withPivot('relationship_type')->withTimestamps();
    }

    // Scopes

    public function scopePurchaseInvoices(Builder $query): Builder
    {
        return $query->where('document_type', 'purchase_invoice');
    }

    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direction', 'inbound');
    }

    /** @param string|array<int, string> $status */
    public function scopeWithStatus(Builder $query, string|array $status): Builder
    {
        return is_array($status)
            ? $query->whereIn('status', $status)
            : $query->where('status', $status);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->whereNotIn('status', ['posted', 'rejected']);
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->where('balance_due', '>', 0)
            ->whereNotIn('status', ['rejected']);
    }

    public function scopeForParty(Builder $query, Party $party): Builder
    {
        return $query->where('party_id', $party->id);
    }

    // Accessors

    protected function isOverdue(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->due_date !== null
                && $this->due_date->isPast()
                && ! in_array($this->status, ['posted', 'rejected']),
        );
    }

    protected function isPaid(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => (float) $this->balance_due <= 0,
        );
    }

    protected function daysOverdue(): Attribute
    {
        return Attribute::make(
            get: function (): int {
                if ($this->due_date === null || ! $this->due_date->isPast()) {
                    return 0;
                }

                if (in_array($this->status, ['posted', 'rejected'])) {
                    return 0;
                }

                return (int) $this->due_date->diffInDays(now());
            },
        );
    }

    protected function isForeignCurrency(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => strtoupper((string) $this->currency)
                !== strtoupper(app(CurrencySettings::class)->base_currency),
        );
    }

    // Methods

    public function recalculateTotals(): void
    {
        $subtotal = (float) $this->lines()->sum('line_total');
        $taxTotal = (float) $this->lines()->sum('tax_amount');
        $total = $subtotal + $taxTotal;

        $this->subtotal = $subtotal;
        $this->tax_total = $taxTotal;
        $this->total = $total;
        $this->balance_due = $total - (float) $this->amount_paid;

        if ($this->is_foreign_currency) {
            $this->foreign_subtotal = (float) $this->lines()->sum('foreign_line_total');
            $this->foreign_tax_total = (float) $this->lines()->sum('foreign_tax_amount');
            $this->foreign_total = (float) $this->foreign_subtotal + (float) $this->foreign_tax_total;
            $this->foreign_balance_due = (float) $this->foreign_total - (float) $this->foreign_amount_paid;
        } else {
            $this->foreign_subtotal = null;
            $this->foreign_tax_total = null;
            $this->foreign_total = null;
            $this->foreign_balance_due = null;
        }

        $this->saveQuietly();
    }
}
