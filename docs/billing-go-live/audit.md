# Billing Go-Live — Audit Findings

Date: 2026-11-19. Read-only audit; nothing in code or data was changed.
Plan: `docs/billing-go-live/plan.md` (step 1).

## Blockers (must resolve before the live run)

### B1. Production DB is empty; real data lives in the dev DB
- `merlin.app` → `~/NettSite/merlin` uses DB `nettsite_merlin`: 0 clients, 0 templates, 0 sales invoices.
- `merlin.local` → working tree uses DB `merlin`: 138 clients, 27 active and 5 paused recurring templates, 1161 imported sales invoices.
- **Decision:** go live from which instance? Either import Ninja into production, or serve production from the `merlin` DB.

### B2. Production code is 67 commits behind main
- Deployed copy is at `d68dd6e`. Since then: the journal layer, the postings view, `document_balances`, manual journals and credit-note changes, among others.
- Deploying needs `migrate --force` on production and a post-deploy verification. Do it today, not tomorrow morning.

### B3. Merlin data is a stale Ninja snapshot (last import 2026-08-30)
- No sales invoice after 2026-08-25. Sep–Nov invoices raised in Ninja are missing.
- Template `next_invoice_date`s therefore lag. Monthly clients (CA Training, Ramson Cross, Medhold, Fitting & Handle, Target Pathology) show Sep/Aug dates although Ninja has billed them since.
- Running `billing:generate-recurring` now would generate **12** invoices, many of them for periods Ninja already billed (double billing).

### B4. Re-running `ninja:import --only=recurring` does not refresh templates
- `ImportFromNinja::importRecurring()` skips any template whose `[ninja_id:N]` sentinel exists (line ~729). It does not update `next_invoice_date`, lines, prices or status.
- Fix options: add an update/sync mode for recurring, or delete and re-import templates (destructive, needs your OK).

### B5. Stale or dead templates still active
- Syllwood Farms (next 2020-03-24, last invoice 2021-07), Election Solutions For Africa (next 2020-09-25), Mindful Media Publishing (next 2025-01-22).
- A run would issue an invoice dated **2020-03-24**. These should be paused or completed, or their state taken from a fresh Ninja sync.

## Accuracy risks (verify in the test run)

### A1. Recurring lines ignore Ninja discounts
- Recurring import sets no `discount_percent`, and ignores Ninja per-line and header discounts. The invoice import handles both, but the recurring import doesn't.
- Any discounted recurring client would be over-billed. Check this against Ninja for every template.

### A2. Payment terms and due dates are not imported for recurring
- `payment_term_id` is left null and falls back to the client's term, then to the default term. Ninja's per-recurring due-date settings are not mapped. Due dates may differ from what clients are used to.

### A3. Template totals vs last invoice
- Matches: CA Training 1150/1150, Ramson 450/450, Medhold 1250/1250, Fitting 3395/3395.
- Mismatches to explain: Target Pathology (template 5505 vs last 6880/3880), Christa Gumede Buthelezi (435 vs 555).

### A4. Only `tax_rate1` imported
- Ninja's `tax_rate2`/`tax_rate3` are dropped. The business is not VAT-registered (blank `tax_number`), so the impact is probably nil. Confirm no recurring line uses tax.

## Email safety

### E1. Invoices send through the `nettmail` mailer, not the default mailer
- `BillingService::sendInvoice()` calls `Mail::mailer('nettmail')`. **Setting `MAIL_MAILER=log` does NOT stop sends.**
- The NettMail driver is `smtp` on dev and `php` (sendmail) on production. Both deliver for real. NettMail has no log or sandbox driver.

### E2. Current safety net: `auto_send = false` on all 27 active templates
- `billing:generate-recurring` therefore creates **drafts only**: no PDF, no email, no status change. The run is safe to do as a test.
- Sends happen only when someone clicks Send in the UI.

### E3. Latent auto-send paths
- `routes/console.php` schedules `billing:generate-recurring` daily at 06:00. No cron or systemd timer runs `schedule:run` anywhere today (checked user crontab, `/etc/cron.d`, systemd timers), so it's inert. Adding a scheduler cron would auto-run it. Remove the schedule entry to keep Merlin manual-only, as you decided.
- `RecurringInvoiceService::createTemplate()` defaults `auto_send` to **true**. A new template with a mid-period start immediately generates **and emails** a pro-rata invoice. Take care when creating templates in the UI.
- Partial-send failure: if mail to recipient 1 succeeds and recipient 2 throws, the invoice stays draft and the error is only logged. A re-send would email recipient 1 again.

