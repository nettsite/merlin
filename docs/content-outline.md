# Merlin Documentation — Content Outline

Master brief for the **content pass**. One section per page, mirroring the
exact `<h1>/<h2>/<h3>` headings already stamped into each HTML file by
`scaffold.php`. Under each heading: **what goes there** + the **codebase
source** to write accurate prose from.

Conventions used below:
- **Source:** file path / route / model / permission to read before writing.
- **NEEDS CONFIRMATION:** a fact I could not verify in the codebase — confirm
  with a maintainer instead of guessing.
- Permission names are the literal Spatie permission strings (`can()` checks).
- All examples must be synthetic (e.g. *Acme Plumbing*, invoice `PINV-2026-00001`).
  Never use real business data.

> **Workflow:** Edit only text inside `<article>` in each HTML file. Headings,
> ids, sidebar, TOC, and footer are generated — do not touch them. To add or
> rename a page, edit the `NAV` tree in `assets/nav.js` **and** the `$pages`
> manifest in `scaffold.php` together, then re-run `php scaffold.php`.

---

## Getting Started

### index.html — Introduction
Source: `README.md`, `CLAUDE.md` (top section).

- **What is Merlin** — One-paragraph positioning: open-source (FOSS) Laravel
  business-management app for small businesses; core feature is LLM-assisted
  supplier-invoice → GL posting. Expands over time (billing + purchasing today).
- **Key features** — Bullet the real feature set: invoice ingestion (PDF/DOCX/
  XLSX/CSV), LLM line-item extraction, confidence-based auto-posting, suppliers/
  clients, sales + recurring invoices, payment terms, chart of accounts,
  reports, roles & permissions, audit log, transactional + campaign email.
- **How invoice automation works** — Narrative summary of the 7-step pipeline
  (link to *The Invoice Pipeline*). Source: `README.md` "Invoice pipeline" +
  `app/Modules/Purchasing/Services/InvoiceProcessingService.php`.
- **Technology stack** — Reproduce the stack table. Source: `README.md` Stack
  table (Laravel 13, PHP 8.4, Livewire 3, Volt, Flux UI, Tailwind 3, Breeze,
  spatie permission/activitylog/medialibrary/settings, nettmail-laravel, Pest).
- **Who this documentation is for** — Audience split: end users (operating the
  app) vs. self-hosters/developers (install + configure). Set expectations that
  this is self-hosted FOSS.

### installation.html — Installation
Source: `README.md` "Local setup", `composer.json` scripts (`setup`, `dev`, `test`).

- **Requirements** — PHP 8.4, Composer, Node + npm, a database (MariaDB
  preferred per CLAUDE.md; MySQL works), the `magika` CLI binary (for file-type
  detection), an Anthropic API key. NEEDS CONFIRMATION: minimum Node version.
- **Quick install** — `git clone … && cd merlin && composer run setup`. Explain
  what `setup` does (install, copy `.env`, `key:generate`, migrate, npm install
  + build). Source: `composer.json` `scripts.setup`.
- **Manual installation** — Intro line before the sub-steps.
  - **Clone and install dependencies** — `composer install`, `npm install`.
  - **Environment file** — `cp .env.example .env`, `php artisan key:generate`.
  - **Database** — set `DB_*`, then `php artisan migrate`. Note migrations have
    no `down()` methods (CLAUDE.md).
  - **Build front-end assets** — `npm run build`. Call out that a built Vite
    manifest is required or pages 500 (Vite manifest error → Troubleshooting).
- **Creating your first user** — `php artisan db:seed --class=DefaultAdminUserSeeder`.
  Source: `database/seeders/DefaultAdminUserSeeder.php`. NEEDS CONFIRMATION:
  default admin email/password the seeder sets (read the seeder; if it pulls
  from env, document the env keys — do **not** print real credentials).
- **Running the development server** — `composer run dev` (server + queue +
  pail + vite concurrently). Single test: `php artisan test --compact`.

### configuration.html — Configuration
Source: `README.md` env block; `config/documents.php`, `config/currency.php`,
`config/services.php`; memory `project_anthropic_model_pinning`.

- **Environment variables** — Intro: most config is env-driven; `config()`
  helper reads it. Then the sub-sections:
  - **Database** — `DB_CONNECTION=mysql` (MariaDB), `DB_DATABASE`, `DB_USERNAME`,
    `DB_PASSWORD`.
  - **Anthropic (Claude) API** — `ANTHROPIC_API_KEY`, `ANTHROPIC_MODEL`
    (=`claude-sonnet-4-6`), `ANTHROPIC_MODEL_FAST` (=`claude-haiku-4-5-20251001`),
    `ANTHROPIC_MODEL_BACKUP` (=`claude-opus-4-8`), `ANTHROPIC_ALERT_RECIPIENTS`,
    `ANTHROPIC_MODEL_DOWN_TTL` (=3600). Note: use model aliases, not dated
    snapshots, except where pinned. Source: `config/services.php`.
  - **Exchange rates** — `EXCHANGERATE_API_KEY` (exchangerate-api.com), 24h
    cache (`config/currency.php`, TTL 86400s).
  - **Invoice watch folder** — `INVOICE_WATCH_DIR` (default
    `storage/app/invoice-watch`), `INVOICE_WATCH_INTERVAL` (default 10s),
    `MAGIKA_BINARY` (default `magika`). Source: `config/documents.php` `watch`/`magika`.
  - **Queue** — `QUEUE_CONNECTION=sync` for local dev (no worker needed;
    CLAUDE.md queue convention). Explain `database` driver needs a running worker.
