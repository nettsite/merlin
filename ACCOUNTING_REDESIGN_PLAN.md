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

### 0.1 Benchmark the view — **DONE 2026-08-30: PASS, proceed with the view**

Method: scratch `merlin_bench` database, chart of accounts seeded, synthetic volume bulk-loaded
with matching `journal_*` rows so both sides read identical data. Twelve-branch `postings` view
(sales AR/income/VAT, purchase expense/AP, payment in/out, credit note). Median of 9–12 runs.
Scratch database dropped afterwards; the working `merlin` database was never written to.

| Volume | Trial-balance render — journal | via `postings` view | ratio |
|---|---|---|---|
| 6 000 docs / 17 800 journal lines | 40 ms | 100 ms | 2.50x |
| 24 000 docs / 71 200 journal lines | 200 ms | 381 ms | **1.91x** |

**The ratio does not degrade with volume — it improved.** Both scale roughly linearly; the
journal's cumulative-balance query degrades faster than the view's, closing the gap. At 1.91x on
four years of synthetic activity the gate is met.

Two side findings, both worth acting on:

- **Review finding F11 is wrong for these queries.** Adding the recommended composite index
  `documents (document_type, status, issue_date)` made the view *slower* (381 ms -> 417 ms), and
  `whereDate()` versus a raw date comparison made no measurable difference (53 ms vs 52 ms). A
  report that aggregates every row in a period is a scan by nature; no index helps it. F11 should
  be re-scoped to the *list* screens that filter to a handful of rows, or dropped.
