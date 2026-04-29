<p align="center">
  <img src="public/logo.svg" alt="Merlin" width="196" height="56">
</p>

<br>

Merlin reads supplier invoices and posts them to your ledger — automatically. It uses an LLM pipeline to extract line items from PDFs, suggest GL account codes, and apply confidence-based auto-posting rules so that routine invoices never need human review.

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
| Testing | PHPUnit 12 |

## Local setup

```bash
git clone https://github.com/nettsite/merlin.git
cd merlin
composer run setup      # install, .env, key:generate, migrate, npm install + build
```

Copy `.env.example` to `.env` and fill in:

```env
DB_CONNECTION=mysql     # MariaDB preferred
DB_DATABASE=merlin
DB_USERNAME=...
DB_PASSWORD=...

OPENAI_API_KEY=...      # or whichever LLM provider the pipeline uses
```

## Development

```bash
composer run dev        # starts server + queue + pail + vite concurrently
composer run test       # clears config cache, then runs full PHPUnit suite
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
├── Core/          User, Party, Person, Business, Address
├── Accounting/    Account, AccountGroup, AccountType, FinancialYearService
├── Purchasing/    Document, DocumentLine, LlmLog, PostingRule + pipeline services
└── Billing/       (stub)
```

All models use UUID primary keys via `App\Traits\HasUuid`. Polymorphic relationships are registered in `AppServiceProvider` via `Relation::enforceMorphMap()`.

### Invoice pipeline

1. Supplier drops a PDF into a watched folder (or uploads manually)
2. The LLM extracts supplier, date, line items, and amounts
3. Each line gets a suggested GL account code with a confidence score
4. Lines above the auto-post threshold are posted immediately; lines below go into the review queue
5. Every decision is logged — confidence score, matched supplier history, rule applied

### Confidence thresholds

| Score | Treatment |
|---|---|
| ≥ 85 % | Auto-posted, listed in "Posted today" |
| 60 – 84 % | Queued for review |
| < 60 % | Queued, flagged as low confidence |

Thresholds are configurable per business via `PurchasingSettings`.

## Navigation

| Group | Pages |
|---|---|
| Expenses | Suppliers, Purchase Invoices, Posting Rules |
| Accounting | Accounts, Account Groups, Reports |
| Settings | General Settings, Purchasing Settings, Roles, Users, LLM Logs |

## Design

Visual decisions are documented in [`DESIGN.md`](DESIGN.md). The short version: warm amber accent (`#C8772E`), Inter font, flat surfaces, no gradients, confidence pills on every AI suggestion, tabular figures everywhere money appears.

## Key conventions

- **No `$user->hasRole()`** anywhere — always `$user->can('permission-name')`
- **`QUEUE_CONNECTION=sync`** in local `.env` — no worker needed in dev
- **Migrations have no `down()` methods**
- Auth routes are owned by Breeze — do not register conflicting routes
- `PurchaseInvoice` UI is always fully custom Livewire — never routed through shared CRUD helpers
