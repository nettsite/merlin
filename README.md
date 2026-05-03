<p align="center">
  <img src="public/logo.svg" alt="Merlin" width="196" height="56">
</p>

<br>

Merlin reads supplier invoices and posts them to your ledger — automatically. It uses an LLM pipeline to extract line items from PDFs, DOCX, XLSX, and CSV files, suggest GL account codes, and apply confidence-based auto-posting rules so that routine invoices never need human review.

## Stack

| Layer | Package |
|---|---|
| Framework | Laravel 13, PHP 8.4 |
| Frontend | Livewire 3, Volt, Flux UI, Alpine.js, Tailwind CSS 3 |
| Auth | Laravel Breeze (Livewire stack) |
| Roles & permissions | spatie/laravel-permission |
| Audit log | spatie/laravel-activitylog |
| File storage | spatie/laravel-medialibrary |
| Settings | spatie/laravel-settings |
| Testing | Pest + PHPUnit 12 |

## Local setup

```bash
git clone https://github.com/nettsite/merlin.git
cd merlin
composer run setup      # install, .env, key:generate, migrate, npm install + build
```

Copy `.env` and fill in:

```env
DB_CONNECTION=mysql     # MariaDB preferred
DB_DATABASE=merlin
DB_USERNAME=...
DB_PASSWORD=...

ANTHROPIC_API_KEY=...   # Claude API key for invoice extraction

EXCHANGERATE_API_KEY=...  # exchangerate-api.com — for foreign currency invoices
```

Seed reference data after migrating:

```bash
php artisan db:seed --class=RolesAndPermissionsSeeder
php artisan db:seed --class=ChartOfAccountsSeeder
php artisan db:seed --class=DefaultAdminUserSeeder
php artisan db:seed --class=PaymentTermSeeder
```

## Development

```bash
composer run dev        # starts server + queue + pail + vite concurrently
composer run test       # clears config cache, then runs full test suite
vendor/bin/pint --dirty # format changed PHP files
```

Run a single test:

```bash
php artisan test --compact --filter=TestName
```

## Architecture

Business logic lives under `app/Modules/`, grouped by domain:

```
app/Modules/
├── Core/        User, Party, Person, Business, Address, ContactAssignment
├── Accounting/  Account, AccountGroup, AccountType, FinancialYearService
├── Purchasing/  Document, DocumentLine, LlmLog, PostingRule + pipeline services
└── Billing/     PaymentTerm, RecurringInvoice + BillingService, DueDateCalculator
```

All models use UUID primary keys. Polymorphic relationships are registered in `AppServiceProvider` via `Relation::enforceMorphMap()` — always add new morph-mapped models there before writing data.

### Invoice pipeline

1. A PDF, DOCX, XLSX, or CSV is dropped into the watched folder or uploaded manually
2. Magika detects the actual file type; unsupported formats are rejected
3. Claude extracts supplier, dates, line items, and amounts — all amounts are stored ex-VAT
4. Each line gets a suggested GL account code with a confidence score drawn from posting history and the current chart of accounts
5. Posting rules evaluate the document; invoices above the confidence threshold are auto-posted
6. Every extraction is logged — tokens used, confidence score, warnings, supplier match method

### Confidence thresholds

| Score | Treatment |
|---|---|
| ≥ 85 % | Auto-posted |
| 60 – 84 % | Queued for review |
| < 60 % | Queued, flagged as low confidence |

Thresholds are configurable per business via `PurchasingSettings`.

## Navigation

| Group | Pages |
|---|---|
| Expenses | Suppliers, Purchase Invoices, Posting Rules |
| Billing | Clients, Sales Invoices, Recurring Invoices, Payment Terms |
| Accounting | Accounts, Account Groups |
| Reports | Expenses by Account, Expenses by Supplier, LLM Performance |
| Settings | General, Purchasing, Billing, Roles, Users, LLM Logs |

## Design

Visual decisions are documented in [`DESIGN.md`](DESIGN.md). The short version: warm amber accent (`#C8772E`), Inter font, flat surfaces, no gradients, confidence pills on every AI suggestion, tabular figures everywhere money appears.

## Key conventions

- **No `$user->hasRole()`** anywhere — always `$user->can('permission-name')`
- **`QUEUE_CONNECTION=sync`** in local `.env` — no worker needed in dev
- **Migrations have no `down()` methods**
- Auth routes are owned by Breeze — do not register conflicting routes
- `PurchaseInvoice` UI is always fully custom Livewire — never routed through shared CRUD helpers
- Party model uses class table inheritance — `Party` is the parent; `Person` and `Business` share its primary key
