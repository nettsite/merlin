# Merlin — Full Project Review

**Date:** 2026-06-11
**Scope:** Suitability & completeness of functionality, efficiency & performance, test coverage.
**Method:** Full read of domain services, models, jobs, commands, Volt pages, test suite run (348 tests).

---

## Executive Summary

The codebase is well-structured and substantially complete for its core mission (LLM-assisted supplier invoice processing plus sales/recurring billing). Architecture is consistent: module layout, service layer owning state transitions, policies on every page, a clean CRUD framework, and a genuinely good audit trail via `DocumentActivity`.

However, the review found **2 failing tests**, **3 correctness bugs** (one of which silently hardcodes currency on recurring invoices, one that can attach the wrong supplier to an invoice, one that can duplicate invoice lines on queue retry), several reliability gaps around the recurring-invoice scheduler, and a database-bloat issue where full base64 PDFs are stored in `llm_logs` on every vision call.

| Area | Rating | Notes |
|---|---|---|
| Functionality / suitability | **B+** | Core pipeline solid; gaps: purchase-invoice payments, foreign-currency sales, credit notes |
| Correctness | **B−** | 3 real bugs found (C1–C3), several edge cases |
| Efficiency / performance | **B** | A few heavy per-render queries, llm_logs bloat, O(N) total recalcs |
| Test coverage | **B+** | 348 tests, 914 assertions, good service coverage; 2 failing, time-bomb dates, a few untested services |

---

## 1. Test Suite Status

`composer run test`: **348 tests, 346 passed, 2 failed** (44s).

### F1 — `RecurringInvoiceTest::it_command_skips_non_due_templates` (time-bomb)
`tests/Feature/Billing/RecurringInvoiceTest.php:306` creates a template with hardcoded `next_invoice_date => '2026-06-01'` and asserts it is *not* due. Today is 2026-06-11, so the date is now in the past and the template **is** due. The test was correct when written and rotted with the calendar. Several other tests in this file use hardcoded 2026 dates and will rot the same way (the `'2026-05-01'` assertion at line 303 only passes because that test freezes or generates relative to the template).

**Fix:** freeze time with `Carbon::setTestNow()` (or Pest `travelTo()`) at the top of every date-sensitive test in this file, or derive dates relative to `now()`.

### F2 — `CurrencySettingsTest::test_currency_settings_can_be_saved`
Fails with `"companyName" => ["The company name field is required."]`. The general settings page gained company settings (commit `20bd209`) and now validates `companyName` as required, but the test only sets `baseCurrency`/`locale`. The test was never updated for the feature.

**Fix:** set `companyName` in the test (and assert company fields persist), or split currency and company saves into separate component actions.

---

## 2. Correctness Bugs

### C1 — Recurring invoice currency always hardcoded to ZAR (HIGH)
`app/Modules/Billing/Services/RecurringInvoiceService.php:50`:

```php
'currency' => $data['currency'] ?? $this->billingSettings->default_receivable_account_id ? 'ZAR' : 'ZAR',
```

`??` binds tighter than the ternary, so this evaluates as `(currency ?? account_id) ? 'ZAR' : 'ZAR'` — **always `'ZAR'`**, regardless of input, and it hardcodes the literal instead of using `CurrencySettings::base_currency`. Any caller passing a currency is silently ignored. Both ternary branches being identical confirms this is a leftover editing error.

**Fix:** `'currency' => strtoupper($data['currency'] ?? $this->currencySettings->base_currency)` (inject `CurrencySettings`).

### C2 — Ungrouped `orWhere` inside `whereHas` can match the wrong supplier (HIGH)
`app/Modules/Purchasing/Services/SupplierResolver.php:54-60`:

```php
Party::whereIn('status', ['active', 'pending'])
    ->whereHas('business', fn ($q) => $q->where('trading_name', $name)->orWhere('legal_name', $name))
    ->first();
```

