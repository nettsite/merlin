# Billing Module Spec

## Overview

Outbound billing: sales invoices, client management, payment terms, payment recording, and recurring invoice automation with pro rata support. Builds on existing `Document`, `Party`/`PartyRelationship`, and Chart of Accounts infrastructure.

---

## 1. Clients

### 1.1 Client as Party
- A client is a `Party` with `relationship_type = 'client'` via `PartyRelationship` (same pattern as suppliers).
- A `Party` may be both supplier and client simultaneously.
- CRUD Volt page at `/clients` — mirrors `/suppliers` using `HasCrudTable` + `HasCrudForm`.
- Client form includes payment terms selector (see §2).

### 1.2 Client Debtor GL Account
- When a Party gains the `'client'` relationship, `ClientAccountService` auto-creates a dedicated `Account`.
- Account group: Debtors (seed if not present).
- Account code: sequential from `BillingSettings::debtor_account_code_start` (e.g. 110001, 110002, …).
- Account name: client's `displayName` at creation time.
- `parties` table gains `client_account_id` (UUID FK → accounts, nullable).
- The client's debtor account is the receivable account on all their sales invoices.

### 1.3 Invoice Contacts
- `contact_assignments` gains `receives_invoices boolean default false`.
- Invoice recipient list defaults to contacts flagged `receives_invoices = true`.
- User can override per invoice (or per recurring template) by selecting from all contacts on that client.
- Recurring template stores `contact_ids` as JSON; null = resolve from flagged contacts at generation time.

---

## 2. Payment Terms

### 2.1 PaymentTerm Model (`app/Modules/Billing/Models/PaymentTerm.php`)

Reusable, named payment term rules assigned to clients and/or recurring templates.

| Column | Type | Notes |
|---|---|---|
| `id` | UUID | |
| `name` | string | e.g. "30 Days", "EOM+1", "25th of Month" |
| `rule` | enum | See §2.2 |
| `days` | int nullable | Used by `days_after_invoice`, `n_working_days_before_month_end` |
| `day_of_month` | int nullable | Used by `nth_of_following_month` (1–28) |

Traits: `HasUuids`, `HasFactory`, `LogsActivity`, `SoftDeletes`.

### 2.2 Due Date Rules (enum: `PaymentTermRule`)

| Value | Description | Parameters |
|---|---|---|
| `days_after_invoice` | Due N days after invoice date | `days` |
| `nth_of_following_month` | Due on day N of the month following the invoice | `day_of_month` |
| `first_business_day_of_following_month` | First Mon–Fri of the following month | — |
| `same_as_invoice_date` | Due on the invoice date itself | — |
| `beginning_of_next_billing_period` | Due on the next billing period start (uses `billing_period_day`) | — |
| `n_working_days_before_month_end` | N working days before the last day of the invoice month | `days` |

"Working days" = Mon–Fri; public holiday calendar is a future enhancement (skip for now).

### 2.3 Due Date Calculation Service (`DueDateCalculator`)

`calculate(Carbon $invoiceDate, PaymentTerm $term, int $billingPeriodDay): Carbon`

- Encapsulates all rule logic.
- `billing_period_day` sourced from `BillingSettings` (overrideable per template).
- For `n_working_days_before_month_end`: start from last calendar day of invoice month, subtract `days` skipping Sat/Sun.
- For `first_business_day_of_following_month`: first day of month+1, advance if Sat/Sun.
- For `beginning_of_next_billing_period`: next occurrence of `billing_period_day` after `invoiceDate`.