- **Configuration files** — Table of the project config files and what they
  hold: `config/documents.php` (watch folder, magika, document types/prefixes/
  statuses, tax exempt rate), `config/currency.php`, `config/party.php`
  (business/relationship/contact/address types), `config/paperdoc.php`,
  `config/nettmail.php`. Source: CLAUDE.md "Configuration Files" table.
- **Model health and fallback** — Two distinct mechanisms (do not conflate):
  (1) **quality fallback** Haiku→Sonnet on bad *output*; (2) **retirement
  ladder** `ModelHealthService` Haiku→Sonnet→Opus on a 404 `not_found_error`,
  circuit-breaks the down model for `down_ttl`, emails `ANTHROPIC_ALERT_RECIPIENTS`.
  `models:health-check` runs daily 05:30. Source: CLAUDE.md pipeline §3 +
  `app/Modules/Purchasing/Services/ModelHealthService.php`.

### seeding.html — Seeding Reference Data
Source: `database/seeders/*`, `README.md` seed block.

- **Why seed** — Reference data (roles, chart of accounts, payment terms, admin
  user) must exist before the app is usable.
- **Available seeders** — Intro listing the five seeders.
  - **Roles and permissions** — `RolesAndPermissionsSeeder` creates only the
    `Administrator` role; permissions themselves are created by **migrations**
    (CLAUDE.md: permissions belong in migrations, roles in the UI). Source:
    `database/seeders/RolesAndPermissionsSeeder.php`.
  - **Chart of accounts** — `ChartOfAccountsSeeder` seeds account types, groups,
    and a default GL (codes 1000–5xxx; Assets/Liabilities/Equity/Income/Expenses).
    Source: `database/seeders/ChartOfAccountsSeeder.php`.
  - **Default admin user** — `DefaultAdminUserSeeder`. NEEDS CONFIRMATION: the
    credentials/source (env vs hard-coded) — see Installation note.
  - **Debtor account group** — `DebtorAccountGroupSeeder` (accounts-receivable
    group used by billing). Source: `database/seeders/DebtorAccountGroupSeeder.php`.
  - **Payment terms** — `PaymentTermSeeder` seeds default terms. Source:
    `database/seeders/PaymentTermSeeder.php`.
- **Running all seeders** — The five `php artisan db:seed --class=…` commands
  (reproduce from README). Mention `DatabaseSeeder` if it chains them — NEEDS
  CONFIRMATION whether `db:seed` alone runs all five.
- **Re-running seeders safely** — Seeders use `firstOrCreate`, so re-running is
  idempotent (verify per seeder). Warn: never wipe production data.

---

## Core Concepts

### architecture.html — Architecture
Source: `CLAUDE.md` "Architecture", `app/Modules/` tree, `README.md` Architecture.

- **Module structure** — `app/Modules/{Core,Accounting,Purchasing,Billing}` with
  `Models/Services/Settings/...`. Business logic lives in modules, not in app root.
- **Domains at a glance** — One line per module: Core (User, Party, Person,
  Business, Address, ContactAssignment, PartyRelationship), Accounting (Account,
  AccountGroup, AccountType), Purchasing (Document, DocumentLine, LlmLog,
  PostingRule + pipeline), Billing (PaymentTerm, RecurringInvoice + services).
- **UUID primary keys** — All models use UUIDs via `App\Traits\HasUuid`. Source:
  CLAUDE.md dev conventions.
- **The morph map** — 18 aliases via `Relation::enforceMorphMap()` in
  `AppServiceProvider`; always register before writing morph data. Source:
  `app/Providers/AppServiceProvider.php`.
- **Volt pages and the CRUD framework** — Pages are Volt single-file components
  in `resources/views/livewire/pages/`; CRUD pages use `HasCrudTable` +
  `HasCrudForm` concerns (`app/Livewire/Concerns/`). PurchaseInvoice is always
  fully custom (never CRUD framework). Source: CLAUDE.md "CRUD Framework".
- **Services and the pipeline** — Domain services orchestrate logic;
  `InvoiceProcessingService` and `DocumentService` are the key Purchasing ones.

### parties.html — Parties & Contacts
Source: `app/Modules/Core/Models/{Party,Person,Business,PartyRelationship,
ContactAssignment,Address}.php`, `app/Modules/Core/Services/PartyService.php`,
`config/party.php`.