### E4. Sender identity
- From: "Merlin <merlin@nettsite.co.za>". Clients know you as NettSite. Consider `NETTMAIL_FROM_NAME`.

## Decisions (2026-11-19)

| Item | Decision | Status |
|---|---|---|
| B1 Instance | Brand-new production instance and DB (e.g. `merlin_nettsite`). Test on `merlin`. | Your infra step |
| B2 Deploy | Full new deploy of `main`. | Your infra step |
| B3/B4/B5 Data | Fresh `ninja:import` after `migrate:fresh` replaces the stale snapshot, so no template-refresh code is needed and dead templates come from Ninja's current state. | Your step (runbook) |
| A1 Discounts | None expected in Ninja. | No change |
| A2 Due dates | All due on the **1st of next month**. New "1st of Next Month" term (exact 1st, not next business day) seeded as the billing default. The import sets no term, so every recurring invoice uses it. | Done |
| A4 Tax | None. | No change |
| E1 Sandbox | `nettmail` mailer always-to `NETTMAIL_SANDBOX_ADDRESS` (cc/bcc dropped). **Defaults to william@nettsite.co.za whenever APP_ENV ≠ production.** | Done |
| E3 Schedule | `billing:generate-recurring` removed from the schedule. Manual trigger only, same as Ninja. | Done |
| E4 Sender | From name, from address and reply-to are set in **Settings → General → Outgoing Email** and applied to every client email at send time. A fresh install seeds them from `NETTMAIL_FROM_*` / `NETTMAIL_REPLY_TO_ADDRESS` (defaults: NettSite / support@nettsite.co.za). | Done |
| Numbering | Continue the R-series: next is `R6537` (max numeric R-number + 1). | Done |

Invoice PDF: `resources/views/pdf/sales-invoice.blade.php` now follows the InvoiceNinja layout (Invoice_R6551.pdf), without Ninja branding. Recurring generation copies a template's terms and footer onto the invoice and strips the import's `[ninja_id:N]` marker from notes, which would otherwise print.

Known gap (not a go-live blocker): editing an invoice's lines recalculates the invoice total without a document-level audit entry, so `documents:verify-audit-trail` flags manual drafts edited in the UI (seen on test drafts R6537/R6538).

Also fixed: `documents:verify-audit-trail` crashed on every run after the first, because it cached a Carbon object under `serializable_classes = false`. That included the daily 05:35 run.

## Runbook

### Today — test run on `merlin` (sandboxed)

