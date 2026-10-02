### Domain Structure (`app/Modules/`)

```
app/Ai/
└── Agents/     — InvoiceExtractionAgent, BankStatementExtractionAgent, PaymentNotificationExtractionAgent,
                — BankTemplateHintsAgent, PdfVisionExtractionAgent (laravel/ai Agent classes; see Invoice Processing Pipeline below)

app/Modules/
├── Core/
│   ├── Models/     — User, Role, Permission, Party, Person, Business, Address, ContactAssignment, PartyRelationship,
│   │               — Document, DocumentLine, DocumentActivity, DocumentRelationship, PaymentTerm, BankTemplate, LlmLog
│   ├── Services/   — PartyService, DocumentService, LlmService, ModelHealthService, IncidentSyncService,
│   │               — BankStatementProcessingService, DocumentTextExtractor,
│   │               — Pdf/MagikaService, Pdf/PdfExtractor
│   ├── Contracts/  — IncidentDetector (implemented per-module, run by IncidentSyncService)
│   ├── Jobs/       — ProcessBankStatementDocument (queued)
│   ├── DTO/        — ExtractedBankStatement, ExtractedBankTransaction, MagikaResult
│   ├── Settings/   — CurrencySettings (base_currency, locale), CompanySettings
│   └── Policies/   — AllModulesPolicy (base; all domain policies extend this)
├── Accounting/
│   ├── Models/     — Account, AccountGroup, AccountType
│   ├── Services/   — FinancialYearService (fiscal year bounds, month labels),
│   │               — AccountBalanceRollup (sub-account rollup); the ledger itself is the
│   │               — `postings` SQL view, not a service — see Journal section below
│   └── Settings/   — AccountingSettings (financial_year_start_month)
├── Purchasing/
│   ├── Models/     — PostingRule
│   ├── Services/   — InvoiceProcessingService, DocumentService, SupplierResolver, AccountResolver,
│   │               — SupplierPayableAccountService, ExchangeRateService, PostingRuleService,
│   │               — DocumentKindClassifier, DuplicateInvoiceMerger,
│   │               — PaymentNotificationMatcher, PaymentNotificationProcessingService, PaymentEvidenceRecorder,
│   │               — UnpostedInvoicesIncidentDetector, InvalidPurchasingSettingsIncidentDetector (IncidentDetector impls)
│   ├── Jobs/       — ProcessInvoiceDocument (queued)
│   ├── DTO/        — ExtractedInvoice, ExtractedInvoiceLine, ExtractedPaymentNotification
│   └── Settings/   — PurchasingSettings (autopost_confidence, tax_default_rate, etc.)
└── Billing/
    ├── Models/     — RecurringInvoice, RecurringInvoiceLine, BillingEmailTemplate
    ├── Enums/      — PaymentTermRule, RecurringFrequency, RecurringInvoiceStatus
    ├── Console/    — GenerateRecurringInvoices, SendReminders, ImportFromNinja, BackfillClientReceivableAccounts (`accounts:backfill-client-receivables`)
    └── Services/   — BillingService (PDF generation), RecurringInvoiceService, ClientReceivableAccountService,
                     — InvoiceEmailTemplateService, DueDateCalculator, ProRataCalculator, WorkingDayCalculator (ZA public holidays), PortalInviteService

app/Policies/       — 19 domain policies, all extend AllModulesPolicy
app/Traits/         — HasDocumentNumber (auto-generates PREFIX-YEAR-NNNNN on create)
app/Exceptions/     — InvalidDocumentStateException, InvalidFileTypeException, LlmApiException, LlmUnusableOutputException, PdfExtractionException,
                    — PostedDocumentImmutableException
```

**Note:** `Purchasing\Services\DocumentService` (purchase-invoice status transitions) and `Core\Services\DocumentService` (shared document plumbing) are two distinct classes — check the namespace, not just the class name.

**Incident framework:** any class implementing `Core\Contracts\IncidentDetector` gets registered into `IncidentSyncService` (wired in `AppServiceProvider`) and is polled to open/refresh/clear `NotificationIncident` rows — surfaced via the dashboard incident bell. See [[project_incident_notifications]].

**Party model uses Class Table Inheritance:** `Party` is the parent; `Person` and `Business` share its primary key. Use `$party->person` / `$party->business` and `$party->displayName`.

### Pages (`resources/views/livewire/pages/`)

All pages are Livewire 4 native single-file components (`new class extends Component`). CRUD pages use `HasCrudTable` + `HasCrudForm` concerns.

Routes use `Route::livewire()` unless marked *view*. Any test that renders a view requires a built Vite manifest (`npm run build`).