- **The Party model** — Parent in a Class Table Inheritance scheme; holds the
  shared primary key. Use `$party->displayName`.
- **Persons versus Businesses** — `Person` and `Business` share the Party PK;
  access via `$party->person` / `$party->business`. Business types from
  `config/party.php`.
- **Party relationships** — A Party becomes a *supplier* or *client* through a
  `PartyRelationship` (relationship_type). Source: `PartyRelationship` model.
  - **Relationship metadata** — Per-type fields live in the `metadata` JSON
    column, never typed columns. Supplier: `default_payable_account_id`,
    `payment_term_id`. Client: `default_receivable_account_id`, `payment_term_id`.
    Read via accessor props; **write only via `mergeMetadata([...])`**. Source:
    CLAUDE.md "party_relationships.metadata Convention".
- **Contact assignments** — People attached to a party as contacts
  (`ContactAssignment`); roles from `config/party.php`.
- **Addresses** — `Address` model, polymorphic; address types from `config/party.php`.

### roles-permissions.html — Roles & Permissions
Source: `database/migrations/*permission*`, `app/Policies/`,
`app/Modules/Core/Policies/AllModulesPolicy.php`. **Hard rule:** docs must say
authorization is always `$user->can('permission')`, never `hasRole()`.

- **How authorization works** — Spatie permission package; 16 policies all
  extend `AllModulesPolicy`. Gates resolve through permissions.
- **The Administrator role** — The super-admin role is named **Administrator**
  (not "Super Admin"); seeded by `RolesAndPermissionsSeeder`. Roles are
  user-configurable in the UI; permissions are the stable code contract.
- **Permission naming** — Two shapes: model permissions
  `{resource}-{view-any|view|create|update|delete|restore|force-delete}` and
  workflow permissions `can-…`. Source: permission migrations.
- **Model permissions** — Table of resource → CRUD permissions. Resources:
  accounts, account-groups, account-types, addresses, businesses,
  contact-assignments, documents, document-activities, document-lines,
  document-relationships, llm-logs, parties, party-relationships, persons,
  users, posting-rules, payment-terms, recurring-invoices. Source:
  `2026_03_11_100002_add_model_permissions.php` + later permission migrations.
- **Workflow permissions** — List with one-line meaning each:
  `access-panel`, `view-llm-summary`, `can-review-invoices`,
  `can-authorise-invoices`, `can-post-invoices`, `can-reprocess-invoices`,
  `can-process-invoice-payments`, `can-record-payments`,
  `can-send-sales-invoices`, `can-void-sales-invoices`, plus the `accountant`
  permission. Source: the dated `*permission*` migrations. NEEDS CONFIRMATION:
  exact intent of `accountant` (looks like a grouping permission, not a role).
- **Managing roles in the UI** — `/roles` page (`roles/index.blade.php`):
  create roles, attach permissions. Requires appropriate permission.
- **Assigning permissions to users** — Done via roles on `/users`
  (`users/index.blade.php`). Cross-link to *Users*.

### settings.html — Settings
Source: `app/Modules/*/Settings/*.php` (spatie/laravel-settings), settings pages
in `resources/views/livewire/pages/settings/`.

- **How settings are stored** — spatie/laravel-settings; each settings class is
  a typed PHP object persisted to the settings store, edited via Livewire forms.
- **Settings groups** — Five classes: `CompanySettings`, `CurrencySettings`
  (Core), `AccountingSettings`, `PurchasingSettings`, `BillingSettings`.
- **Editing settings** — Three UI pages: `/settings/general`,
  `/settings/purchasing`, `/settings/billing`. Cross-link to each admin page.
- **Settings reference** — Master table of every setting key, type, default, and
  which page edits it. Pull defaults from each Settings class (see
  General/Purchasing/Billing Settings pages for the per-field values).

---

## Expenses

### suppliers.html — Suppliers
Source: `resources/views/livewire/pages/suppliers/index.blade.php` (+ `show.blade.php`),
`PartyService`, `PartyRelationship` metadata.

- **What is a supplier** — A Party with a `supplier` relationship; created
  manually or auto-created (pending) by the pipeline.
- **Creating a supplier** — Walk the create flow on `/suppliers`.
- **Supplier fields** — From the `#[Validate]` attributes in `suppliers/index.blade.php`:
  - `legalName` — required, string, max 255
  - `tradingName` — nullable, string, max 255
  - `email` — nullable, email, max 255
  - `phone` — nullable, string, max 50
  - `notes` — nullable, string, max 1000
  - `status` — required, one of `active`, `pending`, `inactive`
  - `defaultPayableAccountId` — nullable, uuid, exists in `accounts`
  - `paymentTermId` — nullable, uuid, exists in `payment_terms`
- **Default payable account and payment term** — Stored on the supplier
  `PartyRelationship.metadata` (`default_payable_account_id`, `payment_term_id`);
  used by the pipeline to pre-fill posting. Cross-link *Parties*.
