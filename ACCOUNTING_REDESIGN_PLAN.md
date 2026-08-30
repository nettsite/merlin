# Accounting Model Redesign — Implementation Plan

Written 2026-08-30. Companion to `REVIEW.md` / `REVIEW_FIXES_PLAN.md`; supersedes the
journal-table approach taken on branch `fix/journal-and-money-bugs` (PR #2).

Arises from a design discussion that rejected three assumptions in the current model:
documents should be immutable, invoices should never be voided, and bank statements
should not post to the ledger.

---

## The design, in one page

**One rule governs everything below: evidence never posts; only documents post.**

| Kind | Examples | Posts? |
|---|---|---|
| **Document** | purchase invoice, sales invoice, credit note, payment, journal | ✅ |
| **Evidence** | bank/CC statement, payment notification, source PDF | ❌ |

Five principles:

1. **Documents are immutable once issued.** The commercial facts — amounts, lines, dates,
   parties — are written once. Balances and status are a derived projection, not part of
   the document.
2. **Invoices are never voided or cancelled.** An issued invoice is reversed only by a
   credit note, which is itself a dated document. A March invoice credited in July leaves
   March's figures intact and puts the reversal in July. Drafts, never issued, may still be
   discarded.
3. **Posting direction derives from `document_type` + `direction`.** A line follows its
   document unless its amount is signed against it (needed only for journal documents).
   No `debit`/`credit` columns on `document_lines`.
4. **Bank statements are for reconciliation only.** Payments are posted manually or by
   batch-confirmation. Unmatched statement lines (bank charges, interest) become journal
   documents. Nothing is allocated unattended.
5. **One derivation site.** A `postings` SQL view projects every posting document into a
   uniform `(entry_date, account_id, party_id, debit, credit, document_id, source)` shape.
   Every report is `SUM` over it. The `journal_entries` / `journal_lines` tables are deleted.

### Why this replaces the journal tables

The journal was built to stop six report files each re-deriving debit/credit (which caused
F2: voided invoices counted as revenue). A view fixes that identically, with no duplicated
amounts. The one property a physical journal had that a view lacks — a reversal dated in
the period it was made — is supplied instead by principle 2. Removing void removes the need
for the table.

---

## Ground rules

- One commit per numbered step, on a branch off `main`.
- Test first or alongside every behavioural change; `php artisan test --compact --filter=…`.
- `vendor/bin/pint --dirty --format agent` before each commit.
- `npm run build` before any test that renders a view.
- No step ships while the suite is red.
- **The database is empty** (refreshed 2026-08-29). No step in this plan carries a data
  migration; destructive migrations are free — drop and recreate rather than alter in place.

**Land PR #2 first.** Its F1 (credit-note reversal), F2 (voided revenue) and F4 (credit
exhaustion) fixes are independent of the journal-vs-view choice and are live wrong numbers
today. Its report rewrites already query the exact `SUM(debit)/SUM(credit) GROUP BY
account_id` shape the view will expose, so Phase 4 becomes a swap rather than a rewrite.

---

## Phase 0 — Decide before building (0.25 day)

The database was refreshed on 2026-08-29 and holds no documents. The InvoiceNinja import is
deferred until this redesign lands. Consequences: **no data migration anywhere in this plan**
— no voided invoices to convert, no balances to backfill — and schema changes can drop and
recreate freely.

### 0.1 Benchmark the view before committing to it
Build the `postings` view as a throwaway and time the trial balance against the current
`journal_lines` query. The database is empty, so seed synthetic volume first — roughly 2 000
invoices, 8 000 lines, 3 000 payments via factories.
- **Within ~2x on that volume** -> proceed with the view.
- **Worse** -> keep a physical postings table written by one service, still deleting the
  per-report derivations. The rest of this plan is unaffected either way.
- Views cannot be indexed and neither MariaDB nor SQLite has materialised views. This is the
  single decision that could invalidate Phase 4.

