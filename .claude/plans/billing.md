# Billing Module — Implementation Plan

Spec: `.claude/specs/billing.md`
Branch: `main` (push directly as per project convention)

Each phase is independently testable. Complete all verification steps before starting the next phase.

---

## Phase 1 — Foundation (DB + Config)

**Goal:** All tables, columns, and config in place; app boots cleanly.

### Tasks

1. **Migrations** (run in order):
   - `create_payment_terms_table` — UUID PK, `name`, `rule` enum, `days`, `day_of_month`, timestamps, soft-deletes
   - `create_recurring_invoices_table` — all columns from spec §5.3
   - `create_recurring_invoice_lines_table` — all columns from spec §5.4
   - ~~`add_billing_columns_to_party_relationships`~~ — superseded by Phase 1a (metadata convention); this migration will be revised to a no-op or deleted before `migrate:fresh`
   - `add_billing_columns_to_documents` — `receivable_account_id`, `bank_account_id`, `payment_term_id` (all UUID FK nullable)
   - `add_receives_invoices_to_contact_assignments` — `receives_invoices boolean default false`

2. **Enum classes**:
   - `App\Modules\Billing\Enums\PaymentTermRule` (6 cases from spec §2.2)
   - `App\Modules\Billing\Enums\RecurringFrequency` (`Monthly`, `Quarterly`, `Annually`)
   - `App\Modules\Billing\Enums\RecurringInvoiceStatus` (`Active`, `Paused`, `Completed`)

3. **Config changes**:
   - `config/documents.php` — add `payment` type (`direction: inbound, prefix: PAY, default_status: draft`)

4. **Morph map** (`AppServiceProvider`):
   - Add `'payment_term'` → `PaymentTerm::class`
   - Add `'recurring_invoice'` → `RecurringInvoice::class`

5. **Seeders**:
   - `DebtorAccountGroupSeeder` — ensure "Debtors" account group exists (type: Asset)
   - `PaymentTermSeeder` — seed: "30 Days" (days_after_invoice, 30), "EOM" (first_business_day_of_following_month), "25th of Month" (nth_of_following_month, 25), "Immediate" (same_as_invoice_date)

### Verify
```bash
php artisan migrate
php artisan db:seed --class=DebtorAccountGroupSeeder
php artisan db:seed --class=PaymentTermSeeder
php artisan test --compact --filter=Phase1  # placeholder; write a smoke test asserting migrations ran
```

---

## Phase 1a — Relationship Metadata Convention

**Goal:** `party_relationships.metadata` is the single source of truth for all per-type data. No typed columns for type-specific fields. Existing supplier code updated to read/write through metadata. Full suite still green.

**Approach:** `migrate:fresh --seed` (dev workstation, no real data). Correct migrations at source rather than layering alter-table patches.

### Tasks

1. **Revise `create_party_relationships_table` migration**:
   - Remove `default_payable_account_id` column entirely. It has no FK constraint and is never read by the invoice pipeline. It moves into `metadata`.

2. **Revise `add_billing_columns_to_party_relationships` migration** (Phase 1 migration):
   - Remove `default_receivable_account_id` and `payment_term_id` dedicated columns. These belong in `metadata`, not as typed columns.
   - Migration becomes a no-op structurally (the table already has `metadata`). Can be deleted or left empty depending on whether it simplifies history.

3. **`PartyRelationship` model** — add Eloquent Attribute accessors that transparently read/write `metadata`:
   - `default_payable_account_id` — reads/writes `metadata['default_payable_account_id']`
   - `default_receivable_account_id` — reads/writes `metadata['default_receivable_account_id']`
   - `payment_term_id` — reads/writes `metadata['payment_term_id']`
   - Remove `default_payable_account_id` from `$fillable` (setters go through the accessor)
   - Setters merge into `metadata`, never replace it wholesale

4. **`DocumentService::createDocument()`** — add per-supplier payable account override:
   - When creating a purchase invoice, check supplier's `PartyRelationship` for `default_payable_account_id` in metadata first; fall back to `PurchasingSettings::default_payable_account` account code

5. **Supplier CRUD (`/suppliers/index.blade.php`)** — add two fields to the form:
   - "Default Payable Account" — select from Liability accounts; saved to supplier relationship `metadata`
   - "Payment Terms" — select from `PaymentTerm::all()`; saved to supplier relationship `metadata`
   - On `edit()`: populate from supplier relationship metadata
   - On `save()`: update metadata on the relationship row (not on `Party` directly)