| Route | File | Notes |
|---|---|---|
| `/dashboard` | `resources/views/dashboard.blade.php` | `Route::view`, requires auth+verified |
| `/profile` | `resources/views/profile.blade.php` | `Route::view`, requires auth |
| `/suppliers` | `suppliers/index.blade.php` | CRUD |
| `/suppliers/{id}` | `suppliers/show.blade.php` | Read-only detail |
| `/purchase-invoices` | `purchase-invoices/index.blade.php` | **Fully custom** — file upload, LLM pipeline, inline line editing, status machine |
| `/payment-notifications` | `payment-notifications/index.blade.php` | **Fully custom** — unmatched supplier payment notifications, link-to-invoice workflow |
| `/journals` | `journals/index.blade.php` | **Fully custom** — manual GL journal entries, debit/credit line editor, balance-gated posting. See "Manual journals" below |
| `/bank-statements` | `bank-statements/index.blade.php` | **Fully custom** — upload PDF statements, LLM extraction, reconciliation (match against an existing document, or create one on the spot), reprocess with hint. Statements never post — see Journal section |
| `/bank-templates` | `bank-templates/index.blade.php` | CRUD |
| `/posting-rules` | `posting-rules/index.blade.php` | CRUD |
| `/settings/purchasing` | `settings/purchasing.blade.php` | Standalone page (not a Settings tab) — lives under the "Expenses" nav group, not `/settings` |
| `/contacts` | `contacts/index.blade.php` | Read-only list of persons with active contact assignments |
| `/clients` | `clients/index.blade.php` | CRUD |
| `/clients/{id}` | `clients/show.blade.php` | Read-only detail with sales invoice transaction history |
| `/sales-invoices` | `sales-invoices/index.blade.php` | CRUD |
| `/quotes` | `quotes/index.blade.php` | CRUD (uses Document model, type=quote) |
| `/credit-notes` | `credit-notes/index.blade.php` | CRUD (uses Document model, type=credit_note) |
| `/recurring-invoices` | `recurring-invoices/index.blade.php` | CRUD |
| `/payment-terms` | `payment-terms/index.blade.php` | CRUD |
| `/accounts` | `accounts/index.blade.php` | CRUD |
| `/accounts/{id}` | `accounts/show.blade.php` | Read-only transaction register for one account, sourced from the `postings` view (see Journal section) |
| `/account-groups` | `account-groups/index.blade.php` | CRUD |
| `/roles` | `roles/index.blade.php` | CRUD |
| `/users` | `users/index.blade.php` | CRUD |
| `/llm-logs` | `llm-logs/index.blade.php` | Read-only |
| `/help` | `help/index.blade.php` | AI help chat, reads `storage/app/docs/*.md` (see [[project_help_chat_docs_sync]]) |
| `/reports` | — | Redirects to `/reports/income-statement` |
| `/reports/income-statement` | `reports/income-statement.blade.php` | Read-only; sourced from the journal, uses FinancialYearService |
| `/reports/trial-balance` | `reports/trial-balance.blade.php` | Read-only; sourced from the journal (single grouped query) |
| `/reports/balance-sheet` | `reports/balance-sheet.blade.php` | Read-only; sourced from the journal; accounting equation validation |
| `/reports/income-by-account` | `reports/income-by-account.blade.php` | Read-only; reads `document_lines`/`documents` directly, not the journal — needs per-line VAT and invoice counts the journal doesn't preserve at that granularity (see Journal section) |
| `/reports/income-by-client` | `reports/income-by-client.blade.php` | Read-only; same as above — also needs `balance_due`, a document field with no journal equivalent |
| `/reports/expenses-by-account` | `reports/expenses-by-account.blade.php` | Read-only; document-based, same reason as income-by-account |
| `/reports/expenses-by-supplier` | `reports/expenses-by-supplier.blade.php` | Read-only; document-based, same reason as income-by-client |
| `/reports/llm-performance` | `reports/llm-performance.blade.php` | Read-only |
| `/settings` | `settings/index.blade.php` | Multi-tab: General / Accounting / Billing / Roles / Email Templates. `/settings/general`, `/settings/billing` are `Route::redirect` shims to `/settings?tab=...`. Purchasing settings moved out to `/settings/purchasing` (see above) |
| `/portal/login` | `portal/auth/login.blade.php` | Guest-only, `auth:portal` guard |
| `/portal/set-password/{token}` | `portal/auth/set-password.blade.php` | Invite accept / set password |
| `/documents/media/{media}` | — | Controller (`DocumentMediaController`), not Livewire. Streams private-disk document files behind `Gate::authorize` — never link `getUrl()` directly, see [[project_media_private_disk]] |

### CRUD Framework

```
app/Livewire/Concerns/
├── HasCrudTable.php   — WithPagination, $search, $sortBy, $sortDir, $perPage; sort(), updatedSearch()
└── HasCrudForm.php    — $showForm, $editingId; create(), edit(), save(), delete(), cancelForm()
                         Abstract: fillForm(), store(), update(), performDelete()

resources/views/components/
├── layout/app.blade.php, nav.blade.php, top-nav.blade.php
└── crud/table.blade.php, th.blade.php, form.blade.php   ← centered modal, 2-column
```

**CRUD form layout rule:** `<x-crud.form>` renders as a centered `<flux:modal>` (`max-w-2xl`), with its slotted `<flux:field>` children laid out in a responsive 2-column grid (`grid-cols-1 sm:grid-cols-2`, single column below `sm`). This is a single shared component — every CRUD page using `<x-crud.form>` gets this automatically, no per-page changes needed. Any field that shouldn't be squeezed to half-width (a `<flux:textarea>`, a field with a long description, etc.) needs `<flux:field class="sm:col-span-2">` explicitly on that field. When a page's create/edit form is duplicated elsewhere (e.g. a list page's inline form AND a detail page's own modal — see Suppliers), don't keep two copies: extract a shared nested Livewire component instead (see [[feedback_shared_form_modal_pattern]]) and wire it with `dispatch()`/`#[On(...)]`, matching `resources/views/livewire/suppliers/form-modal.blade.php`.

Flux UI components (`<flux:input>`, `<flux:select>`, etc.) used directly — no custom field abstraction.
