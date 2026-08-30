<?php

namespace App\Modules\Core\Models;

use App\Modules\Accounting\Models\Account;
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
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    /**
     * Statuses that mean "in the ledger" for purchase invoices: posted plus
     * the payment states reached after posting. Any query that means
     * "posted" in the accounting sense must use these, not just 'posted'.
     */
    public const POSTED_STATUSES = ['posted', 'partially_paid', 'paid'];

    /**
     * Statuses that mean "not recognised as revenue" for sales invoices.
     * Counterpart to POSTED_STATUSES. Any report filtering sales invoices
     * must use this rather than re-typing the list — a stale literal here is
     * what once let voided invoices count as revenue on the income statement
     * while the trial balance correctly excluded them. An issued sales
     * invoice can no longer be voided at all — see credit notes — so the
     * only unrecognised status left is one that was never sent.
     */
    public const UNRECOGNISED_SALES_STATUSES = ['draft'];

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
        'credits_applied',
        'foreign_subtotal',
        'foreign_tax_total',
        'foreign_total',
        'foreign_amount_paid',
        'foreign_balance_due',
        'notes',
        'terms',
        'footer',
        'payable_account_id',
        'receivable_account_id',
        'contra_account_id',
        'tax_account_id',
        'payment_term_id',
        'source',
        'llm_confidence',
        'metadata',
        'bank_template_id',
        'requires_review',
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
            'foreign_subtotal' => 'decimal:2',
            'foreign_tax_total' => 'decimal:2',
            'foreign_total' => 'decimal:2',
            'llm_confidence' => 'decimal:4',
            'metadata' => 'array',
            'requires_review' => 'boolean',
        ];
    }

    /**
     * status/amount_paid/balance_due/foreign_amount_paid/foreign_balance_due/
     * credits_applied aren't real columns on `documents` any more — see
     * DocumentBalance. A plain array here (not the `attributes` bag) so
     * they never reach the INSERT/UPDATE this model itself builds; save()
     * below flushes them into document_balances instead.
     *
     * @var array<string, mixed>
     */
    private array $pendingBalance = [];

    /**
     * Flushes pendingBalance into document_balances after every save —
     * overridden here rather than hooked on the `saved` event because
     * saveQuietly() (used throughout DocumentService for every status
     * transition) suppresses model events entirely but still calls this
     * method, so an event-based hook would silently never fire for the
     * single most common way this app changes a document's status.
     */
    public function save(array $options = [])
    {
        $result = parent::save($options);

        if ($this->pendingBalance !== []) {
            $balance = DocumentBalance::updateOrCreate(
                ['document_id' => $this->id],
                $this->pendingBalance,
            );
            $this->pendingBalance = [];
            $this->setRelation('balance', $balance);
        }

        return $result;
    }

    private function balanceValue(string $key): mixed
    {
        return array_key_exists($key, $this->pendingBalance)
            ? $this->pendingBalance[$key]
            : $this->balance?->{$key};
    }

    /** @return array<string, mixed> */
    private function setBalanceValue(string $key, mixed $value): array
    {
        $this->pendingBalance[$key] = $value;

        return [];
    }

    protected function status(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->balanceValue('status'),
            set: fn ($value) => $this->setBalanceValue('status', $value),
        );
    }

    protected function amountPaid(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->balanceValue('amount_paid'),
            set: fn ($value) => $this->setBalanceValue('amount_paid', $value),
        );
    }

    protected function balanceDue(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->balanceValue('balance_due'),
            set: fn ($value) => $this->setBalanceValue('balance_due', $value),
        );
    }

    protected function foreignAmountPaid(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->balanceValue('foreign_amount_paid'),
            set: fn ($value) => $this->setBalanceValue('foreign_amount_paid', $value),
        );
    }

    protected function foreignBalanceDue(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->balanceValue('foreign_balance_due'),
            set: fn ($value) => $this->setBalanceValue('foreign_balance_due', $value),
        );
    }

    protected function creditsApplied(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->balanceValue('credits_applied'),
            set: fn ($value) => $this->setBalanceValue('credits_applied', $value),
        );
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('source_document')->singleFile();
        $this->addMediaCollection('invoice_pdf')->singleFile();
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

    /** @return BelongsTo<BankTemplate, $this> */
    public function bankTemplate(): BelongsTo
    {
        return $this->belongsTo(BankTemplate::class);
    }

    /** @return BelongsTo<Account, $this> */
    public function contraAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'contra_account_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function payableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payable_account_id');
    }

    /** @return BelongsTo<Account, $this> */
    public function receivableAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'receivable_account_id');
    }

    /**
     * The VAT liability account in force when this document was issued —
     * stamped at that moment from BillingSettings::tax_liability_account_id
     * rather than read live, so changing the setting later can't
     * retroactively re-post a historical invoice's VAT leg.
     *
     * @return BelongsTo<Account, $this>
     */
    public function taxAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'tax_account_id');
    }

    /** @return BelongsTo<PaymentTerm, $this> */
    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    /** @return HasOne<DocumentBalance, $this> */
    public function balance(): HasOne
    {
        return $this->hasOne(DocumentBalance::class);
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

    public function scopeSalesInvoices(Builder $query): Builder
    {
        return $query->where('document_type', 'sales_invoice');
    }

    public function scopeQuotes(Builder $query): Builder
    {
        return $query->where('document_type', 'quote');
    }

    public function scopeCreditNotes(Builder $query): Builder
    {
        return $query->where('document_type', 'credit_note');
    }

    public function scopeBankStatements(Builder $query): Builder
    {
        return $query->where('document_type', 'bank_statement');
    }

    public function scopeCreditCardStatements(Builder $query): Builder
    {
        return $query->where('document_type', 'credit_card_statement');
    }

    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direction', 'inbound');
    }

    /**
     * status/balance_due live on document_balances now — every scope that
     * filters on either needs this joined first. Idempotent: safe to call
     * from scopes that might be chained together in the same query.
     */
    public function scopeJoinBalance(Builder $query): Builder
    {
        $alreadyJoined = collect($query->getQuery()->joins)
            ->contains(fn ($join) => $join->table === 'document_balances');

        if ($alreadyJoined) {
            return $query;
        }

        $query->join('document_balances', 'document_balances.document_id', '=', 'documents.id');

        // Both tables have created_at/updated_at — an unqualified select *
        // would let document_balances' columns silently clobber documents'
        // own timestamps when hydrating the model. Only when nothing more
        // specific has been selected yet, so a report building its own
        // selectRaw() isn't overridden.
        if ($query->getQuery()->columns === null) {
            $query->select('documents.*');
        }

        return $query;
    }

    /** @param string|array<int, string> $status */
    public function scopeWithStatus(Builder $query, string|array $status): Builder
    {
        $query->joinBalance();

        return is_array($status)
            ? $query->whereIn('document_balances.status', $status)
            : $query->where('document_balances.status', $status);
    }

    public function scopePostedOnwards(Builder $query): Builder
    {
        return $query->joinBalance()->whereIn('document_balances.status', self::POSTED_STATUSES);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        // Overdue = past due with money still owing. Posted purchase
        // invoices are awaiting payment, so they count; settled, rejected,
        // and unsent drafts do not.
        return $query->joinBalance()
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->where('document_balances.balance_due', '>', 0)
            ->whereNotIn('document_balances.status', ['draft', 'rejected']);
    }

    public function scopeUnpaid(Builder $query): Builder
    {
        return $query->joinBalance()
            ->where('document_balances.balance_due', '>', 0)
            ->whereNotIn('document_balances.status', ['rejected']);
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
                && (float) $this->balance_due > 0
                && ! in_array($this->status, ['draft', 'rejected']),
        );
    }

    protected function isPaid(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => (float) $this->balance_due <= 0,
        );
    }

    /**
     * True once a sales invoice's balance was zeroed entirely by credit
     * notes rather than cash — status alone reads "paid" either way, so the
     * UI needs this to show "Credited" instead of implying money changed
     * hands. Presentation only; the status column and every report keep
     * reading 'paid'.
     */
    protected function isFullyCredited(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => $this->status === 'paid'
                && (float) $this->credits_applied > 0
                && (float) $this->credits_applied >= (float) $this->total,
        );
    }

    /**
     * True once a document carries real accounting weight — its lines are
     * what the postings view reads, so they can no longer change without
     * silently rewriting a report that already showed them. Per document
     * type because "issued" means something different for each: a sales
     * invoice the moment it's sent, a purchase invoice once posted (draft
     * through disputed are all still pre-posting review states), a credit
     * note the moment it's issued — not only once applied, matching "a
     * document is immutable once issued" rather than waiting for its GL
     * effect. Quotes, POs, and statements never carry accounting weight, so
     * they're never locked here.
     */
    protected function isIssued(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => match ($this->document_type) {
                'sales_invoice' => ! in_array($this->status, self::UNRECOGNISED_SALES_STATUSES, true),
                'purchase_invoice' => in_array($this->status, self::POSTED_STATUSES, true),
                'credit_note' => in_array($this->status, ['issued', 'applied'], true),
                default => false,
            },
        );
    }

    protected function daysOverdue(): Attribute
    {
        return Attribute::make(
            get: function (): int {
                if ($this->due_date === null || ! $this->due_date->isPast()) {
                    return 0;
                }

                if ((float) $this->balance_due <= 0 || in_array($this->status, ['draft', 'rejected'])) {
                    return 0;
                }

                return (int) $this->due_date->diffInDays(now());
            },
        );
    }

    protected function isForeignCurrency(): Attribute
    {
        // shouldCache: computed once per model instance — Blade row loops
        // access this several times per row and each call resolves settings.
        return Attribute::make(
            get: fn (): bool => strtoupper((string) $this->currency)
                !== strtoupper(app(CurrencySettings::class)->base_currency),
        )->shouldCache();
    }

    // Methods

    /**
     * The single formula for what is still owed. Every writer of balance_due
     * must go through here — three independent writers computing it three
     * different ways is what let an applied credit be silently reversed by
     * the next payment or line edit.
     */
    public function recalculateBalance(): void
    {
        $this->balance_due = max(0, (float) $this->total
            - (float) $this->amount_paid
            - (float) $this->credits_applied);
    }

    public function recalculateTotals(): void
    {
        $subtotal = (float) $this->lines()->sum('line_total');
        $taxTotal = (float) $this->lines()->sum('tax_amount');
        $total = $subtotal + $taxTotal;

        $this->subtotal = $subtotal;
        $this->tax_total = $taxTotal;
        $this->total = $total;
        $this->recalculateBalance();

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