6. **`migrate:fresh --seed`** — run with all standard seeders:
   ```bash
   php artisan migrate:fresh --seed --seeder=DatabaseSeeder
   ```
   Ensure `DatabaseSeeder` calls `ChartOfAccountsSeeder`, `RolesAndPermissionsSeeder`, `DefaultAdminUserSeeder`, `DebtorAccountGroupSeeder`, `PaymentTermSeeder`.

7. **Update tests** — check for any test that sets or asserts `default_payable_account_id` as a direct column attribute; update to use the accessor or metadata array.

### Verify
```bash
php artisan migrate:fresh --seed
php artisan test --compact
```
Full suite must be green before proceeding to Phase 2.

---

## Phase 2 — Payment Terms

**Goal:** `PaymentTerm` model + `DueDateCalculator` fully tested; CRUD UI at `/payment-terms`.

### Tasks

1. **Model** `app/Modules/Billing/Models/PaymentTerm.php`:
   - Traits: `HasUuids`, `HasFactory`, `LogsActivity`, `SoftDeletes`
   - Cast `rule` → `PaymentTermRule` enum
   - `getActivitylogOptions()` logging `name`, `rule`, `days`, `day_of_month`

2. **Factory** `database/factories/Modules/Billing/PaymentTermFactory.php`

3. **Service** `app/Modules/Billing/Services/DueDateCalculator.php`:
   - `calculate(Carbon $invoiceDate, PaymentTerm $term, int $billingPeriodDay): Carbon`
   - Implement all 6 rules (spec §2.3)
   - Working days = Mon–Fri only (no public holidays)

4. **BillingSettings** `app/Modules/Billing/Settings/BillingSettings.php`:
   - `billing_period_day` (int, default 1) — needed by DueDateCalculator
   - Remaining properties added later (Phase 6)

5. **Volt page** `resources/views/livewire/pages/payment-terms/index.blade.php`:
   - `HasCrudTable` + `HasCrudForm`
   - Form fields: name, rule (select with dynamic show/hide for `days` / `day_of_month`)
   - Route: `GET /payment-terms` → name `payment-terms`

6. **Nav link** in layout nav for Payment Terms (under Settings group or standalone)

### Tests
- `tests/Unit/Billing/DueDateCalculatorTest.php` — one case per rule, edge cases (month boundaries, Sat/Sun landing)
- `tests/Feature/Billing/PaymentTermCrudTest.php` — create, edit, delete via UI

### Verify
```bash
php artisan test --compact tests/Unit/Billing/DueDateCalculatorTest.php
php artisan test --compact tests/Feature/Billing/PaymentTermCrudTest.php
```

---

## Phase 3 — Clients

**Goal:** `/clients` CRUD works; gaining `'client'` relationship auto-creates debtor GL account.

### Tasks

1. **ClientAccountService** `app/Modules/Billing/Services/ClientAccountService.php`:
   - `createForRelationship(PartyRelationship $rel): Account`
   - Finds "Debtors" account group (seeded in Phase 1)
   - Next code = `BillingSettings::debtor_account_code_start` + count of existing debtor accounts
   - Creates `Account`, sets `rel->default_receivable_account_id` via the metadata accessor (Phase 1a)
   - Guard: if `default_receivable_account_id` already set in metadata, skip

2. **Hook into PartyService** (or `PartyRelationship` observer):
   - When `PartyRelationship` with `relationship_type = 'client'` is created, call `ClientAccountService::createForRelationship()`

3. **Volt page** `resources/views/livewire/pages/clients/index.blade.php`:
   - `HasCrudTable` + `HasCrudForm` — mirrors `/suppliers/index.blade.php` (which will already have been updated in Phase 1a)
   - Extra field in form: payment term selector — stored in client relationship `metadata` via accessor
   - Route: `GET /clients` → name `clients`

4. **`PartyRelationship` model** — `defaultReceivableAccount()` and `paymentTerm()` BelongsTo relationships added (accessors already exist from Phase 1a)

5. **Nav link** for Clients

### Tests
- `tests/Feature/Billing/ClientCrudTest.php` — CRUD, check debtor account created on `'client'` relationship
- `tests/Unit/Billing/ClientAccountServiceTest.php` — code sequencing, skip if already set

### Verify
```bash
php artisan test --compact tests/Feature/Billing/ClientCrudTest.php
php artisan test --compact tests/Unit/Billing/ClientAccountServiceTest.php
```

---

## Phase 4 — Sales Invoices (Core)

**Goal:** Create, edit, and transition draft sales invoices. No email/PDF yet.

### Tasks