- **Pending suppliers from the pipeline** — `SupplierResolver` creates suppliers
  with `pending` status when an invoice names an unknown supplier; explain the
  approve flow. Source: `app/Modules/Purchasing/Services/SupplierResolver.php`,
  `PartyService` approve method.
- **The supplier detail page** — `/suppliers/{id}` (`show.blade.php`): read-only
  detail (contacts, addresses, recent documents). NEEDS CONFIRMATION: exact
  panels shown.
- **Permissions** — `parties-*`, `party-relationships-*`, `businesses-*`,
  `persons-*` (a supplier touches all). State the minimum to create/edit.

### purchase-invoices.html — Purchase Invoices
Source: `resources/views/livewire/pages/purchase-invoices/index.blade.php`
(fully custom), `_status-badge.blade.php`, `DocumentService`,
`InvoiceProcessingService`.

- **Overview** — The main purchasing workspace: upload, auto-extract, review,
  post. PurchaseInvoice UI is fully custom (not CRUD framework).
- **Uploading invoices** — Intro.
  - **Manual upload** — File picker; multi-file supported (recent commit
    `5c4413b`). Accepted: PDF, DOCX, XLSX, CSV. Files stored via Spatie
    MediaLibrary on the `source_document` collection of `Document`.
  - **The watch folder** — `php artisan invoices:watch` polls `INVOICE_WATCH_DIR`
    every `INVOICE_WATCH_INTERVAL`s; drop files to auto-ingest.
- **The invoice list** — Columns, status badges (`_status-badge.blade.php`),
  search/sort/filter. Always eager-loads `media` (N+1 avoidance, CLAUDE.md).
- **Reviewing an invoice** — The review panel for a single document.
  - **Editing line items** — Inline `DocumentLine` editing: description,
    quantity, unit_price, tax_rate, line_total, account allocation.
  - **Allocating GL accounts** — Each line gets a suggested account +
    confidence pill; user can override. Source: `AccountResolver`.
- **Status actions** — Buttons map to `DocumentService` transitions (see
  *Document Lifecycle*): review, approve, post, dispute, reject.
- **Reprocessing** — `DocumentService::reprocess()` deletes existing lines then
  re-runs the pipeline (process() only appends). Source: CLAUDE.md key rules +
  memory `project_invoice_reprocess`.
- **Permissions** — `documents-*`, `document-lines-*`, plus workflow perms
  `can-review-invoices`, `can-authorise-invoices`, `can-post-invoices`,
  `can-reprocess-invoices`.

### invoice-pipeline.html — The Invoice Pipeline
Source: `app/Modules/Purchasing/Services/InvoiceProcessingService.php` and the
services it orchestrates; CLAUDE.md "Invoice Processing Pipeline"; memories
`project_llm_tiered_extraction`, `project_vat_authoritative_amounts`.

- **Pipeline overview** — 7 ordered steps; `InvoiceProcessingService::process(Document)`
  orchestrates. Include a step diagram/list.
- **Text extraction** — `DocumentTextExtractor`: PDF via `PdfExtractor`,
  DOCX/XLSX/CSV via `paperdoc-dev/paperdoc-lib`.
- **File-type detection (Magika)** — `MagikaService` shells the Rust `magika`
  CLI to detect actual type; unsupported formats rejected
  (`InvalidFileTypeException`).
- **LLM extraction** — `LlmService` calls Claude with the prompt
  `resources/views/prompts/invoice-extraction.blade.php`; returns
  `ExtractedInvoice` DTO; logs tokens to `LlmLog`.
  - **Tiered model fallback** — Tries `ANTHROPIC_MODEL_FAST` (Haiku) first; full
    retry on `ANTHROPIC_MODEL` (Sonnet) if: invalid JSON, lines don't reconstruct
    `total`, or confidence < `PurchasingSettings::fallback_confidence` (0.80).
  - **Reconciliation** — Checked against `total` only (not header subtotal/tax),
    grossing each line by its effective tax rate. A reconciling fast result is
    kept over a non-reconciling strong one. Explain *why* total-only (VAT-inclusive
    invoices + shipping break header arithmetic).
  - **Model health and retirement** — Distinct from quality fallback:
    `ModelHealthService` ladder Haiku→Sonnet→Opus on 404; circuit-breaker +
    alert email; `models:health-check` daily probe. Cross-link *Configuration*.
- **Supplier resolution** — `SupplierResolver`: tax-number match → name
  similarity → create pending supplier via `PartyService`.
- **Account resolution** — `AccountResolver`: history match → LLM suggestion →
  manual allocation fallback.
- **Exchange rates** — `ExchangeRateService`: ExchangeRate-API, 24h cache;
  `EXCHANGERATE_API_KEY`.
- **Posting rule evaluation** — `PostingRuleService` evaluates active rules;
  auto-posts when confidence/pattern thresholds met. Cross-link *Posting Rules*.