Laravel does **not** auto-group constraints added inside a `whereHas` closure. The generated subquery is `WHERE businesses.id = parties.id AND trading_name = ? OR legal_name = ?` — the `OR` escapes the relation constraint. If *any* business in the table has `legal_name = $name`, the `EXISTS` is satisfied for **every** active/pending party, and `first()` attaches an arbitrary supplier to the invoice. This silently mis-attributes invoices to the wrong supplier.

The same ungrouped pattern exists in the search filter of `resources/views/livewire/pages/purchase-invoices/index.blade.php:450` (`orWhereHas('party.business', fn ($b) => $b->where(...)->orWhere(...))`) and should be checked in any other page that copied it (recurring-invoices search uses the same shape).

**Fix:** wrap in a nested group: `fn ($q) => $q->where(fn ($q) => $q->where('trading_name', $name)->orWhere('legal_name', $name))`.

### C3 — Queue retries duplicate invoice lines (HIGH)
`app/Modules/Purchasing/Jobs/ProcessInvoiceDocument.php` declares `public int $tries = 3;`, but `InvoiceProcessingService::process()` is append-only (per the project's own rule: "Reprocess = delete lines first"). If attempt 1 creates 4 lines and then fails (e.g. on activity creation, posting-rule evaluation, or a transient DB error), attempt 2 re-extracts and **appends 4 more lines**. The LLM call is also repeated, doubling token spend.

**Fix:** make the job idempotent — delete existing lines (and reset header fields the pipeline sets) at the start of `handle()`/`process()`, or set `$tries = 1` and rely on the `failed()` activity + manual reprocess. Wrapping `process()` in a DB transaction also prevents the partial-state problem (note: the LLM call inside a transaction lengthens it; acceptable at this volume, or extract-then-write in two phases).

### C4 — `resolveFirstFullPeriodDate` breaks for billing days that don't exist in the month (MEDIUM)
`RecurringInvoiceService.php:170`: `startOfMonth()->addDays($billingPeriodDay - 1)` overflows into the next month when `billing_period_day` exceeds the month's length (e.g. day 31 in February yields 2/3 March, day 30 in February yields 1/2 March). The resulting `next_invoice_date` is then off, and subsequent `addMonthNoOverflow()` calls anchor to the wrong day forever.

**Fix:** clamp: `$candidate = $startDate->copy()->startOfMonth()->day(min($billingPeriodDay, $startDate->daysInMonth));` and re-clamp when adding months (or store intended day separately, which `billing_period_day` already provides — derive each cycle from it instead of mutating `next_invoice_date`).

### C5 — Scheduler can double-generate recurring invoices (MEDIUM)
`GenerateRecurringInvoices` + `RecurringInvoiceService::generateFromTemplate()`:
- `generateFromTemplate()` is **not transactional**: draft is created, lines added, PDF generated, email sent, then the command separately calls `advanceNextDate()`. A crash between generation and advancing leaves `next_invoice_date` unchanged → next run generates a duplicate and emails the client again.
- The scheduled task (`routes/console.php:12`) has no `withoutOverlapping()`; a slow run overlapping the next would double-process.
- The catch in the command logs the failure but cannot tell whether the invoice was already sent.

**Fix:** wrap generate+advance in one transaction with the send *after* commit (or mark generation in metadata idempotently keyed on `template_id + period`), and add `->withoutOverlapping()` to the schedule.

### C6 — Payment recording allows overpayment and ignores purchase invoices (LOW)
`DocumentService::recordPayment()` never validates `$amount` against `balance_due` — a typo (extra zero) silently produces a negative balance and marks the invoice `paid`. Also, the status-transition block only handles `sales_invoice`; purchase invoices have no payment workflow at all (see G2). The `payment` Document created by `BillingService::recordPayment()` is left in status `draft` forever with no transitions defined for `document_type = 'payment'`.

---

## 3. Functionality — Suitability & Completeness

### What's solid
- **Purchase pipeline** is the standout: multi-format extraction (PDF/DOCX/XLSX/CSV), Magika type sniffing, vision fallback for scanned PDFs, supplier/account resolution with history context, VAT-inclusive detection with back-calculation, duplicate detection by SHA-256, posting rules + pattern-based autopost with sensible eligibility gates, full activity trail.
- **State machine** centralised in `DocumentService::transition()` with per-type maps — correct design.
- **Foreign currency on purchases** is thorough: provisional rates, finalisation from actual payment, foreign mirrors of every amount column.
- **Recurring billing**: pro-rata first invoice, frequency advancement with no-overflow, contact-targeted sending.

### Gaps (G-series)

**G1 — No general ledger.** "Posted" is a status, not a journal entry. Reports aggregate `document_lines` directly. Fine for current scope, but the moment bank accounts, VAT returns, or trial balance are needed, a `journal_entries` layer is required. Flagging as a deliberate scope boundary to document, not a defect.

**G2 — Purchase invoices cannot record payments.** `recordPayment()` exists and handles foreign-rate finalisation (clearly built with purchases in mind), but nothing in the purchase-invoices UI calls it, and the purchase status machine terminates at `posted` with no paid/partially-paid concept. Expense reports therefore cannot distinguish paid from unpaid supplier invoices.

**G3 — Sales invoices are base-currency only.** `BillingService::createDraft()` hardcodes `currency => base_currency, exchange_rate => 1.0`. Purchases support foreign currency; sales do not. If only local clients exist this is fine — but C1 shows callers *believe* they can pass a currency.

**G4 — No credit notes / invoice adjustments.** Once posted/sent, the only correction paths are `void` (sales) or `dispute` (purchase). No credit-note document type, no negative-invoice flow. For a billing system this is the most common missing real-world workflow.

**G5 — Watch folder only accepts PDFs.** `WatchInvoiceFolderCommand::scan()` globs `*.pdf`/`*.PDF` only, while the upload path accepts DOCX/XLSX/CSV. Either intentional (document it) or the glob should cover supported extensions and use `assertIsSupportedFormat()` (it currently calls the stricter `assertIsPdf()`).

**G6 — Recurring invoices send immediately with no review step.** `generateFromTemplate()` creates the draft and immediately emails it. No "generate as draft, review, then send" option. One bad template line goes straight to a client. A `auto_send` flag on the template (default on, to preserve behaviour) would cover this.

**G7 — Failed extractions are invisible in the UI.** A failed `ProcessInvoiceDocument` job records an activity, but the document stays in `received` looking identical to "still processing". No error state/badge, no retry button surfaced from the failure. (The reprocess action exists but the user must guess.)

**G9 — Competitive roadmap items (from `competitive-plan.html`, June 2026).** Verified against the codebase 2026-06-12; the plan file itself is now gitignored, so this section is the canonical record:

*Already shipped (plan marked these "Missing" — outdated):* email invoices to clients (`SalesInvoiceMail`, send modal, recipient resolution), payment recording, recurring invoices, custom branding/logo (company settings).

*Still outstanding (in plan priority order):*
- **Dashboard** — `dashboard.blade.php` is still the Breeze "You're logged in!" stub. Plan 1.1: 4 metric cards (outstanding AR, overdue, paid this month, AP to pay) + activity feed, read-only over existing models.
- **Invoice open/view tracking** — no `sent_at`/`viewed_at`/`email_token` columns exist; needs public token route `/invoices/{token}`.
- **Automated payment reminders** — no `invoices:send-reminders` command; triggers at −3d/due/+7d/+14d, per-invoice `reminder_paused`, settings in `/settings/billing`.
- **Quotes/estimates** — reuse `Document` with type `quote` (draft → sent → accepted/declined/expired), convert-to-invoice action.
- **Product/service library** — new `Billing` model + CRUD page, type-ahead into invoice lines.
- **AR aging report** — buckets current/30/60/90/90+, group by client, CSV export.
- **VAT report (SARS)** — output vs input VAT per period; note dependency on G1 (no GL layer) being acceptable: derivable from `document_lines.tax_amount` both directions.
- **Phase 3 moat items** — client portal (separate guard scoped by `party_id`), PayFast gateway, AI-drafted sales invoices (reuse `LlmService` with new prompt), AP/AR reconciliation per party.

*Plan corrections:* it claims "full double-entry GL posting" and two-sided multi-currency — both overstated; see G1 (posted is a status, not a journal) and G3 (sales are base-currency only).

**G8 — Uncommitted work in tree.** `recurring-invoices/index.blade.php` has uncommitted pause/activate actions + template-value column (functional, reviewed: fine, eager-loads `lines` correctly), and an untracked `competitive-plan.html`. The new `activate()`/`pause()` actions have **no tests**.

---

## 4. Efficiency & Performance

**P1 — Base64 PDFs persisted into `llm_logs` (HIGH).** `LlmService::log()` stores the full request body — for `extractRawTextFromPdf()` that includes the entire base64-encoded PDF (`LlmService.php:54,189`). Every scanned invoice writes a multi-MB row. Table will balloon; `llm-logs` index page and `report/llm-performance` queries slow with it.
**Fix:** strip/replace document `source.data` with a placeholder (`['type' => 'document', 'bytes' => N]`) before logging.

**P2 — No HTTP timeouts/retries on external calls (MEDIUM).** `LlmService::callApi()` and `ExchangeRateService::fetchRates()` use bare `Http::post/get()` — Laravel's default 30s timeout, no `retry()`, no `connectTimeout`. Vision extraction of a large PDF can exceed 30s and dies as a generic `ConnectionException` (then retries — see C3). The job allows 120s; the HTTP client should too.

**P3 — Purchase-invoices page re-queries heavy data every Livewire request (MEDIUM).** `with()` runs on *every* interaction (every keystroke-debounce, every modal toggle): status counts aggregate, **all** suppliers with businesses (`Party::suppliers()->with('business')->get()`), and the 25-row page eager-loads `lines.account` even though the table only displays header data. Same pattern on sales-invoices (`clients` list). With hundreds of suppliers this is a visible per-request cost.
**Fix:** `#[Computed(persist: true)]`/cache the supplier dropdown, drop `lines.account` from the list query (use `withCount('lines')` if a count is shown), only fetch the detail payload when the flyout is open (already done) — and load `lines` only with the columns the table needs.

**P4 — O(N) document total recalculation during line creation (LOW).** `DocumentLine::saved` triggers `Document::recalculateTotals()` which runs 2–4 `SUM` queries + a save. Creating N lines in `process()` runs ~N×5 queries. Acceptable at invoice scale (≤50 lines), but trivially batchable: suspend the event during the pipeline loop and recalc once.

**P5 — `is_foreign_currency` accessor resolves settings from the container per call (LOW).** `Document.php:253` calls `app(CurrencySettings::class)` per access; Blade loops over rows call it repeatedly. Spatie settings are cached, so cost is the container resolve — minor, but memoizing the base currency statically per request is free.

**P6 — Report queries don't exclude soft-deleted documents (LOW).** `reports/expenses-by-account` (and by-supplier) join `documents` manually — the `Document` SoftDeletes scope does not apply to joins, so `documents.deleted_at` is unchecked. Currently mitigated because posted docs can't be deleted, but one DB-level change breaks it silently. Add `->whereNull('documents.deleted_at')`.

**P7 — LLM model pinned as class constant (LOW).** `LlmService::MODEL = 'claude-sonnet-4-20250514'` — a dated snapshot model, not configurable. Move to `config/services.php` (`services.anthropic.model`, env `ANTHROPIC_MODEL`) and default it to a current alias so model upgrades don't require a code change.

**Positives noted:** sort columns are whitelisted via `match` (no injection through `sortBy`); Magika/pdftotext use Symfony `Process` with array args (no shell interpolation); exchange rates cached 24h; pagination everywhere; duplicate-upload SHA check is indexed via JSON path on custom_properties (verify index exists — see plan).

---

## 5. Test Coverage

**Stats:** 348 tests / 914 assertions, in-memory SQLite, Pest. Feature coverage of services is genuinely good: processing pipeline, document service transitions, supplier/account resolvers, posting rules (incl. pattern post), exchange rates, recurring invoices, sends, payments, policies, page smoke tests, watch command, arch test.

**Gaps:**

| Gap | Detail |
|---|---|
| T1 — Failing tests | F1, F2 above — suite is red today |
| T2 — Time-bomb dates | `RecurringInvoiceTest` hardcodes 2026 calendar dates without freezing time; more will fail as dates pass |
| T3 — `FinancialYearService` | Zero tests (fiscal-year bounds and month labels are classic off-by-one territory) |
| T4 — Pause/activate actions | New uncommitted `activate()`/`pause()` on recurring-invoices page untested |
| T5 — Bug-shaped blind spots | No test would catch C1 (no assertion on stored template currency from input), C2 (no test for cross-supplier name collision), C3 (no retry-idempotency test), C4 (no day-31/February case in `RecurringInvoiceTest` — `DueDateCalculatorTest` covers the *due-date* path but not template scheduling) |
| T6 — Reports | Only smoke-tested (page renders); no assertion that aggregates are correct, no soft-delete/date-boundary cases |
| T7 — Overpayment | No test for payment > balance (currently passes silently into negative balance — C6) |
| T8 — MariaDB | Everything runs on SQLite; per your own guidelines, UUID/morph columns and the JSON-path query in the duplicate check (`custom_properties->sha256`) deserve one real MariaDB integration run |

---

## 6. Security Observations (brief)

- Authorization is consistently applied (`mount()` + per-action `authorize()`); permissions used, not roles — matches house rules.
- `editLine()` on purchase-invoices loads line data without an `authorize('view', …)` check (authorize happens only on save). Low risk (viewAny gated in mount), but inconsistent.
- Bulk actions swallow `\Throwable` silently — invalid transitions are intentionally skipped, but real errors (DB down) also vanish into a happy "N invoice(s) posted" flash. Catch `InvalidDocumentStateException` specifically.
- File uploads validated by mime + size, then Magika-verified server-side. Good.
- LLM output is parsed into a typed DTO and never rendered unescaped — trust boundary handled.

---

## 7. Priority Matrix

| # | Finding | Severity | Effort |
|---|---|---|---|
| C1 | Recurring currency hardcoded ZAR | High | XS |
| C2 | whereHas/orWhere wrong-supplier match | High | XS |
| C3 | Job retry duplicates lines | High | S |
| F1/F2 | 2 failing tests | High | S |
| P1 | base64 PDF in llm_logs | High | XS |
| C5 | Recurring double-generation risk | Medium | S |
| C4 | billing_period_day month overflow | Medium | S |
| P2 | HTTP timeouts/retries | Medium | XS |
| P3 | Heavy per-render queries | Medium | M |
| G7 | Failed extraction invisible in UI | Medium | M |
| G2 | Purchase payment recording | Medium | M–L |
| C6/T7 | Overpayment guard | Low | XS |
| G5 | Watch folder non-PDF formats | Low | S |
| G6 | Recurring auto-send flag | Low | S |
| T3/T4/T6 | Coverage gaps | Low | S–M |
| P4–P7, P6 | Minor perf/config | Low | XS each |
| G1/G3/G4 | GL, FX sales, credit notes | Scope decisions | L |

See `REVIEW_FIXES_PLAN.md` for the implementation plan.