- **The cumulative query is the expensive half** (145 ms of the journal's 200 ms). The trial
  balance runs two aggregates — movement window and cumulative — where one pass with conditional
  `SUM(CASE WHEN entry_date >= ? THEN debit END)` would do. Roughly halves the render either way.

Fallback trigger: revisit if document volume passes ~50 000, at which point either collapse the
two aggregates into one or materialise `postings` into a table written by one service.

### 0.2 Scope on payment notifications — **DECIDED 2026-08-30**

Existing matching behaviour is kept; Phase 3 reduces to flooring the settings threshold. See Phase 3.

## Phase 1 — Remove void; credit notes as the sole reversal (1.5 days) — **DONE 2026-08-30**

All six sub-steps landed on `main`: transition maps closed, `voidDocument()` deleted, the UI
replaced with **Issue Credit Note** (`createCreditNoteFromInvoice()` on `DocumentService`, a
draft pre-filled with the invoice's party/currency/lines that the operator can trim before
issuing), status vocabulary shrunk, the backdating guard added, the "Credited" label wired into
all three status-badge locations, and `confirmDelete()` on both sales invoices and credit notes
now refuses anything past `draft` — closing the hole where deleting an issued document would
have achieved the same period-erasure void existed to prevent. 664/664 tests green (was 661),
`vendor/bin/pint --dirty` clean, `npm run build` run for the new badge colour.

**Bug found and fixed while writing the period-integrity test (1.6):** `postCreditNoteJournal()`
posted every credit note's journal entry with `date: now()`, ignoring the credit note's own
`issue_date` entirely. A March invoice credited in July would have posted its reversal dated
*today* instead of July — silently defeating the one property this whole phase exists to
provide. Only surfaced because the new test asserted on a specific month's movement rather than
just a net-zero balance. Fixed to `$creditNote->issue_date ?? now()`, matching the three sibling
`journal->post()` calls elsewhere in the file, all of which already used the document's own date.

**Known gap surfaced, left out of scope on purpose:** `income-by-account` and `income-by-client`
read `documents` directly and have never netted credit notes against the invoices they reverse —
true before this phase and unchanged by it. Two new tests
(`ReportStatusFilteringTest`) assert this explicitly so it's a documented fact rather than a
silent surprise. Worth a line item whenever those two reports are next touched.

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

## Phase 3 — Floor the payment-match threshold (0.25 day)

**Decided 2026-08-30: the existing matching behaviour stays.** The dual-invoice pattern is
already handled — a supplier sends an unpaid invoice and a matching paid copy, often under a
different invoice number; `DuplicateInvoiceMerger` folds the paid copy into the original as
evidence and `PaymentEvidenceRecorder` raises the payment from it. A paid-only arrival is covered
by the extraction's `already_paid` signal. None of that changes.

This differs from the bank-statement case on purpose. `PaymentNotificationMatcher::score()`
already tiers its evidence, and the top two tiers are *definitive* — the payment's own reference
text contains the invoice's `document_number` (0.95) or the supplier's invoice number (0.90).
That is a real identifier appearing in the payment record, not a date-proximity guess.

The hole is the settings UI. `PurchasingSettings::payment_match_auto_confidence` defaults to
0.80, which correctly excludes the weak tiers — name resemblance at 0.60 (`similar_text` >= 50%)
and same-day-only at 0.40 — but the field accepts any value >= 0. Set it to 0.6 and a loose
string match starts auto-applying, including `applyCorrectedAmount()`'s rescaling of every line
on the invoice.

- Floor the settings input at **0.90**, the lowest confidence a definitive (reference-match) tier
  can produce, so the weak tiers can never be configured into auto-applying. Closes review
  finding **F7**.
- Gate `applyCorrectedAmount()` on the definitive threshold independently of the merge threshold
  — rescaling an invoice's line amounts deserves its own gate.
- Verify: a test asserting the settings page rejects a threshold below 0.90, and that a 0.60
  name-only match never posts.

## Phase 4 — The `postings` view; delete the journal tables (2.5 days)

Gated on benchmark 0.2.

### 4.1 Build the view
`CREATE VIEW postings AS` — one `UNION ALL` branch per posting source: sales invoice AR leg,
sales invoice income legs (per line), VAT leg where `tax_amount > 0`, purchase invoice expense
legs + AP leg, credit note legs, payment legs, journal legs (signed amount carries direction).
Columns named `entry_date, account_id, party_id, debit, credit, document_id, source` to match
what the reports already select.
- SQL must run on both MariaDB (prod) and SQLite (tests). Portable `UNION ALL` only.

**Prerequisite — the VAT account must move onto the document.** The VAT leg needs
`BillingSettings::tax_liability_account_id`, which lives in the `settings` table as a JSON
payload and cannot be read from a view portably. Add `tax_account_id` to `documents`, stamped at
issue time, alongside the `receivable_account_id` / `payable_account_id` / `contra_account_id`
columns that are already there. This is more correct than reading the setting anyway: it captures
which VAT account was in force when the invoice was issued, so changing the setting later cannot
retroactively re-post historical invoices. The benchmark view hard-coded the id to get a timing.

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
| 3 · Floor the payment-match threshold | 0.25 |
| 4 · `postings` view, delete journal | 2.5 |
| 5 · Split mutable columns | 2.0 |
| 6 · App-level immutability + audit | 1.5 |
| **Total** | **11.0** |

No data-migration contingency — the database is empty. Phases 1–3 are independently shippable
and deliver the correctness wins; 4–6 are the structural payoff.

---

## Effect on the outstanding review findings

**Eliminated by this plan**

| # | Finding | How |
|---|---|---|
| F5 | Bank settlement guesses which invoice got paid | feature deleted (2.1) |
| F7 | Payment notifications merge on 50% name match | settings floored at the definitive tier (Phase 3) |
| F3 | No ledger / reports re-invent double entry | `postings` view (Phase 4) |
| F19 (half) | `BankStatementProcessingService` untested | covered in 2.6 |

**Already fixed on PR #2:** F1, F2, F4, F12, F17, F19 (`FinancialYearService`).

**Untouched — still open after this plan:** F6 (posting rules auto-post on `similar_text`),
F8 (numbering and payment races), F9 (unbounded prompt growth), F10 (FX failure discards paid
LLM work), F11 (**measured in 0.1 and does not hold for the reports** — re-scope to list screens or drop), F13 (per-line query
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