- **VAT handling** — Amounts stored ex-VAT but the printed invoice amount is
  authoritative; VAT-inclusive lines back-calculate ex-VAT base and derive VAT
  by subtraction (gross − net) via `DocumentLine::$taxAmountOverride` to avoid
  cent drift. Source: CLAUDE.md VAT rule + memory `project_vat_authoritative_amounts`.

### document-lifecycle.html — Document Lifecycle
Source: `app/Modules/Purchasing/Services/DocumentService.php`, CLAUDE.md
"Document State Machine", `config/documents.php` (statuses).

- **Statuses** — `received`, `reviewed`, `approved`, `posted`, `disputed`,
  `rejected`. `posted`/`rejected` are terminal.
- **The state machine** — Managed by `DocumentService::transition()`; show the
  full transition map from CLAUDE.md.
- **Transitions** — Intro; then one sub-section each:
  - **Mark as reviewed** — `markAsReviewed()`; from `received`.
  - **Approve** — `approve()`; from received/reviewed/disputed.
  - **Post** — `post()`; from received/reviewed/approved/disputed → terminal.
  - **Dispute** — `dispute()`; from received/reviewed/approved.
  - **Reject** — `reject()`; from received/disputed → terminal.
- **Document activity log** — `DocumentActivity` + spatie/activitylog record
  each transition (causer, event). Source: `DocumentActivity` model.
- **Permissions** — Map each action to its workflow permission
  (`can-review-invoices`, `can-authorise-invoices`, `can-post-invoices`).
  NEEDS CONFIRMATION: which permission governs dispute/reject.

### posting-rules.html — Posting Rules
Source: `app/Modules/Purchasing/Models/PostingRule.php`,
`app/Modules/Purchasing/Services/PostingRuleService.php`,
`resources/views/livewire/pages/posting-rules/index.blade.php`.

- **What posting rules do** — Per-supplier (or global) rules that auto-allocate
  accounts and auto-post matching invoices. Fields: `party_id`, `name`,
  `description`, `conditions` (array), `actions` (array), `is_active`,
  `last_matched_at`, `match_count`.
- **Conditions** — Shape of the `conditions` JSON (match criteria). NEEDS
  CONFIRMATION: exact condition keys/operators — read `PostingRuleService`.
- **Actions** — Shape of the `actions` JSON (e.g. set account, set tax). NEEDS
  CONFIRMATION: exact action keys.
- **Auto-posting and confidence** — Interaction with
  `PurchasingSettings::autopost_confidence` (0.90); only auto-posts above
  threshold. `match_count`/`last_matched_at` track usage.
- **Managing posting rules** — CRUD on `/posting-rules`.
- **Permissions** — `posting-rules-view-any|view|create|update|delete|restore|force-delete`.

### llm-logs.html — LLM Logs
Source: `app/Modules/Purchasing/Models/LlmLog.php`,
`resources/views/livewire/pages/llm-logs/index.blade.php`.

- **What is logged** — One row per extraction attempt: model used, tokens,
  parsed confidence, warnings, supplier match method, linked document. Source:
  `LlmLog` fillable/casts.
- **The log list** — Read-only `/llm-logs` table; columns + filters.
- **Reading a log entry** — How to interpret confidence, token counts, which
  model tier answered (Haiku/Sonnet/Opus), warnings.
- **Permissions** — `llm-logs-view-any`, `llm-logs-view`; summary gated by
  `view-llm-summary`.

---

## Billing

### clients.html — Clients
Source: `resources/views/livewire/pages/clients/index.blade.php`.

- **What is a client** — A Party with a `client` relationship; the billing-side
  counterpart of a supplier.
- **Creating a client** — Create flow on `/clients`.
- **Client fields** — From `#[Validate]` in `clients/index.blade.php`:
  `legalName` (required, max 255), `tradingName`, `email` (email), `phone`,
  `notes`, `status` (active/pending/inactive), plus billing address fields
  (line, city, etc.) and `paymentTermId` (uuid, exists payment_terms).
- **Contacts** — Inline contact creation: `newContactFirstName` (required, max
  100), `newContactLastName`, `newContactEmail` (email), `newContactPhone`.
  Source: the `rules()` method in `clients/index.blade.php`.
- **Default receivable account and payment term** — Stored on the client
  `PartyRelationship.metadata` (`default_receivable_account_id`, `payment_term_id`).
- **Permissions** — `parties-*`, `party-relationships-*`, `persons-*`.

### sales-invoices.html — Sales Invoices
Source: `resources/views/livewire/pages/sales-invoices/index.blade.php`,
`app/Modules/Billing/Services/BillingService.php`, `Document` (type
`sales_invoice`, prefix `SINV`), `SalesInvoiceMail`,
`InvoiceEmailTemplateService`.

- **Overview** — Outbound invoices to clients; `Document` with
  `document_type=sales_invoice`, number `SINV-YEAR-NNNNN` via `HasDocumentNumber`.
