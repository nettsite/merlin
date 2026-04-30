# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What Is This Project?

Laravel business management app for small businesses. Core feature: LLM-assisted supplier invoice processing (PDFs/DOCX/XLSX/CSV → GL transactions). Previous Filament-based version at `~/Projects/merlin` is **read-only spec** — do not copy Filament files from it.

**Stack:** Laravel 13, Livewire 3, Flux UI (livewire/flux), Volt (single-file components), Alpine.js, Tailwind CSS 3, PHPUnit 12, Laravel Breeze (Livewire stack) for auth.

## Commands

```bash
composer run dev          # starts server + queue + pail + vite concurrently
composer run test         # clears config cache, then runs full PHPUnit suite
php artisan test --compact --filter=TestName   # single test
vendor/bin/pint --dirty   # format changed PHP files

# Seeders
php artisan db:seed --class=RolesAndPermissionsSeeder
php artisan db:seed --class=ChartOfAccountsSeeder
php artisan db:seed --class=DefaultAdminUserSeeder
php artisan db:seed --class=DebtorAccountGroupSeeder
php artisan db:seed --class=PaymentTermSeeder

# Invoice watch folder (polls INVOICE_WATCH_DIR every INVOICE_WATCH_INTERVAL seconds)
php artisan invoices:watch
```

## Architecture

### Domain Structure (`app/Modules/`)

```
app/Modules/
├── Core/
│   ├── Models/     — User, Party, Person, Business, Address, ContactAssignment, PartyRelationship
│   ├── Services/   — PartyService (create/approve suppliers, persons, businesses)
│   ├── Settings/   — CurrencySettings (base_currency, locale)
│   └── Policies/   — AllModulesPolicy (base; all domain policies extend this)
├── Accounting/
│   ├── Models/     — Account, AccountGroup, AccountType
│   ├── Services/   — FinancialYearService (fiscal year bounds, month labels)
│   └── Settings/   — AccountingSettings (financial_year_start_month)
├── Purchasing/
│   ├── Models/     — Document, DocumentLine, DocumentActivity, DocumentRelationship, LlmLog, PostingRule
│   ├── Services/   — see "Invoice Processing Pipeline" below
│   ├── Jobs/       — ProcessInvoiceDocument (queued)
│   ├── DTO/        — ExtractedInvoice, ExtractedInvoiceLine, MagikaResult
│   └── Settings/   — PurchasingSettings (autopost_confidence, tax_default_rate, etc.)
└── Billing/        — in progress; Enums, PaymentTerm + RecurringInvoice models, seeders done (Phase 1)

app/Policies/       — 16 domain policies, all extend AllModulesPolicy
app/Traits/         — HasDocumentNumber (auto-generates PREFIX-YEAR-NNNNN on create)
app/Exceptions/     — InvalidDocumentStateException, InvalidFileTypeException, LlmApiException, PdfExtractionException
```

**Party model uses Class Table Inheritance:** `Party` is the parent; `Person` and `Business` share its primary key. Use `$party->person` / `$party->business` and `$party->displayName`.

### Volt Pages (`resources/views/livewire/pages/`)

All pages are Volt single-file components. CRUD pages use `HasCrudTable` + `HasCrudForm` concerns.

| Route | File | Notes |
|---|---|---|
| `/suppliers` | `suppliers/index.blade.php` | CRUD |
| `/purchase-invoices` | `purchase-invoices/index.blade.php` | **Fully custom** — file upload, LLM pipeline, inline line editing, status machine |
| `/posting-rules` | `posting-rules/index.blade.php` | CRUD |
| `/accounts` | `accounts/index.blade.php` | CRUD |
| `/account-groups` | `account-groups/index.blade.php` | CRUD |
| `/roles` | `roles/index.blade.php` | CRUD |
| `/users` | `users/index.blade.php` | CRUD |
| `/llm-logs` | `llm-logs/index.blade.php` | Read-only |
| `/reports/expenses-by-account` | `reports/expenses-by-account.blade.php` | Read-only |
| `/reports/expenses-by-supplier` | `reports/expenses-by-supplier.blade.php` | Read-only |
| `/reports/llm-performance` | `reports/llm-performance.blade.php` | Read-only |
| `/settings/general` | `settings/general.blade.php` | Spatie settings form |
| `/settings/purchasing` | `settings/purchasing.blade.php` | Spatie settings form |

### CRUD Framework

```
app/Livewire/Concerns/
├── HasCrudTable.php   — WithPagination, $search, $sortBy, $sortDir, $perPage; sort(), updatedSearch()
└── HasCrudForm.php    — $showForm, $editingId; create(), edit(), save(), delete(), cancelForm()
                         Abstract: fillForm(), store(), update(), performDelete()

resources/views/components/
├── layout/app.blade.php, nav.blade.php, top-nav.blade.php
└── crud/table.blade.php, th.blade.php, form.blade.php   ← flyout modal
```

