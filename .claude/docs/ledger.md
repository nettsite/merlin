### The Ledger: `postings`, a derived view — not a written table

There is no journal table. `postings` (`create_postings_view` migration) is a SQL view, `UNION ALL`
of one branch per posting source — sales invoice AR/income/VAT legs, purchase invoice
expense/AP legs, credit note reversal legs, and payment in/out legs — read live from
`documents`/`document_lines` with columns `document_id, entry_date, account_id, party_id, debit,
credit, description, source`. Nothing writes to it; there's nothing to keep in sync. A document
appears in it (or doesn't) purely by virtue of its own current status and account columns.

**A document is "in the ledger" once its status is issued and its accounts are configured** — no
write-time posting step exists. Each branch requires: the relevant account column set
(`receivable_account_id`/`payable_account_id`/`contra_account_id`/`tax_account_id`, stamped onto
the document at the moment it's decided — see below) and, for invoices/credit notes, **every
line coded** (a `NOT EXISTS` guard in the view — one uncoded line hides the whole document, not
just that line, reproducing the old all-or-nothing write-time skip). `Document::isIssued()`
(`app/Modules/Core/Models/Document.php`) says which statuses count, per document type: a sales
invoice from `sent` onward, a purchase invoice from `posted` onward, a credit note from `issued`
onward (not only once applied — "immutable once issued" holds even before a credit note is ever
used).

**Balance by construction, not by assertion.** AR debit = `total`; income credit =
`SUM(line_total)`; VAT credit = `tax_total`; and `total = subtotal + tax_total`,
`subtotal = SUM(line_total)` are already enforced by `Document::recalculateTotals()`. There is no
`UnbalancedJournalEntryException` equivalent because there is nothing left that could produce an
imbalance — the arithmetic is tautological, not checked at write time.

**Reversal is a credit note, not a swap.** A credit note posts its own legs (line debit reversing
the income, AR credit) — a second, independent posting, not a mutation of the original. **Any
report computing a signed total must sum `credit − debit` (or `debit − credit`) — never one side
alone** — or a credit note has no visible effect. Sales invoices can no longer be voided at all;
see the immutability paragraph below.

**Account columns are stamped once, at the moment they're decided, never read live from Settings
at query time** — a view has no portable way to join a settings-table JSON payload, and reading
live would let a later Settings change retroactively re-post a historical document anyway.
`DocumentService::stampTaxAccount()` copies `BillingSettings::tax_liability_account_id` onto a
sales invoice's `tax_account_id` in `markAsSent()` (throws if VAT applies and none is configured
— unless the invoice isn't otherwise postable yet, e.g. no `receivable_account_id`, in which case
that's still an in-progress document, not a Settings gap). `applyCreditNote()` copies the
invoice's `receivable_account_id` onto the credit note. `reconcileToGlAccount()` (bank
reconciliation) stamps a GL account straight onto a payment's `receivable_account_id` /
`payable_account_id` — the same slot an ordinary AR/AP payment uses, so it needs no branch of its
own in the view.

**Issued documents' lines are immutable, and so is the document header.** `DocumentLine::saving()`/
`deleting()` throw `PostedDocumentImmutableException` once `$this->document->is_issued` — always a
fresh query (`Document::find()`), never the cached `document()` relation, since a line saved once
while its document was still draft would otherwise cache that stale status past the point the
document gets issued. `Document::saving()` mirrors this for the header's commercial columns
(`total`, `subtotal`, `tax_total`, `issue_date`, `party_id`, `document_number`), checked against
the persisted pre-save status (`$this->balance?->status`, bypassing `pendingBalance`) so that
issuing a draft and confirming its `issue_date` in the same save is still allowed — only a save
touching them on a document already issued before that save started throws. Bypassed only by
`saveQuietly()`, used by the trusted system paths that legitimately rewrite an issued invoice's
amounts after the fact — FX-rate finalisation in `DocumentService::recordPayment()`, and every
`DocumentService::transition()`/`applyCreditNote()` status/balance write. (`applyCorrectedAmount()`
in `PaymentNotificationMatcher` needs no bypass — it only runs against an invoice confirmed not yet
posted, where the guard can't fire, so it uses a plain `save()`.) Because balance/status live on
`document_balances`, not on `documents` itself, they never show up in `Document`'s own dirty
attributes for `LogsActivity` to catch regardless of `save()` vs `saveQuietly()` — so every one of
these bypasses is paired with a `DocumentActivity` row (`recordActivity()`) in the same
transaction instead, and `php artisan documents:verify-audit-trail` (daily at 05:35) treats either
a Spatie `Activity` row or a `DocumentActivity` row as evidence a row's change was legitimate,
flagging only a change with neither as a likely out-of-band database edit.

**Not every report reads `postings`.** `trial-balance`, `balance-sheet`, `income-statement`, and
`accounts/{id}` are pure ledger views and query the view directly (one grouped query each).
`income-by-account`, `income-by-client`, `expenses-by-account`, `expenses-by-supplier`
deliberately still read `documents`/`document_lines` — each needs data the view doesn't preserve
at that granularity (invoice counts, `balance_due`, or per-line VAT split out from the invoice
total), and neither has the reversal-drift risk the view exists to fix (sales-side status
filtering already excludes unissued invoices directly; purchase invoices only ever move forward
through `posted`/`partially_paid`/`paid`).

**`credits_applied` on `Document`** stores a credit note's raw, uncapped amount; `Document::recalculateBalance()` is the single formula (`total − amount_paid − credits_applied`, floored at zero) every writer of `balance_due` must go through — `applyCreditNote()`, `recordPayment()`, and `Document::recalculateTotals()` all call it rather than assigning `balance_due` directly, which is what let a credit note get silently reversed by the invoice's next payment before this existed.

**Eloquent `'date'`-cast columns store a full `Y-m-d H:i:s` value**, not a clean date (`fromDateTime()` uses the connection's datetime format regardless of cast type) — every date-column query in this app, including `postings.entry_date`, must use `whereDate()`, never a raw `where()` comparison, or the stored value's trailing time component silently fails the match.

### Manual journals

`document_type = 'journal'` reuses `Document`/`DocumentLine` rather than a separate table. A
line's signed `line_total` (positive = debit, negative = credit) is produced for free by
`DocumentLine::calculateTotals()` as long as journal lines are always created with `quantity = 1`,
`discount = 0`, `tax_rate = null` — `line_total` then equals `unit_price` exactly, so `unit_price`
carries the signed amount. `direction` is `'internal'`, a value used by no other document type and
read by no posting/report logic — it exists only because the column is `NOT NULL`.

**Draft → posted is the entire state machine** (`DocumentService::getAllowedTransitions()['journal']`)
— no review step, and posted is terminal. `DocumentService::postJournal()` validates before
transitioning: the lines must sum to zero (`abs(difference) <= 0.01`, the same rounding tolerance
used elsewhere), and no line may target an AR/AP control account — journals adjust the general
ledger directly, they never touch a customer/supplier balance, which stays the job of
invoices/credit notes/payments. `DocumentService::controlAccountIds()` builds that exclusion list
from `BillingSettings::default_receivable_account_id` and `PurchasingSettings::default_payable_account`
plus each one's immediate sub-accounts — `Account` has no "is this AR/AP" flag of its own, so this
is necessarily built from the same two Settings pointers every other control-account lookup in the
app already relies on, not a database-enforced classification.

**A posted journal is immutable exactly like a posted invoice** — `Document::statusCountsAsIssued()`
treats `journal`/`posted` as issued, so the existing header and line guards (see "Issued documents'
lines are immutable" above) apply with no journal-specific code. **The only correction is
`DocumentService::createReversingJournal()`** — a new draft with every line's sign flipped, dated
today (or an explicit discovery date) rather than the original's date, same principle as credit
notes: a mistake found in July never rewrites March.

The `postings` view's journal branch is the one branch that maps a document's lines onto ledger
rows 1:1 rather than reproducing a fixed two-leg shape — each line's own sign already says debit or
credit, so there's nothing to derive. This is also why adding journals cost nothing on the
reporting side: trial balance, balance sheet, income statement, and account registers all read
`postings` directly and never filter by `source`, so a posted journal appears in every one of them
automatically.

### `document_balances` — the only mutable part of a document

`status`, `amount_paid`, `balance_due`, `foreign_amount_paid`, `foreign_balance_due`, and
`credits_applied` are **not columns on `documents`** — they live on `document_balances`
(`document_id` primary key, one row per document), so `documents`/`document_lines` are
physically append-only: every other column on a document is set once, at creation, and never
touched again.

**Model-level reads and writes are unaffected — `$doc->status = 'sent'; $doc->save();` and
`Document::create(['status' => 'draft', ...])` work exactly as before.** `Document` declares
these six as custom `Attribute::make()` accessors backed by a `balance()` `hasOne` relation, not
real columns; they stay in `$fillable` so mass assignment keeps working, and Document's own
`save()` is overridden (not hooked on the `saved` **event**, because `saveQuietly()` — used
throughout `DocumentService` for every status transition — suppresses events entirely but still
calls `save()`) to flush any pending values into `document_balances` after every save, quiet or
not.

**Query-builder-level filtering is what actually changed.** `Document::where('status', ...)`,
`whereIn('balance_due', ...)`, `orderBy('status', ...)` etc. all break — those columns are gone
from `documents`. Every such query needs `document_balances` joined first and the column
qualified: `Document::scopeJoinBalance()` does this (idempotent — safe if already joined; skips
setting `select('documents.*')` if the caller already specified a custom select, so a report's
own `selectRaw()` isn't overridden). The four built-in scopes that used to filter on these
columns (`scopeWithStatus`, `scopePostedOnwards`, `scopeOverdue`, `scopeUnpaid`) call it
internally. **Any new query filtering or sorting by status/balance_due must call
`->joinBalance()` (or an explicit `->join('document_balances', ...)`) and qualify the column** —
grep for `document_balances.status` / `document_balances.balance_due` across the report and list
pages for the established pattern. Also qualify `created_at`/`updated_at` explicitly
(`documents.created_at`) once the join is present — both tables have their own, and an
unqualified `ORDER BY` after the join is ambiguous.

The `postings` view joins `document_balances` for its own status filters — see the Journal
section above.