- **Creating a sales invoice** — Create flow; selecting client pre-fills
  receivable account + payment term from client metadata.
- **Invoice fields and line items** — Document fields (`issue_date`, `due_date`,
  `currency`, lines with description/qty/unit_price/tax_rate). NEEDS
  CONFIRMATION: exact validation rules on the sales-invoice form.
- **Tax and totals** — `subtotal`, `tax_total`, `total`, `balance_due`,
  `amount_paid`; VAT label/rate from `PurchasingSettings`/document config.
- **Sending an invoice** — Emails the rendered Unlayer template selected by
  `BillingSettings::invoice_email_template_id` via `SalesInvoiceMail`. Gated by
  `can-send-sales-invoices`. Cross-link *Email*.
- **Recording payments** — Record payments against an invoice (updates
  `amount_paid`/`balance_due`). Gated by `can-record-payments` /
  `can-process-invoice-payments`. NEEDS CONFIRMATION: distinction between these
  two permissions.
- **Voiding an invoice** — Gated by `can-void-sales-invoices`. NEEDS
  CONFIRMATION: void semantics (status used).
- **PDF generation** — `BillingService` generates the invoice PDF. NEEDS
  CONFIRMATION: PDF engine used.
- **Permissions** — `documents-*` + `can-send-sales-invoices`,
  `can-void-sales-invoices`, `can-record-payments`, `can-process-invoice-payments`.

### recurring-invoices.html — Recurring Invoices
Source: `app/Modules/Billing/Models/RecurringInvoice.php`,
`app/Modules/Billing/Services/RecurringInvoiceService.php`,
`resources/views/livewire/pages/recurring-invoices/index.blade.php`.

- **Overview** — Templates that auto-generate sales invoices on a schedule.
  Status enum (`RecurringInvoiceStatus`): `active`, `paused`, `completed`.
- **Creating a recurring invoice** — Fields (from `RecurringInvoice` fillable):
  `client_id`, `payment_term_id`, `receivable_account_id`, `contact_ids`,
  `frequency`, `billing_period_day`, `start_date`, `end_date`,
  `next_invoice_date`, `currency`, `auto_send`, `notes`, `terms`, `footer`.
- **Frequency and billing day** — `RecurringFrequency`: `monthly`, `quarterly`,
  `annually`; `billing_period_day` sets the day generated.
- **Auto-send** — `auto_send` flag emails the generated invoice automatically.
- **How invoices are generated** — `RecurringInvoiceService` creates a `Document`
  on/after `next_invoice_date` and advances the schedule. NEEDS CONFIRMATION:
  the scheduler/command that triggers generation (cron entry).
- **Permissions** — `recurring-invoices-view-any|view|create|update|delete|restore|force-delete`.

### payment-terms.html — Payment Terms
Source: `app/Modules/Billing/Models/PaymentTerm.php`,
`app/Modules/Billing/Enums/PaymentTermRule.php`,
`app/Modules/Billing/Services/DueDateCalculator.php`, `PaymentTermSeeder`.

- **What payment terms do** — Reusable due-date rules attached to suppliers/
  clients/invoices.
- **Term rules** — `PaymentTermRule` enum:
  - `days_after_invoice` — N days after invoice date
  - `nth_of_following_month` — the Nth of the following month
  - `first_business_day_of_following_month`
  - `same_as_invoice_date`
  - `beginning_of_next_billing_period`
  - `n_working_days_before_month_end`
- **Fields** — `name`, `rule` (enum), `days` (int), `day_of_month` (int).
- **Due-date calculation** — `DueDateCalculator` resolves a due date from the
  rule + invoice date (+ billing period for the billing-period rule).
- **Managing payment terms** — CRUD on `/payment-terms`; defaults seeded by
  `PaymentTermSeeder`.
- **Permissions** — `payment-terms-view-any|view|create|update|delete|restore|force-delete`.

---

## Accounting

### chart-of-accounts.html — Chart of Accounts
Source: `app/Modules/Accounting/Models/{Account,AccountGroup,AccountType}.php`,
`resources/views/livewire/pages/accounts/index.blade.php`, `ChartOfAccountsSeeder`.

- **Account structure** — Accounts belong to an `AccountGroup` (which has an
  `AccountType`); accounts can nest via `parent_id`.
- **Account fields** — From `#[Validate]` in `accounts/index.blade.php` +
  `Account` fillable: `account_group_id` (required, exists), `parent_id`
  (nullable, exists accounts), `code` (required, max 20), `name` (required, max
  255), `description` (max 1000), `is_active` (bool), `allow_direct_posting`
  (bool), `is_system`, `sort_order`, `metadata`.
- **Direct posting** — `allow_direct_posting` controls whether transactions can
  post directly to the account (vs. parent/summary accounts).
- **System accounts** — `is_system` accounts are protected (cannot be deleted).
  NEEDS CONFIRMATION: exact UI restrictions on system accounts.