### 0.2 Confirm scope on payment notifications
Phase 3 applies principle 1 to `PaymentEvidenceRecorder`, which currently creates GL payments
unattended from fuzzy-matched receipts. Confirm this is wanted — it is the same pattern being
removed from bank statements, but it is extra work and removes a labour saving.

## Phase 1 — Remove void; credit notes as the sole reversal (1.5 days)

### 1.1 Close the transition maps
`app/Modules/Core/Services/DocumentService.php:822–840`:
```php
'sales_invoice' => [
    'draft'          => ['sent'],
    'sent'           => ['partially_paid', 'paid'],
    'partially_paid' => ['paid'],
],
'credit_note' => [
    'draft'  => ['issued'],
    'issued' => ['applied'],
],
```
Delete `voidDocument()` (`:95`). Drafts remain discardable via delete, which has no
accounting effect.
- Verify: transition to `voided` throws `InvalidDocumentStateException`.

### 1.2 Replace the UI actions
`sales-invoices/index.blade.php:423` and `credit-notes/index.blade.php:338` call
`voidDocument()`. Replace the Void action with **Issue credit note**, pre-filled from the
invoice, supporting full or partial value. Remove `voided` from the status filter tabs
(`:586`) and the badge colour maps (`:664`, `:765`), plus
`recurring-invoices/index.blade.php:626`.

### 1.3 Shrink the status vocabulary
- `Document::UNRECOGNISED_SALES_STATUSES` → `['draft']`.
- Drop `'voided'` from the three guards at `Document.php:259`, `:281`, `:300`.
- Delete `'cancelled'` everywhere — this app has never written it, and it caused F2.
- Verify: `grep -rn "voided\|cancelled" app/ resources/` returns nothing outside migrations.

### 1.4 Guard against backdated credit notes
Reject a credit note whose `issue_date` precedes the invoice it credits — dated reversal is
the entire point of this phase.
- Verify: new test asserts the rejection.

### 1.5 Derive a "Credited" label
`applyCreditNote()` already zeroes `balance_due` and flips status to `paid` for a full
credit. Show "Credited" in the UI when `credits_applied >= total`. Presentation only.

### 1.6 Tests
New `tests/Feature/Documents/CreditNoteReversalTest.php`: March invoice + July credit note →
March income statement unchanged, July shows the reversal, invoice balance zero, status label
"Credited". Update `ReportStatusFilteringTest` for the shrunk vocabulary.

---

## Phase 2 — Bank statements become evidence (3 days)

### 2.1 Delete the auto-allocation
Remove `postBankStatement()` (`DocumentService.php:297`) and the bank-statement branch of
`createPaymentDocument()` (`:419`). This deletes review finding **F5** outright — the
newest-first date-heuristic allocation, the missing amount check, and the hard-coded `'2400'`
advance account all go with it.
- Verify: no path creates a payment document from a statement line.

### 2.2 Add statement period and balances
New migration on `documents`: `statement_from`, `statement_to`, `opening_balance`,
`closing_balance` (all nullable, statements only). Without these, reconciliation can tick
lines off but never prove the account balances.

### 2.3 Reconciliation match table
```
bank_reconciliation_matches
  id, statement_line_id → document_lines
  document_id → documents        (the payment/journal it reconciles to)
  matched_at, matched_by, note
```
Kept separate so the statement document itself stays immutable — do **not** reuse
`document_lines.linked_document_id`, which would mutate a frozen line.

### 2.4 Reconciliation screen
Rework `bank-statements/index.blade.php` (759 lines) from a posting page into a
reconciliation page:
- Per line: suggested match (party + amount + date proximity), Confirm / Skip / Create.
- **Batch confirm** — "11 lines match 11 invoices, confirm all" — one click, human in the loop.
- Create → new payment or journal document dated from the statement line, then auto-matched.
- Header: book balance vs closing balance vs unreconciled total, which must itemise to the
  difference.

### 2.5 Keep the extraction
`BankStatementExtractionAgent`, `ProcessBankStatementDocument` and
`BankStatementProcessingService` are unchanged — they now feed reconciliation rather than
posting. Check whether the existing unused-ish `requires_review` flag can carry reconciliation
state before adding another column.