1. Edit local `.env` so a fresh database seeds the right sender:
   ```dotenv
   NETTMAIL_FROM_ADDRESS=support@nettsite.co.za
   NETTMAIL_FROM_NAME=NettSite
   ```
   The sandbox is already on because `APP_ENV=local`. Confirm with `php artisan config:show mail.mailers.nettmail` that `to ⇁ address` shows william@nettsite.co.za.
   After `migrate:fresh`, fill in the settings that the invoice PDF prints:
   - **General:** company address (139 Clulee Road / Linbro Park, Gauteng 2090 / South Africa), **Website**, logo, Outgoing Email, and **Banking Details** (bank, account name, account number, branch code), which print as the EFT block on every invoice.
   - **Billing → Email → Invoice Email:** the NettMail template that is the whole invoice email (subject and body). A fresh database seeds and selects "Invoice Email"; restyle it under Emails → Templates. Tags: `{{first_name}}` (each recipient's own), `{{client_name}}`, `{{invoice_number}}`, `{{invoice_date}}`, `{{amount_due}}`, `{{due_date}}`, `{{company_name}}`, `{{invoice_url}}`, `{{reference}}`. Reminders still use the Email Templates tab text inside the "Reminder Email Layout".
   - **Billing → Invoice Document:** default terms ("All invoices are payable by due date."), printed on every invoice whose recurring template has none (22 of 27). The footer is optional extra text. A footer that repeats the configured account number (Ninja's "Our Bank Details" line) is skipped, so it doesn't print twice.
2. **You run** (Claude is blocked from this; it wipes `merlin`: today 1 manual journal, 2 users, settings, all imports):
   ```bash
   php artisan migrate:fresh --seed --drop-views
   ```
   `--drop-views` is required: plain `migrate:fresh` leaves the `postings` view behind, and the migration that creates it then fails with "Table 'postings' already exists".
3. Re-import Ninja (dry run first):
   ```bash
   php artisan ninja:import /path/to/ninja --dry-run
   php artisan ninja:import /path/to/ninja
   ```
4. Preview, then generate. Templates have `auto_send=false`, so this creates **drafts only**:
   ```bash
   php artisan billing:generate-recurring --dry-run
   php artisan billing:generate-recurring
   ```
5. In Sales Invoices, compare each draft against Ninja (client, lines, amount, number `R6537…`, due date the 1st of next month), then click **Send**. Every email lands at william@nettsite.co.za.

### Tomorrow — go live on the new instance

1. Deploy `main` and create the DB (e.g. `merlin_nettsite`). After every `git pull` run `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build` and `php artisan optimize:clear`: a leftover route cache from an earlier deploy hides new routes and breaks the navigation with "Route [...] not defined". Production runs on a box that is **not reachable from the internet**, so: set **Settings → General → Email Logo URL** to a public copy of the logo (e.g. on nettsite.co.za), which `{{company_logo}}` then uses; and leave `{{invoice_url}}` (client portal link) out of the invoice email, since clients can't open it. Set `.env`: `APP_ENV=production`, `NETTMAIL_DRIVER`, `NETTMAIL_FROM_ADDRESS`, `NETTMAIL_FROM_NAME`, `NETTMAIL_REPLY_TO_ADDRESS`.
2. Recommended: also set `NETTMAIL_SANDBOX_ADDRESS=william@nettsite.co.za` for a first smoke send on production.
3. `php artisan migrate --force && php artisan db:seed --force`, then `ninja:import` as above.
   Copy settings, the NettMail invoice email, billing email texts and the logo from the test instance, instead of re-entering them:
   ```bash
   # on the test instance (merlin)
   php artisan settings:export ~/merlin-settings.json
   # on production
   php artisan settings:import ~/merlin-settings.json --dry-run
   php artisan settings:import ~/merlin-settings.json
   rm ~/merlin-settings.json   # contains banking details
   ```
   The dry run should end "0 skipped". Account, payment-term and template references are matched by code/name, so run the import **after** seeding.
   Afterwards check Settings → Billing → Email → **Invoice Email** is set: a setting left blank on the source is exported as blank and overwrites what the seeder selected (this happened on the first production import, 2026-11-20).
4. `billing:generate-recurring`, review the drafts.
5. Go live: clear `NETTMAIL_SANDBOX_ADDRESS` (blank = real delivery in production), run `php artisan config:clear` (or `config:cache`), then Send.
6. Run `/post-deploy-verify`.
7. Daily database backup (cron entry on the production box). It runs at noon because the box sleeps overnight, which is before the 16:00 iDrive job:
   ```bash
   0 12 * * * cd /home/will/NettSite/merlin && /usr/bin/php artisan database:backup >> storage/logs/backup.log 2>&1
   ```
   `database:backup` writes three fixed files to `data-backups/` in the app root, rotating on each successful run: `merlin_nettsite.sql.gz` (newest) → `merlin_nettsite.sql.bak.gz` → `merlin_nettsite.sql.bbk.gz`. A failed dump leaves all three untouched. The dump holds the database only: uploaded files (logo, purchase-invoice source documents in `storage/app`) and `.env` need their own backup.
   Restore (try it once into a scratch database before you ever need it):
   ```bash
   gunzip -c data-backups/merlin_nettsite.sql.gz | mariadb merlin_restore_test
   ```

## OK / verified

- All 27 active templates resolve at least one invoice recipient (`receives_invoices` contacts, 162 flagged).
- The invoice email template exists (`BillingEmailTemplate::forInvoice()` → "Invoice").
- `documents:verify-audit-trail`: no unaudited changes.
- Billing settings populated: receivable account, default payment term, billing period day (25), contra account.
- Idempotency guard prevents double generation for the same template and period.

## Test suite

`composer run test`: **701 passed**, 0 failed (1790 assertions, ~89s). Green on main.
