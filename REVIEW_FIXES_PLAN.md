# Review Fixes — Implementation Plan

Companion to `REVIEW.md` (2026-06-11). Phases ordered by risk reduction per unit effort. Each step lists its verification. IDs (C1, P1, T3…) reference REVIEW.md findings.

**Ground rules for all phases**
- One commit per logical fix, pushed to `main` (house rule: no PRs unless asked).
- Every behavioural change gets a test first or alongside (`php artisan test --compact --filter=…`).
- `vendor/bin/pint --dirty --format agent` before each commit.
- The uncommitted recurring-invoices diff (G8) is committed first so the tree is clean.

---

## Phase 0 — Clean tree + make the suite green (do first)

### 0.1 Commit pending recurring-invoices work (G8)
- Commit existing pause/activate + value-column changes in `resources/views/livewire/pages/recurring-invoices/index.blade.php` **together with new tests** (see 0.2). Decide fate of untracked `competitive-plan.html` (ask Will / leave untracked).

### 0.2 Tests for pause/activate (T4)
- In `tests/Feature/Billing/RecurringInvoiceTest.php`: pause sets `Paused`, activate sets `Active`, both gated by `update` policy (assert 403 for unauthorised user).
- Verify: `php artisan test --compact --filter=RecurringInvoiceTest`.

### 0.3 Fix F1 + T2 — freeze time in RecurringInvoiceTest
- Add `Carbon::setTestNow('2026-05-15')` (or Pest `beforeEach` + `travelTo`) for all date-dependent tests; keep existing hardcoded dates, now stable forever.
- Audit the whole file (and `SalesInvoiceTest`, `SendInvoiceTest`) for other absolute dates compared against `now()`.
- Verify: filter run passes; mentally bump the frozen date ±1 year — assertions still hold.