- **The seeded chart** — Default GL from `ChartOfAccountsSeeder`: codes 1000s
  (Current Assets, Bank, Accounts Receivable), 2000s (Liabilities, Accounts
  Payable code `2000`, GST/VAT), 3000s (Equity), 4000s (Income/Revenue), 5000s
  (Expenses: Cloud Hosting, Communication, Professional Services, etc.). Use as
  illustrative only.
- **Permissions** — `accounts-view-any|view|create|update|delete|restore|force-delete`,
  `account-types-*`.

### account-groups.html — Account Groups
Source: `app/Modules/Accounting/Models/{AccountGroup,AccountType}.php`,
`resources/views/livewire/pages/account-groups/index.blade.php`, `ChartOfAccountsSeeder`.

- **What account groups are** — Containers grouping accounts under a type
  (e.g. *Current Assets*, *Current Liabilities*).
- **Account types** — The base classifications: Asset, Liability, Equity,
  Income/Revenue, Expense (+ normal balance debit/credit). Source: seeder
  AccountType rows.
- **Fields** — NEEDS CONFIRMATION: read `account-groups/index.blade.php` for the
  exact `#[Validate]` field set (likely name, account_type_id, code, sort_order).
- **Managing account groups** — CRUD on `/account-groups`.
- **Permissions** — `account-groups-view-any|view|create|update|delete|restore|force-delete`.

---

## Reports

### reports.html — Reports
Source: `resources/views/livewire/pages/reports/*.blade.php` (`_subnav.blade.php`,
three report pages), `FinancialYearService`.

- **Expenses by Account** — `/reports/expenses-by-account`: posted purchase
  spend grouped by GL account over a period. NEEDS CONFIRMATION: exact grouping
  + filters from the blade.
- **Expenses by Supplier** — `/reports/expenses-by-supplier`: posted spend
  grouped by supplier.
- **LLM Performance** — `/reports/llm-performance`: extraction metrics from
  `LlmLog` (token usage, confidence distribution, model-tier hit rate,
  fallback frequency). Cross-link *LLM Logs*.
- **Date ranges and the financial year** — `FinancialYearService` derives
  fiscal-year bounds from `AccountingSettings::financial_year_start_month`;
  reports default to the current financial year. NEEDS CONFIRMATION: default
  date range per report.
- **Permissions** — NEEDS CONFIRMATION: report-view gating (likely
  `documents-view-any` / `view-llm-summary` for the LLM report).

---

## Administration

### users.html — Users
Source: `resources/views/livewire/pages/users/index.blade.php`,
`app/Modules/Core/Models/User.php`, `UserPolicy`, `access-panel` permission.

- **Managing users** — CRUD on `/users`.
- **User fields** — NEEDS CONFIRMATION: exact `#[Validate]` rules — read the
  blade (expect name, email unique, password, role).
- **Assigning roles** — Attach Spatie roles to a user; permissions flow from
  roles. Cross-link *Roles & Permissions*.
- **Panel access** — `access-panel` permission gates app entry; users without it
  cannot reach the admin UI.
- **Permissions** — `users-view-any|view|create|update|delete`.

### general-settings.html — General Settings
Source: `app/Modules/Core/Settings/{CompanySettings,CurrencySettings}.php`,
`app/Modules/Accounting/Settings/AccountingSettings.php`,
`resources/views/livewire/pages/settings/general.blade.php`.

- **Company details** — `CompanySettings`: `name`, `address_line_1`,
  `address_line_2`, `city`, `state_province`, `postal_code`, `country`, `phone`,
  `email`, `tax_number`, `logo_path`. Appears on invoices.
- **Currency and locale** — `CurrencySettings`: `base_currency` (default `ZAR`),
  `locale` (default `en_ZA`). Drives money formatting (tabular figures).
- **Financial year** — `AccountingSettings::financial_year_start_month`; drives
  reports + `FinancialYearService`. NEEDS CONFIRMATION: default value (no
  default in the class — likely seeded/migrated).
- **Permissions** — NEEDS CONFIRMATION: which permission gates `/settings/general`.

### purchasing-settings.html — Purchasing Settings
Source: `app/Modules/Purchasing/Settings/PurchasingSettings.php`,
`resources/views/livewire/pages/settings/purchasing.blade.php`.

- **Tax settings** — `tax_default_rate` (15.00), `tax_label` (`VAT`).
- **Default payable account** — `default_payable_account` (`2000`); the GL code
  invoices post to by default.
- **Confidence thresholds** — `autopost_confidence` (0.90 — auto-post above this),
  `fallback_confidence` (0.80 — below this, retry on the stronger model).
  Cross-link *The Invoice Pipeline* + README confidence table (≥85% auto, 60–84%
  review, <60% flagged) — NEEDS CONFIRMATION: reconcile README's 85/60 bands
  with the 0.90/0.80 setting defaults (they differ; confirm which is canonical).