1. **BillingService** `app/Modules/Billing/Services/BillingService.php`:
   - `createDraft(Party $client, array $data): Document`
     - Sets `document_type = sales_invoice`, `direction = outbound`, `status = draft`
     - Copies `receivable_account_id` from client's `client_account_id`
     - Resolves `payment_term_id` (explicit → client's → BillingSettings default)
     - Calls `DueDateCalculator::calculate()` → sets `due_date`
   - `markAsSent(Document $invoice): Document`
     - Transitions `draft → sent` (via `DocumentService::transition()`)
   - `voidInvoice(Document $invoice): Document`
     - Transitions `draft → voided` or `sent → voided`
   - Enum values for new statuses added to `config/documents.php` statuses list for `sales_invoice`

2. **Volt page** `resources/views/livewire/pages/sales-invoices/index.blade.php`:
   - Fully custom (mirrors `/purchase-invoices` structure)
   - Table: invoice number, client, issue date, due date, total, status
   - Detail/edit view: client picker, payment term picker (live due-date preview via Alpine), inline line editor
   - Status buttons: Send, Void (Record Payment in Phase 5)
   - Recipient selector: contacts flagged `receives_invoices`; plus manual override

3. **Route** `GET /sales-invoices` → name `sales-invoices`; **nav link**

4. **`contact_assignments` model** — add `receives_invoices` cast; expose in client form (Phase 3 form can be updated)

### Tests
- `tests/Feature/Billing/SalesInvoiceTest.php`:
  - Create draft, add lines, check totals
  - Send invoice (status transition)
  - Void invoice

### Verify
```bash
php artisan test --compact tests/Feature/Billing/SalesInvoiceTest.php
```

---

## Phase 5 — Payment Recording

**Goal:** Record payments against invoices; balance tracked; status auto-transitions.

### Tasks

1. **BillingService additions**:
   - `recordPayment(Document $invoice, array $data): Document`
     - Creates `Document` (`document_type = payment, direction = inbound, status = draft`)
     - `bank_account_id` from `$data` or `BillingSettings::default_bank_account_id`
     - Links via `DocumentRelationship` (`relationship_type = 'payment_for'`)
     - Recalculates `invoice.amount_paid` = Σ linked payments
     - Recalculates `invoice.balance_due` = `invoice.total_incl_tax - amount_paid`
     - Transitions invoice to `partially_paid` or `paid` based on `balance_due`

2. **"Record Payment" flyout** on sales invoice detail:
   - Fields: date, amount, bank account (default from settings, selectable from asset accounts), reference
   - On save: call `recordPayment()`, refresh page

3. **Payment history list** on invoice detail (linked `Document` records via `DocumentRelationship`)

4. **BillingSettings** — add `default_bank_account_id` (?string, null)

### Tests
- `tests/Feature/Billing/PaymentRecordingTest.php`:
  - Single payment → `paid`
  - Partial payment → `partially_paid`
  - Two partial payments → `paid`
  - Payment on non-sent invoice → error

### Verify
```bash
php artisan test --compact tests/Feature/Billing/PaymentRecordingTest.php
```

---

## Phase 6 — PDF & Email

**Goal:** Invoices downloadable as PDF; "Send Invoice" emails PDF to recipients.

### Tasks

1. **PDF Blade template** `resources/views/pdf/sales-invoice.blade.php`:
   - Company header, client address, invoice number/date/due date
   - Line items table (description, qty, unit price, tax, total)
   - Subtotal / tax / total incl. tax
   - Payment terms note, footer

2. **PDF generation in BillingService**:
   - Try Paperdoc first (`paperdoc-dev/paperdoc-lib`); fall back to `barryvdh/laravel-dompdf` on failure
   - Store generated PDF on `Document` via Spatie Media Library (collection: `invoice_pdf`)
   - `generatePdf(Document $invoice): void`

3. **Mailable** `App\Mail\SalesInvoiceMail`:
   - View: `resources/views/mail/sales-invoice.blade.php` — simple HTML email body
   - Attaches PDF (generated on demand if not cached)
   - Accepts `Document $invoice` and `array $recipients` (email strings)

4. **"Send Invoice" flow in BillingService**:
   - `sendInvoice(Document $invoice, ?array $recipientIds = null): Document`
   - Resolves recipients (param → flagged contacts → error if none)
   - Calls `generatePdf()`, then dispatches `SalesInvoiceMail` per recipient
   - Calls `markAsSent()` on success

5. **BillingSettings** — add `tax_liability_account_id` (?string, null)

6. **"Send Invoice" confirmation modal** on Volt page:
   - Lists resolved recipients (checkboxes to deselect)
   - Confirm → calls `sendInvoice()`

7. **PDF download button** on invoice detail

### Tests
- `tests/Feature/Billing/SendInvoiceTest.php` — email queued with attachment, status transitions, recipient resolution
- Use `Mail::fake()` and `Storage::fake()`

### Verify
```bash
php artisan test --compact tests/Feature/Billing/SendInvoiceTest.php
```

---

## Phase 7 — Recurring Invoices

**Goal:** Templates generate invoices (with pro rata first invoice); artisan command drives subsequent runs.

### Tasks

1. **Models**:
   - `app/Modules/Billing/Models/RecurringInvoice.php` — traits, casts for `frequency`/`status` enums, `contact_ids` as array, `lines()` hasMany
   - `app/Modules/Billing/Models/RecurringInvoiceLine.php`

2. **Factories** for both models

3. **ProRataCalculator** (or method inside `BillingService`):
   - `calculateProRataFactor(Carbon $startDate, int $billingPeriodDay): array` → `['factor', 'period_start', 'period_end', 'days_active', 'days_in_period']`
   - Triggered if `startDate->day !== billingPeriodDay`

4. **RecurringInvoiceService** `app/Modules/Billing/Services/RecurringInvoiceService.php`:
   - `generateFromTemplate(RecurringInvoice $template, bool $isProRata = false): Document`
     - Creates `Document`, copies lines, calls `sendInvoice()`
     - Pro rata: multiplies quantity per line by factor, appends description suffix
   - `advanceNextDate(RecurringInvoice $template): void` — adds frequency from current `next_invoice_date`
   - `createTemplate(array $data): RecurringInvoice` — saves template; if pro rata applies, generates immediately

5. **Artisan command** `app/Modules/Billing/Console/GenerateRecurringInvoices.php`:
   - `billing:generate-recurring [--dry-run]`
   - Loops active templates where `next_invoice_date ≤ today`
   - Calls `generateFromTemplate()`, `advanceNextDate()`, marks completed if past `end_date`
   - `Log::info` per generation

6. **Volt page** `resources/views/livewire/pages/recurring-invoices/index.blade.php`:
   - `HasCrudTable` + `HasCrudForm`
   - Inline line editor in form
   - Table: client, frequency, next date, status
   - History tab: generated invoices for this template

7. **Route** + **nav link** for Recurring Invoices

8. **Register command** in `Console/Kernel.php` (or `routes/console.php` for Laravel 13)

### Tests
- `tests/Unit/Billing/ProRataCalculatorTest.php` — factor, period boundaries, full-period case
- `tests/Feature/Billing/RecurringInvoiceTest.php` — template creation, pro rata generation, subsequent generation, advance date, complete on end_date, dry-run

### Verify
```bash
php artisan test --compact tests/Unit/Billing/ProRataCalculatorTest.php
php artisan test --compact tests/Feature/Billing/RecurringInvoiceTest.php
php artisan billing:generate-recurring --dry-run
```

---

## Phase 8 — Settings UI

**Goal:** `/settings/billing` page exposes all `BillingSettings` properties.

### Tasks

1. **Volt page** `resources/views/livewire/pages/settings/billing.blade.php`:
   - Mirrors `/settings/purchasing` structure
   - Fields: debtor account code start, default bank account (select from asset accounts), default payment term (select from `PaymentTerm::all()`), tax liability account (select from liability accounts), billing period day
   - Route: `GET /settings/billing` → name `settings.billing`; nav link under Settings

2. **`BillingSettings::debtor_account_code_start`** (int, 110000) — add if deferred from Phase 2

### Tests
- `tests/Feature/Billing/BillingSettingsTest.php` — save and retrieve each setting

### Verify
```bash
php artisan test --compact tests/Feature/Billing/BillingSettingsTest.php
```

---

## Phase 9 — Full Suite Pass

**Goal:** No regressions in existing tests; full suite green.

```bash
composer run test
vendor/bin/pint --dirty
```

Fix any failures before marking complete.

---

## Cross-cutting Notes

- **No Filament** anywhere in this module.
- Every new model: `getActivitylogOptions()` logging fillable fields.
- `BillingService::transition()` must use `DocumentService::transition()` — do not bypass state machine.
- `HasCrudForm` pages must return to table after create/edit (existing convention).
- Reprocess guard: `BillingService` methods that modify invoices must check status guards (e.g. can't edit `sent` invoices).
- All account selectors: filter by account type (assets for bank, income for lines, liability for tax).
- Auth: wrap all Billing routes in `auth` middleware; add `can:` gates as permissions are defined.