Flux UI components (`<flux:input>`, `<flux:select>`, etc.) used directly — no custom field abstraction.

### Invoice Processing Pipeline

`InvoiceProcessingService::process(Document $doc)` orchestrates:

1. **DocumentTextExtractor** — multi-format text extraction (PDF via PdfExtractor, DOCX/XLSX/CSV via `paperdoc-dev/paperdoc-lib`)
2. **MagikaService** — Rust CLI (`magika`) detects actual file type; rejects unsupported formats
3. **LlmService** — calls Claude API with `resources/views/prompts/invoice-extraction.blade.php`; returns `ExtractedInvoice` DTO; logs tokens to `LlmLog`
4. **SupplierResolver** — tax number match → name similarity → create pending supplier via `PartyService`
5. **AccountResolver** — history match → LLM suggestion → manual allocation fallback
6. **ExchangeRateService** — fetches rate from ExchangeRate-API (24h cache); env `EXCHANGERATE_API_KEY`
7. **PostingRuleService** — evaluates active rules; auto-posts if confidence/pattern thresholds met

`DocumentService` owns all status transitions and wraps `InvoiceProcessingService::process()` for reprocessing. **Reprocessing must delete existing lines first** — `process()` only appends.

### Document State Machine

Managed by `DocumentService::transition()`. Valid transitions:

```
received → processing → processed → reviewed → approved → posted
         ↘ failed    → received   ↗           ↘ disputed → received
```

Methods: `markAsReviewed()`, `approve()`, `post()`, `dispute()`, `reject()`, `reprocess()` (deletes lines, re-runs pipeline).

### Configuration Files

| File | Key env vars / settings |
|---|---|
| `config/documents.php` | `INVOICE_WATCH_DIR`, `INVOICE_WATCH_INTERVAL`, `MAGIKA_BINARY`; document type prefixes & statuses |
| `config/currency.php` | `EXCHANGERATE_API_KEY`; cache TTL 86400s |
| `config/party.php` | business types, relationship types, contact roles, address types |
| `config/paperdoc.php` | paperdoc-lib settings |

### Morph Map (AppServiceProvider)

18 aliases registered via `Relation::enforceMorphMap()`. Always add new morph-related models here before writing data.

### `party_relationships.metadata` Convention

**Never add typed columns to `party_relationships` for relationship-type-specific data.** The `metadata` JSON column is the canonical store for all per-type fields. This keeps the table stable as new relationship types are introduced.

Current metadata shapes:

```json
// relationship_type = "supplier"
{ "default_payable_account_id": "uuid|null", "payment_term_id": "uuid|null" }

// relationship_type = "client"
{ "default_receivable_account_id": "uuid|null", "payment_term_id": "uuid|null" }
```

`PartyRelationship` exposes getter-only Eloquent Attribute accessors so callers read via `$rel->default_payable_account_id` etc. as normal properties. **For writing, always use `$rel->mergeMetadata([...])` — never assign to the accessor properties directly.** Eloquent `Attribute` setters that return `['metadata' => array]` bypass the 'array' cast encoder, leaving a raw PHP array in `$attributes` that breaks subsequent `json_decode` calls on the same request.

## Key Rules

- **Auditing:** `spatie/laravel-activitylog` — models use `LogsActivity` trait + `getActivitylogOptions()`. No `owen-it/laravel-auditing`. Import as `Spatie\Activitylog\Support\LogOptions` (not `Spatie\Activitylog\LogOptions` — that namespace does not exist).
- **Auth:** Laravel Breeze owns `/login`, `/register`, auth tests. Do not touch `tests/Feature/Auth/` or `tests/Feature/Settings/`.
- **No Filament.** Do not install or reference Filament. Read `~/Projects/merlin` as spec only.
- **PurchaseInvoice is always fully custom.** Never route it through `HasCrudForm`.
- **Reprocess = delete lines first.** `InvoiceProcessingService::process()` appends; `DocumentService::reprocess()` must delete existing lines before calling it.

---

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- laravel/framework (LARAVEL) - v13
- laravel/prompts (PROMPTS) - v0
- livewire/flux (FLUXUI_FREE) - v2
- livewire/livewire (LIVEWIRE) - v3
- livewire/volt (VOLT) - v1
- laravel/boost (BOOST) - v2
- laravel/breeze (BREEZE) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/telescope (TELESCOPE) - v5
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v3

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== volt/core rules ===

# Livewire Volt

- Single-file Livewire components: PHP logic and Blade templates in one file.
- Always check existing Volt components to determine functional vs class-based style.
- IMPORTANT: Always use `search-docs` tool for version-specific Volt documentation and updated code examples.
- IMPORTANT: Activate `volt-development` every time you're working with a Volt or single-file component-related task.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

=== spatie/laravel-activitylog rules ===

# spatie/laravel-activitylog