### 2.6 Tests
`BankStatementProcessingService` currently has **zero** test references (review finding F19).
Cover: statement import creates no postings; confirmed match links without posting; create-from-line
produces a dated document; reconciliation arithmetic balances.

---

## Phase 3 — Payment notifications become evidence (1 day)

Conditional on decision 0.3.

`PaymentEvidenceRecorder:75` calls `recordPurchasePayment()` unattended, fed by
`PaymentNotificationMatcher`'s 50%-name-similarity match (review finding **F7**). Same pattern
as F5, one layer over. Change it to raise a suggestion into the Phase 2 confirm queue instead
of posting. F7 dies with it; the `applyCorrectedAmount()` rescaling risk it fed goes behind the
same human confirmation.

---

## Phase 4 — The `postings` view; delete the journal tables (2.5 days)

Gated on benchmark 0.2.

### 4.1 Build the view
`CREATE VIEW postings AS` — one `UNION ALL` branch per posting source: sales invoice AR leg,
sales invoice income legs (per line), VAT leg where `tax_amount > 0`, purchase invoice expense
legs + AP leg, credit note legs, payment legs, journal legs (signed amount carries direction).
Columns named `entry_date, account_id, party_id, debit, credit, document_id, source` to match
what the reports already select.
- SQL must run on both MariaDB (prod) and SQLite (tests). Portable `UNION ALL` only.

### 4.2 Point the reports at it
`trial-balance`, `balance-sheet`, `income-statement`, `accounts/show` already query
`SUM(journal_lines.debit)` / `SUM(journal_lines.credit)` grouped by `account_id`. Swap the table
name; the queries are otherwise unchanged.
- The four analytical reports (`income-by-*`, `expenses-by-*`) stay on `documents` — they need
  invoice counts, `balance_due` and per-line VAT the projection doesn't preserve.

### 4.3 Delete the journal layer
Remove `JournalService`, `JournalEntry`, `JournalLine`, `UnbalancedJournalEntryException`, the
`create_journal_tables` migration, the morph-map entries, and every `post*Journal()` call in
`Core\DocumentService`, `Purchasing\DocumentService` and `BillingService`.
- Repoint `DocumentLine`'s immutability guard from "has a non-reversed journal entry" to
  "document status is issued/posted".
- Rewrite `JournalServiceTest` and `LedgerIntegrityTest` against the view. **Keep**
  `LedgerIntegrityTest`'s assertions — debits equal credits, the accounting equation holds —
  they are the regression net for the whole redesign.

---

## Phase 5 — Split the mutable columns out of `documents` (2 days)

While `balance_due`, `amount_paid`, `credits_applied`, `status` and `foreign_balance_due` live
on `documents`, immutability can only ever be a convention — every payment UPDATEs the row.

- Move them to `document_balances` (one row per document), or derive from `postings`.
- `Document::recalculateBalance()` becomes the writer of that table, not of `documents`.
- Watch the read cost: aged debtors, overdue scopes and every invoice list read these columns.
  A table keeps them indexable; full derivation does not.
- Then `documents` and `document_lines` are physically append-only.

---

## Phase 6 — Immutability in the app; audit as the detection layer (1.5 days)

**Enforcement is app-level. No SQL triggers, no restricted grants.** Whoever administers this
database has full access to it, so a control implemented in the database is one its own operator
bypasses by default. Triggers also do not exist under SQLite, so no test could cover them.