- **Matching tolerances** — `amount_tolerance` (10.0), `description_similarity`
  (60.0) — used by supplier/account history matching.
- **Settings reference** — Table of all seven keys + types + defaults.

### billing-settings.html — Billing Settings
Source: `app/Modules/Billing/Settings/BillingSettings.php`,
`resources/views/livewire/pages/settings/billing.blade.php`.

- **Default accounts** — `default_receivable_account_id`,
  `default_bank_account_id`, `tax_liability_account_id` (all nullable uuid).
- **Billing period day** — `billing_period_day` (default 1) — default day for
  recurring billing periods.
- **Default payment term** — `default_payment_term_id`.
- **Invoice email template** — `invoice_email_template_id` selects the NettMail
  Unlayer template used for sales-invoice emails. Cross-link *Email*.
- **Settings reference** — Table of all six keys + types + defaults.

### email.html — Email (NettMail)
Source: `nettsite/nettmail-laravel` package, `config/nettmail.php`,
`app/Modules/Billing/Services/InvoiceEmailTemplateService.php`, `SalesInvoiceMail`,
CLAUDE.md "NettMail Integration".

- **The NettMail integration** — Composer package providing transactional +
  campaign email; pinned version (bump deliberately). Admin pages live under the
  "Emails" nav group.
- **Transactional versus campaign email** — Transactional (invoice emails) vs
  campaigns (templates, contacts, lists, segments, campaigns).
- **Invoice email templates** — Authored as Unlayer templates, not Blade;
  `InvoiceEmailTemplateService` renders the template chosen via
  `BillingSettings::invoice_email_template_id` with merge tags;
  `SalesInvoiceMail` consumes the rendered HTML.
- **Configuring mail delivery** — `NETTMAIL_*` driver/SMTP/provider settings in
  `config/nettmail.php`; `nettmail.layout` set to `layout.app` so admin pages
  render inside Merlin's layout. NEEDS CONFIRMATION: full `NETTMAIL_*` env key
  list — read `config/nettmail.php`.
- **The Emails admin section** — Pages: Templates, Contacts, Lists, Segments,
  Campaigns (package routes/nav group). NEEDS CONFIRMATION: exact route paths
  (owned by the package).

---

## Troubleshooting

### troubleshooting.html — Troubleshooting
Source: CLAUDE.md key rules + boost guidelines + memories.

- **Invoices not processing** — Check `magika` binary installed/`MAGIKA_BINARY`,
  watch command running (`invoices:watch`), queue connection, Anthropic key.
- **Vite manifest errors** — `Unable to locate file in Vite manifest` → run
  `npm run build` (or `npm run dev`). Source: boost Vite rule.
- **Queued jobs never run** — `QUEUE_CONNECTION=database` without a worker
  queues silently; use `sync` in dev. Source: CLAUDE.md queue convention.
- **Model not found (404 from Claude)** — A retired model id; the health ladder
  escalates Haiku→Sonnet→Opus and alerts. Update `ANTHROPIC_MODEL*` to current
  aliases. Source: memory `project_anthropic_model_pinning`.
- **Permission denied or missing menu items** — Missing permission/role; menu
  items hide when the user lacks the permission. Never relies on `hasRole()`.
  Re-check role permissions on `/roles`.
- **Foreign-currency rates not updating** — `EXCHANGERATE_API_KEY` missing/24h
  cache; explain cache TTL. Source: `config/currency.php`, `ExchangeRateService`.

### faq.html — Frequently Asked Questions
Source: cross-references to the above pages. Synthetic examples only.

- **General** — What is Merlin / is it free / self-hosting / SaaS mode (note:
  `config/documents.php` mentions a SaaS wrapper — NEEDS CONFIRMATION whether
  SaaS mode is in scope to document publicly).
- **Invoice processing** — Which file types, what an LLM costs, accuracy,
  human review.
- **Accounting** — Can I change the chart of accounts, VAT/GST handling,
  financial year.
- **Billing** — Recurring invoices, payment terms, emailing invoices.
- **Security and data** — Where data/files live (MediaLibrary `source_document`
  collection), audit log (spatie/activitylog), `.env`/`APP_KEY` safety,
  permissions model.

---

## Open items to confirm before the content pass ships

1. `DefaultAdminUserSeeder` credentials source (env vs hard-coded) — do not
   print real credentials.
2. README confidence bands (85/60) vs `PurchasingSettings` defaults (0.90/0.80)
   — which is canonical?
3. `accountant` permission intent.
4. Sales-invoice + account-group + user form validation rules (read the blades).
5. PostingRule `conditions`/`actions` JSON schema.
6. Recurring-invoice generation trigger (scheduler/command + cron).
7. Permission gating for settings pages and reports.
8. Distinction: `can-record-payments` vs `can-process-invoice-payments`.
9. Sales-invoice void semantics + PDF engine.
10. Whether SaaS mode is in scope for public docs.
11. Full `NETTMAIL_*` env key list and Emails route paths.