### 2.4 Assignment
- `parties` table gains `payment_term_id` (UUID FK → payment_terms, nullable).
- Default payment term (for clients without one) configured in `BillingSettings::default_payment_term_id`.
- Recurring template can override: `payment_term_id` on `recurring_invoices` (nullable; null = use client's term).

### 2.5 UI
- CRUD Volt page at `/payment-terms` (simple, via `HasCrudTable` + `HasCrudForm`).

---

## 3. Sales Invoice

### 3.1 Model
Reuses the existing `Document` model.

| Field | Value / Change |
|---|---|
| `document_type` | `sales_invoice` |
| `direction` | `outbound` |
| `prefix` | `SINV` (already in `config/documents.php`) |
| New: `receivable_account_id` | UUID FK → accounts — client's debtor GL account |
| New: `payment_term_id` | UUID FK → payment_terms, nullable — overrides client default |

`due_date` is calculated at invoice creation using `DueDateCalculator` (client's term, or template override).

### 3.2 Status Machine

```
draft → sent → partially_paid → paid
      ↘ voided
```

| Status | Meaning |
|---|---|
| `draft` | Editable; not sent to client |
| `sent` | Dispatched; triggers GL entry (Dr debtor, Cr income per line) |
| `partially_paid` | One or more payments; `balance_due > 0` |
| `paid` | `balance_due ≤ 0` |
| `voided` | Cancelled before payment |

Managed by `BillingService::transition()`.

### 3.3 Line Items
`DocumentLine` reused unchanged.
- `account_id` per line → income/revenue account (credited when invoice transitions to `sent`).
- Per-line tax: `tax_rate`, `tax_amount` — identical to purchase side.
- `calculateTotals()` / `recalculateTotals()` cascade already exists.

### 3.4 Accounting Entries (on `draft → sent`)

```
Dr  client debtor account (receivable_account_id)   total incl. tax
Cr  income account (DocumentLine.account_id)         line_total per line
Cr  tax liability account (BillingSettings)          tax_amount per line (if tax > 0)
```

Note: actual GL journal entry model is out of scope this phase. Account assignment is captured at document/line level; ledger posting is a future module.

### 3.5 UI (Volt: `/sales-invoices`)
Fully custom (like `/purchase-invoices`). Features:
- Client picker (clients only), contact/recipient selector
- Payment term picker (pre-filled from client; due date previewed live)
- Inline line editor (description, income account, qty, price, tax)
- Status action buttons: Send, Void, Record Payment
- "Send Invoice" — discrete action; opens recipient confirmation modal then sends email + transitions to `sent`
- PDF download
- Payment history list

---

## 4. Payments

### 4.1 Model
A payment is a `Document` with `document_type = 'payment'`.

| Field | Value |
|---|---|
| `document_type` | `payment` |
| `direction` | `inbound` |
| `prefix` | `PAY` |
| New: `bank_account_id` | UUID FK → accounts — bank/cash GL account that received the money |

`bank_account_id` pre-filled from `BillingSettings::default_bank_account_id`; user can override per payment.

### 4.2 Linking
- Payment → invoice via `DocumentRelationship` (`relationship_type = 'payment_for'`).
- Multiple payments per invoice supported (partial payments).
- After recording: `invoice.amount_paid` = Σ linked payment totals; `balance_due` recalculated; status → `partially_paid` or `paid`.

### 4.3 Accounting Entries (on recording)

```
Dr  bank/cash account (bank_account_id)   payment total
Cr  client debtor account                 payment total
```

### 4.4 UI
- "Record Payment" flyout on invoice detail.
- Fields: date, amount, bank account (default from settings, editable), reference.
- Payment history shown on invoice.

---

## 5. Recurring Invoices

### 5.1 Concepts and Naming

| Concept | Field name | Meaning |
|---|---|---|
| When next invoice is generated | `next_invoice_date` | Date the console command will next fire for this template |
| When the generated invoice is due | Calculated at generation time via `DueDateCalculator` | Stored on the generated `Document.due_date` |
| Invoice date | Generation date (today when the command runs) | Stored on `Document.issue_date` |

### 5.2 Billing Period

- **Default period start day**: `BillingSettings::billing_period_day` (int, default 1 = 1st of each month).
- **Template override**: `recurring_invoices.billing_period_day` (int nullable; null = use BillingSettings).
- The billing period anchors both `next_invoice_date` calculation and pro rata computation.

### 5.3 RecurringInvoice Model (`app/Modules/Billing/Models/RecurringInvoice.php`)

| Column | Type | Notes |
|---|---|---|
| `id` | UUID | |
| `client_id` | UUID FK → parties | |
| `payment_term_id` | UUID FK → payment_terms, nullable | Overrides client's term; null = use client's |
| `contact_ids` | JSON nullable | Override recipients; null = resolve from flagged contacts |
| `frequency` | enum: monthly, quarterly, annually | |
| `billing_period_day` | int nullable | Period start day override (1–28); null = BillingSettings |
| `start_date` | date | When billing begins (may be mid-period → pro rata) |
| `end_date` | date nullable | Stop after this date; null = indefinite |
| `next_invoice_date` | date | Date of next generation run |
| `status` | enum: active, paused, completed | |
| `currency` | string | ISO 4217 |
| `notes` | text nullable | |
| `terms` | text nullable | |
| `footer` | text nullable | |
| `receivable_account_id` | UUID FK → accounts | Client's debtor account (copied from client at template creation) |

Traits: `HasUuids`, `HasFactory`, `LogsActivity`, `SoftDeletes`.

### 5.4 RecurringInvoiceLine Model (`app/Modules/Billing/Models/RecurringInvoiceLine.php`)

| Column | Type |
|---|---|
| `id` | UUID |
| `recurring_invoice_id` | UUID FK |
| `line_number` | int |
| `description` | string |
| `account_id` | UUID FK → accounts (income) |
| `quantity` | decimal(10,4) |
| `unit_price` | decimal(10,4) |
| `discount_percent` | decimal(5,2) default 0 |
| `tax_rate` | decimal(5,2) nullable |
| `notes` | text nullable |

### 5.5 Pro Rata First Invoice

Triggered at template creation if `start_date.day ≠ billing_period_day`.

**Calculation:**
- `period_start` = most recent period start on or before `start_date`
- `period_end` = day before the next period start
- `days_in_period` = calendar days in that period
- `days_active` = `period_end` − `start_date` + 1
- `pro_rata_factor` = `days_active / days_in_period`
- Applied per line as a `quantity` multiplier (so original unit_price is preserved)
- `description` suffix: " (pro rata {start_date} – {period_end})"

**Timing:** Generated immediately when template is saved. Status: `sent`. Email sent to recipients.

**`next_invoice_date`** after pro rata: set to the next full period start.

### 5.6 Artisan Command: `billing:generate-recurring`

```
php artisan billing:generate-recurring [--dry-run]
```

For each active template where `next_invoice_date ≤ today`:
1. Create `Document` (`document_type = sales_invoice`, `status = sent`, `issue_date = today`).
2. Calculate `due_date` via `DueDateCalculator`.
3. Copy lines from `RecurringInvoiceLine` → `DocumentLine` (full amounts; no pro rata on subsequent invoices).
4. Set `receivable_account_id`, `payment_term_id`, `party_id`.
5. Trigger email send to resolved recipients.
6. Advance `next_invoice_date` by frequency from the current `next_invoice_date` (not from today — prevents drift).
7. If `next_invoice_date > end_date` (when set), mark template `status = completed`.
8. `Log::info` each generation with document number and client name.

### 5.7 UI (Volt: `/recurring-invoices`)
`HasCrudTable` + `HasCrudForm`. Table: client, frequency, `next_invoice_date`, status. Form: client picker, payment term, frequency, `billing_period_day`, start/end date, currency, contacts, notes/terms/footer, inline line editor. Show history of generated invoices.

---

## 6. PDF & Email

### 6.1 PDF Generation
- Blade template: `resources/views/pdf/sales-invoice.blade.php`.
- **Try Paperdoc first** for PDF output. Fall back to `barryvdh/laravel-dompdf` if Paperdoc cannot generate.
- Generated PDF stored as Spatie media on `Document` (collection: `invoice_pdf`). Regenerated on demand.

### 6.2 Email Send
- Mailable: `App\Mail\SalesInvoiceMail`; view: `resources/views/mail/sales-invoice.blade.php`.
- Attaches generated PDF.
- Recipients resolved at send time (flagged contacts or template override).
- On successful send (manual): `draft → sent`.
- Recurring generation: email fires automatically after invoice is created.

---

## 7. Settings: BillingSettings

`app/Modules/Billing/Settings/BillingSettings.php`

| Property | Type | Default | Description |
|---|---|---|---|
| `debtor_account_code_start` | int | 110000 | First sequential code for client debtor accounts |
| `default_bank_account_id` | ?string | null | Default bank/cash GL account for payments |
| `default_payment_term_id` | ?string | null | Fallback payment term when client has none |
| `tax_liability_account_id` | ?string | null | Output tax (VAT) GL account |
| `billing_period_day` | int | 1 | Default billing period start day |

Settings page: `/settings/billing` (Volt, mirrors `/settings/purchasing`).

---

## 8. Data Model Changes

### New Tables
| Table | Purpose |
|---|---|
| `payment_terms` | Named, reusable payment term rules |
| `recurring_invoices` | Recurring invoice templates |
| `recurring_invoice_lines` | Template line items |

### Modified Tables
| Table | Change |
|---|---|
| `contact_assignments` | `+ receives_invoices boolean default false` |
| `documents` | `+ receivable_account_id UUID FK nullable` |
| `documents` | `+ bank_account_id UUID FK nullable` |
| `documents` | `+ payment_term_id UUID FK nullable` |
| `parties` | `+ client_account_id UUID FK nullable` |
| `parties` | `+ payment_term_id UUID FK nullable` |

### Config Changes
| File | Change |
|---|---|
| `config/documents.php` | Add `payment` type (direction: inbound, prefix: PAY, default_status: draft) |
| `AppServiceProvider` morph map | Add `RecurringInvoice`, `PaymentTerm` |

### New Seeders
- `PaymentTermSeeder` — seed common terms: "30 Days", "EOM", "25th of Month", etc.
- `DebtorAccountGroupSeeder` — ensure Debtors account group exists.

---

## 9. Module Structure

```
app/Modules/Billing/
├── Models/
│   ├── PaymentTerm.php
│   ├── RecurringInvoice.php
│   └── RecurringInvoiceLine.php
├── Services/
│   ├── BillingService.php            — invoice creation, status transitions, payment recording
│   ├── ClientAccountService.php      — creates GL account when Party gains 'client' relationship
│   └── DueDateCalculator.php         — calculates due date from PaymentTerm + invoice date
├── Settings/
│   └── BillingSettings.php
└── Console/
    └── GenerateRecurringInvoices.php

resources/views/
├── livewire/pages/
│   ├── clients/index.blade.php
│   ├── sales-invoices/index.blade.php
│   ├── recurring-invoices/index.blade.php
│   └── payment-terms/index.blade.php
├── pdf/
│   └── sales-invoice.blade.php
└── mail/
    └── sales-invoice.blade.php
```

---

## 10. Out of Scope (this phase)

- Credit notes (document type exists; UI deferred)
- Quotations
- Full double-entry GL ledger / journal entry model
- Public holiday awareness in working-day calculations
- Late payment interest / automated reminders
- Client statements
- Bulk invoice operations
- Multi-currency payment matching / FX gain/loss