### 0.4 Fix F2 — CurrencySettingsTest
- Update test to `->set('companyName', 'Test Co')` (match the form's required fields) and add an assertion that company name persists.
- Verify: `--filter=CurrencySettingsTest`; then **full suite green**: `composer run test`.

---

## Phase 1 — High-severity correctness (C1–C3, P1)

### 1.1 C1 — Recurring template currency
- `RecurringInvoiceService`: inject `CurrencySettings`; replace line 50 with
  `'currency' => strtoupper($data['currency'] ?? $this->currencySettings->base_currency),`
- Tests: template created with explicit `'currency' => 'USD'` stores USD; without input stores base currency (set base to non-ZAR in test to prove no hardcoding).
- Note: generated sales invoices still come out base-currency via `BillingService::createDraft` (G3) — template currency is stored but not yet propagated; document this in the service docblock until G3 is decided.

### 1.2 C2 — WITHDRAWN (done: coverage test only)
- Finding was wrong: `whereHas` applies callbacks via `callScope`, which auto-groups OR constraints. Verified in framework source + a decoy-supplier test that passes against unmodified code. Test kept in `SupplierResolverTest`; no production change.

### 1.3 C3 — Idempotent processing job
- Chosen approach: make `ProcessInvoiceDocument::handle()` defensive — before calling `process()`, delete existing lines (`$this->document->lines()->delete()`) iff status is `received` (re-entry implies a prior partial attempt). Keep `$tries = 3` for transient-failure resilience.
- Also reset pipeline-written header fields? Not needed — `process()` overwrites them.
- Tests (InvoiceProcessingServiceTest or new job test): dispatch handle() twice against same document with mocked LLM → line count equals extraction count, not double.

### 1.4 P1 — Stop logging base64 PDFs
- `LlmService::log()`: before persisting `request_payload`, walk messages and replace any `content[*].source.data` with `'[base64 omitted: N bytes]'`.
- Test (LlmServiceTest): after `extractRawTextFromPdf` with `Http::fake`, assert `LlmLog::first()->request_payload` contains the placeholder and not the base64 string.
- Optional one-off cleanup command/migration to strip historic rows (check prod table size first) — propose, don't auto-run.

---

## Phase 2 — Reliability (C4, C5, C6, P2)

### 2.1 C5 — Recurring generation atomicity
- `GenerateRecurringInvoices`: wrap per-template work so duplicates are impossible:
  1. In `generateFromTemplate()`, create draft + lines + metadata link inside `DB::transaction`.
  2. Move `advanceNextDate()` *into* the same transaction boundary in the command (generate → advance → commit), and send the email **after** commit (send failure already only logs a warning — invoice stays `draft`+unsent; surface in UI per G7 pattern).
  3. Add idempotency guard: before generating, skip if a sales invoice already exists with `metadata->recurring_invoice_id = template->id` and `issue_date = next_invoice_date`.
- `routes/console.php`: `Schedule::command(...)->dailyAt('06:00')->withoutOverlapping();`
- Tests: simulate crash (mock BillingService::sendInvoice to throw RuntimeException → invoice exists once, next_invoice_date advanced); run command twice same day → exactly one invoice per template.

### 2.2 C4 — billing_period_day month-length clamp
- `resolveFirstFullPeriodDate()`: clamp with `->day(min($billingPeriodDay, $candidate->daysInMonth))`; recompute candidate per month rather than `addDays`.
- Consider deriving each cycle's date from `billing_period_day` in `advanceNextDate()` (re-anchor: next month, clamped day) instead of pure date arithmetic, so a Feb-28 clamp recovers to the 31st in March. Confirm desired semantics with Will before re-anchoring (behaviour change); the pure clamp fix is safe either way.
- Tests (ProRataCalculatorTest / RecurringInvoiceTest): day 31 + February start; day 30 + February; day 1 unchanged.

### 2.3 C6 / T7 — Overpayment guard
- `DocumentService::recordPayment()`: throw `InvalidArgumentException` when `$amount <= 0` or `$amount > balance_due + ε` (allow small FX rounding when `finaliseRate`).
- Sales-invoices payment modal: validate amount ≤ balance due, show inline error.
- Tests: overpayment rejected; exact payment → `paid`; partial → `partially_paid`.

### 2.4 P2 — HTTP client hardening
- `LlmService::callApi()`: `Http::timeout(110)->connectTimeout(10)->retry(2, 1000, throw: false)` (keep total under the job's 120s; retry only on connection errors so we don't double-bill on 5xx after acceptance — use `retry` callback to skip non-idempotent cases, or simply timeout without retry given job-level tries from 1.3 are now safe).
- `ExchangeRateService::fetchRates()`: `Http::timeout(15)->retry(2, 500)`.
- Tests: fake timeout → `LlmApiException` surfaced and logged to `llm_logs` with error.

---

## Phase 3 — Performance (P3–P7, P6)

### 3.1 P3 — Purchase/sales index render cost
- Drop `lines.account` eager load from the list query; if a line count/total is displayed use `withCount('lines')` (totals already denormalised on `documents`).
- Cache dropdown datasets per request via `#[Computed]` (suppliers/clients/accounts) so modal toggles and search keystrokes don't refetch; select only `id` + display columns.
- Compute `statusCounts` in a single query (already is) but skip it when a status filter interaction didn't change data — acceptable to leave as-is if measurement says cheap.
- Verify: Telescope/debugbar query count on `/purchase-invoices` interaction before vs after (expect drop from ~8+ to ~4 queries on a search keystroke); PageSmokeTest + PurchaseInvoiceUiTest stay green.

### 3.2 P4 — Batch total recalculation in pipeline
- In `InvoiceProcessingService::process()`: wrap line creation with `DocumentLine::withoutEvents()`? No — `saving` computes line totals. Instead add a static flag or use `Document::recalculateTotals()` once after the loop: cheapest correct approach is to keep per-line `calculateTotals` (saving event) but suppress the `saved` → document recalc during the loop (e.g. `DocumentLine::$suspendDocumentRecalc = true` guard or move recalc call out of the event into the three call sites). Pick the smallest diff; assert totals unchanged via existing InvoiceProcessingServiceTest expectations.

### 3.3 P5 — Memoise base currency
- `Document::isForeignCurrency()`: `once(fn () => app(CurrencySettings::class)->base_currency)` or static property. Trivial; existing ForeignCurrencyModelTest covers behaviour.

### 3.4 P6 — Soft-delete filter on report joins
- Add `->whereNull('documents.deleted_at')` to both expenses reports. Add report aggregate test (T6): one posted invoice counted; soft-deleted document's lines excluded; date boundaries inclusive.

### 3.5 P7 — LLM model to config
- `config/services.php`: `'anthropic' => ['key' => …, 'model' => env('ANTHROPIC_MODEL', '<current sonnet alias>')]`; `LlmService` reads config. Update `.env.example`. LlmServiceTest asserts request body uses configured model.

### 3.6 Index audit (from P-section positives)
- Check migrations for indexes on: `documents(document_type, status)`, `documents(party_id)`, `document_lines(document_id)`, `document_lines(account_id)`, `media(custom_properties->sha256)` (likely missing — JSON path; add a generated/virtual column + index in MariaDB if duplicate check is hot, else accept table scan on media which is small).
- Add only what `EXPLAIN` on the real MariaDB dev DB justifies.

---

## Phase 4 — Functional completeness (G-series; needs Will's sign-off on scope)

Present these as options before building:

### 4.1 G7 — Surface extraction failures (recommended, M)
- Add `extraction_failed` boolean or reuse activity: list page badge when latest activity is a failed extraction and status is `received`; "Retry" button calls existing reprocess. Tests: failed job → badge visible; retry clears it.

### 4.2 G2 — Purchase invoice payments (M–L)
- Extend purchase status map: `posted → partially_paid → paid` (or track payment purely via `amount_paid/balance_due` without new statuses — smaller change, reports filter on `balance_due`). Reuse `recordPayment()` (already FX-aware); add payment modal to purchase detail flyout mirroring sales. Tests mirror `SalesInvoicePaymentTest` incl. `finaliseRate` path.

### 4.3 G6 — Recurring auto-send flag (S)
- `recurring_invoices.auto_send` boolean (default true). When false, generated invoice stays `draft`, no email; appears in sales invoices for manual send. Form checkbox + tests.

### 4.4 G5 — Watch folder multi-format (S)
- Glob `pdf|docx|xlsx|csv` (case-insensitive), switch `assertIsPdf` → `assertIsSupportedFormat`. Extend WatchInvoiceFolderCommandTest with a CSV fixture.

### 4.5 G1 / G3 / G4 — GL journal layer, foreign-currency sales, credit notes
- Scope decisions, not fixes. Write up options + effort; do not implement without explicit go-ahead. Until G3 is decided, validate/reject non-base `currency` input in `createTemplate` rather than silently storing it.

### 4.6 G9 — Competitive roadmap backlog (from competitive-plan.html)
Outstanding items recorded in REVIEW.md §G9, in plan priority order. Suggested build order (each its own commit + tests, after Phases 0–2 land):
1. **Dashboard** (plan 1.1, "quick win") — Volt page replacing the Breeze stub; metric cards + activity feed; read-only queries, no new models.
2. **View tracking + public invoice link** (1.2/1.3) — migration for `sent_at`/`viewed_at`/`email_token` on documents (or metadata), signed public route `/invoices/{token}`, stamp on first view, badges on sales list.
3. **Payment reminders** (1.4) — `invoices:send-reminders` scheduled daily, trigger offsets −3d/due/+7d/+14d, `reminder_paused` flag, global toggle + message text in billing settings, activity-log every send.
4. **Product library** (2.1) — `Product` model in Billing, `/products` CRUD via HasCrudTable/HasCrudForm, type-ahead in invoice line form; free-text lines keep working.
5. **Quotes** (2.2) — `quote` document type in `config/documents.php` (HasDocumentNumber handles numbering), status map draft→sent→accepted/declined/expired in `DocumentService`, custom page (NOT HasCrudForm), convert-to-invoice clones lines, quote PDF view, token email reuse from item 2.
6. **AR aging report** (2.3) + **VAT report** (2.4) — new report Volt pages + `_subnav` + both nav files.
7. **Phase 3 moat** (portal, PayFast, AI AR drafting, AP/AR reconciliation) — separate planning round; portal needs its own auth guard and party-scoped policies.

---

## Phase 5 — Test debt (T3, T6, T8)

### 5.1 T3 — FinancialYearService unit tests
- Fiscal-year bounds for start months 1, 3 (SA convention), 12; dates on/before/after boundary; month label ordering.

### 5.2 T6 — Report correctness tests
- Covered in 3.4; add expenses-by-supplier equivalent.

### 5.3 T8 — One MariaDB integration pass
- Run suite (or at minimum media/duplicate + morph-heavy tests) against the dev MariaDB once: `php artisan test --compact --filter='PurchaseInvoiceUploadTest|SuppliersTest'` with `DB_CONNECTION=mariadb` env override pointed at a scratch database (never the live dev data; do not wipe existing data per house rule — use a dedicated `merlin_test` schema).

### 5.4 Misc hardening from review §6
- `editLine()` add `authorize('view', $line->document)`.
- Bulk actions: catch `InvalidDocumentStateException` only; report real failures in the flash ("3 posted, 1 failed").

---

## Suggested commit sequence

| Order | Commit | Findings |
|---|---|---|
| 1 | test(billing): freeze time in recurring tests; cover pause/activate; commit pending UI work | F1, T2, T4, G8 |
| 2 | test(settings): update currency settings test for company fields | F2 |
| 3 | fix(billing): respect requested currency on recurring templates | C1 |
| 4 | ~~fix(purchasing): group orWhere~~ — withdrawn, coverage test only | C2 |
| 5 | fix(purchasing): make invoice processing job idempotent | C3 |
| 6 | fix(purchasing): omit base64 payloads from llm_logs | P1 |
| 7 | fix(billing): atomic recurring generation + schedule overlap guard | C5 |
| 8 | fix(billing): clamp billing_period_day to month length | C4 |
| 9 | fix(documents): reject overpayments | C6 |
| 10 | chore(http): timeouts/retries for LLM + FX clients | P2 |
| 11 | perf(pages): trim index page queries | P3–P5 |
| 12 | fix(reports): exclude soft-deleted documents; add aggregate tests | P6, T6 |
| 13 | chore(llm): model from config | P7 |
| 14 | test(accounting): FinancialYearService coverage | T3 |
| 15+ | Phase 4 items individually after scope sign-off | G2, G5–G7 |

Full-suite gate (`composer run test`) after every commit; `vendor/bin/pint --dirty` throughout.