### 6.1 Model-level immutability guards
`DocumentLine::saving()` / `deleting()` already throw `PostedDocumentImmutableException` (built
on PR #2, repointed in 4.3 to key off document status). Extend the same guard to `Document` for
the commercial columns — `total`, `subtotal`, `tax_total`, `issue_date`, `party_id`,
`document_number` — once issued. After Phase 5 the mutable columns live elsewhere, so this guard
has nothing legitimate left to block.
- Verify: a test asserting an issued invoice throws on every frozen column.

### 6.2 Audit the lines, not just the header
`Document` uses `LogsActivity`. **`DocumentLine` does not** — so the rows carrying the actual
amounts have no audit trail today. Add the trait with `logFillable()->logOnlyDirty()`. This is
the largest gap in current audit coverage.

### 6.3 Detect out-of-band changes by reconciliation
`spatie/laravel-activitylog` (installed, already wired on `Document`) records every legitimate
change. It cannot see a direct SQL edit — but it makes one *inferable*: a row whose `updated_at`
moved with no corresponding activity row was changed outside the app.

Add `php artisan documents:verify-audit-trail`:
- for each document and line changed since the last run, assert a matching activity row exists;
- report orphans with row id, column, and both timestamps;
- schedule daily alongside `models:health-check` (05:30).

Detection, not prevention — the correct ambition for this threat model. The realistic failure is
an 11pm hand-fix in a SQL client, and this surfaces it the next morning.

### 6.4 Close the bypasses
`saveQuietly()` skips model events, so it skips both the 6.1 guard and the 6.2 audit row. Audit
the three callers — `applyCreditNote()`, `recordPayment()`, `applyCorrectedAmount()` — so only
balance columns are reachable through them, and so 6.3 does not report them as false positives.

**Optional hardening**, only if a third party will ever rely on these books: chain each activity
row to its predecessor with a hash of (previous hash + current values), so editing or deleting a
log row breaks the chain detectably. App-level, works under SQLite, ~0.5 day. Not recommended
otherwise.

## Cost summary

| Phase | Days |
|---|---|
| 0 · Decide before building | 0.25 |
| 1 · Remove void | 1.5 |
| 2 · Bank statements → reconciliation | 3.0 |
| 3 · Payment notifications → evidence | 1.0 |
| 4 · `postings` view, delete journal | 2.5 |
| 5 · Split mutable columns | 2.0 |
| 6 · App-level immutability + audit | 1.5 |
| **Total** | **11.75** |

No data-migration contingency — the database is empty. Phases 1–3 are independently shippable
and deliver the correctness wins; 4–6 are the structural payoff.

---

## Effect on the outstanding review findings

**Eliminated by this plan**

| # | Finding | How |
|---|---|---|
| F5 | Bank settlement guesses which invoice got paid | feature deleted (2.1) |
| F7 | Payment notifications merge on 50% name match | becomes a suggestion (Phase 3) |
| F3 | No ledger / reports re-invent double entry | `postings` view (Phase 4) |
| F19 (half) | `BankStatementProcessingService` untested | covered in 2.6 |

**Already fixed on PR #2:** F1, F2, F4, F12, F17, F19 (`FinancialYearService`).

**Untouched — still open after this plan:** F6 (posting rules auto-post on `similar_text`),
F8 (numbering and payment races), F9 (unbounded prompt growth), F10 (FX failure discards paid
LLM work), F11 (missing composite index — and see the view's index caveat), F13 (per-line query
fan-out), F14 (unindexable sha256 dedupe), F15 (client-writable sort/perPage), F16 (279-line
`process()`), F18 (unbounded duplicate matching), F20 (stale settings in daemon).

F6 deserves a note: `PostingRuleService` auto-posts purchase invoices on a fuzzy match. It is not
evidence, so principle 1 doesn't strictly cover it — but it is the same unattended-money pattern,
and once the reconciliation confirm queue exists it has an obvious home.

---

## Risk register

| Risk | Mitigation |
|---|---|
| View too slow to be indexed | Benchmark gate at 0.2; fall back to a physical postings table written by one service |
| SQLite/MariaDB dialect drift in the view | Portable `UNION ALL` only; full suite runs on SQLite, smoke-test on MariaDB before deploy |
| Manual posting is more work than auto-allocation | Batch confirm in 2.4 keeps most of the saving, loses only the unattended part |
| Audit reconciliation reports false positives | 6.4 restricts `saveQuietly()` to balance columns before 6.3 is scheduled |
| Half-migrated state (journal tables and view coexisting) | Phase 4 is one commit: create view, swap reports, delete journal layer |