Activity logging package for Laravel. Logs model events and manual activities to a database table.

## Key Concepts

- **Activity**: An Eloquent model (`Spatie\Activitylog\Models\Activity`) storing log entries with subject, causer, event, attribute_changes, and properties.
- **Subject**: The model being acted upon (polymorphic `subject_type`/`subject_id`).
- **Causer**: The model that caused the action, typically the authenticated user (polymorphic `causer_type`/`causer_id`).
- **LogOptions**: Fluent configuration object returned by `getActivitylogOptions()` on models using the `LogsActivity` trait.
- **ActivityEvent**: Enum with cases `Created`, `Updated`, `Deleted`, `Restored`.
- **`attribute_changes`** column: stores `{"attributes": {...}, "old": {...}}` for tracked model changes.
- **`properties`** column: stores custom user data set via `withProperties()`.

## Traits

### `LogsActivity`

Add to models to automatically log create/update/delete events. Optionally implement `getActivitylogOptions()` to configure which attributes to track (defaults to logging events without attribute changes).

```php
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Article extends Model
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
```

### `CausesActivity`

Add to user/causer models. Provides `activitiesAsCauser()` relationship.

### `HasActivity`

Combines `LogsActivity` and `CausesActivity`. Provides `activities()`, `activitiesAsSubject()`, and `activitiesAsCauser()`.

## Manual Logging

```php
activity()
    ->performedOn($article)
    ->causedBy($user)
    ->event(ActivityEvent::Updated)
    ->withProperties(['key' => 'value'])
    ->log('Article was updated');
```

## LogOptions Methods

| Method | Description |
|--------|-------------|
| `logFillable()` | Log all fillable attributes |
| `logAll()` | Log all attributes |
| `logOnly(array)` | Log specific attributes |
| `logExcept(array)` | Exclude attributes |
| `logOnlyDirty()` | Only log changed attributes |
| `dontLogEmptyChanges()` | Skip logging when no tracked attributes changed |
| `dontLogIfAttributesChangedOnly(array)` | Ignore updates that only change these attributes |
| `useLogName(string)` | Set custom log name |
| `setDescriptionForEvent(Closure)` | Custom description per event |
| `useAttributeRawValues(array)` | Store raw (uncast) values |

## Querying Activities

```php
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Enums\ActivityEvent;

Activity::forEvent(ActivityEvent::Created)->get();
Activity::causedBy($user)->get();
Activity::forSubject($article)->get();
Activity::inLog('orders')->get();
```

## Setting the causer

Override the causer for a block of code:

```php
use Spatie\Activitylog\Facades\Activity;

Activity::defaultCauser($admin, function () {
    // all activities here are caused by $admin
});

// or set globally for the rest of the request
Activity::defaultCauser($admin);
```

## Disabling Logging

```php
activity()->withoutLogging(function () {
    // no activities logged here
});
```

## Accessing Changes and Properties

```php
$activity = Activity::latest()->first();

// Tracked model changes (set automatically by LogsActivity)
$activity->attribute_changes; // Collection: {"attributes": {...}, "old": {...}}

// Custom user data (set via withProperties)
$activity->properties; // Collection
$activity->getProperty('key'); // single value
```

## Custom Activity Model

Set `activity_model` in `config/activitylog.php` to a class that extends `Model` and implements `Spatie\Activitylog\Contracts\Activity`. Use a custom model for custom table names or database connections.

## Customizing Actions

The package uses action classes (`LogActivityAction`, `CleanActivityLogAction`) that can be extended and swapped via config:

```php
// config/activitylog.php
'actions' => [
    'log_activity' => \App\Actions\CustomLogActivityAction::class,
    'clean_log' => \App\Actions\CustomCleanAction::class,
],
```

Custom action classes must extend the originals. Override protected methods (`save()`, `beforeActivityLogged()`, `resolveDescription()`, etc.) to customize behavior.

## Configuration

Key config options in `config/activitylog.php`:
- `enabled`: Master on/off switch (env: `ACTIVITYLOG_ENABLED`)
- `clean_after_days`: Days to keep records for `activitylog:clean` command
- `default_log_name`: Default log name (string)
- `default_auth_driver`: Auth driver for causer resolution
- `include_soft_deleted_subjects`: Include soft-deleted subjects
- `activity_model`: Custom Activity model class
- `default_except_attributes`: Globally excluded attributes
- `actions.log_activity`: Action class for logging activities
- `actions.clean_log`: Action class for cleaning old activities

=== spatie/laravel-medialibrary rules ===

## Media Library

- `spatie/laravel-medialibrary` associates files with Eloquent models, with support for collections, conversions, and responsive images.
- Always activate the `medialibrary-development` skill when working with media uploads, conversions, collections, responsive images, or any code that uses the `HasMedia` interface or `InteractsWithMedia` trait.

</laravel-boost-guidelines>
