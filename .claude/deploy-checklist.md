# Merlin — Production Deploy Checklist

This file is read by `/post-deploy-verify`. Add entries whenever a new class of production bug is discovered.
Each entry follows: **Symptom → Root cause → Detection probe → Fix**.

---

## Security

### CSP Headers
- **Symptom:** Browser blocks inline scripts/styles; Livewire or Alpine breaks silently.
- **Root cause:** `Content-Security-Policy` header missing or too restrictive after a new middleware or nginx/apache config change.
- **Probe:** `curl -sI <APP_URL>/ | grep -i content-security-policy`
- **Expected:** Header present; must include `script-src` and `style-src` with at least `'self'`. `'unsafe-inline'` acceptable for Livewire/Alpine compatibility, but log a warning.
- **Fix:** Check `app/Http/Middleware/SecurityHeaders.php` or server config.

### CORS allowed_origins
- **Symptom:** API or Livewire requests from the frontend domain fail with `CORS policy` errors.
- **Root cause:** `config/cors.php` → `allowed_origins` left as `['*']` or missing the production domain.
- **Probe:** `curl -sI -H "Origin: https://example.com" <APP_URL>/livewire/update | grep -i access-control`
- **Expected:** `Access-Control-Allow-Origin` matches the production domain (not `*`) — or is absent (same-origin apps don't need this header on same-domain requests).
- **Fix:** Set `CORS_ALLOWED_ORIGINS` env var or update `config/cors.php`.

---

## Mail

### Mail Driver Config
- **Symptom:** Emails silently discarded; no error thrown.
- **Root cause:** `MAIL_MAILER=log` or `MAIL_MAILER=array` left from development.
- **Probe:** SSH and run `php artisan config:show mail.default` (or read `.env`). Must NOT be `log` or `array` in production.
- **Expected:** `smtp`, `mailgun`, `ses`, `postmark`, or `resend`.
- **Fix:** Set `MAIL_MAILER` in production `.env`.

---

## Queue

### Queue Connection
- **Symptom:** Jobs (invoice processing, notifications) queue silently and never execute.
- **Root cause:** `QUEUE_CONNECTION=sync` is correct for local dev but jobs in production need a worker. If `database` driver is used, a queue worker must be running.
- **Probe (local):** `grep QUEUE_CONNECTION .env` — must be `sync` locally; warn if `database`/`redis` without a running worker.
- **Probe (production):** SSH → `ps aux | grep queue:work` or `systemctl status laravel-worker`.
- **Expected:** Worker process running if driver is not `sync`.
- **Fix:** Deploy queue worker via Supervisor or systemd.

---

## Database

### Morph Map Registration
- **Symptom:** `Spatie\Activitylog` entries reference old FQCN after namespace refactor; or `Relation::requireMorphMap` throws on unmapped models.
- **Root cause:** New model added without registering its morph alias in `AppServiceProvider::configureMorphMap()`.
- **Probe:** `php artisan tinker --execute 'echo json_encode(array_keys(Illuminate\Database\Eloquent\Relations\Relation::morphMap()));'`
- **Expected:** All domain models listed. Currently required: `account`, `account_group`, `account_type`, `business`, `document`, `document_activity`, `document_line`, `document_relationship`, `llm_log`, `party`, `party_relationship`, `person`, `posting_rule`, `user`.
- **Fix:** Add missing alias to `configureMorphMap()` in `AppServiceProvider`.

### Pending Migrations
- **Symptom:** App errors on missing columns/tables immediately after deploy.
- **Root cause:** Migrations not run after deploy.
- **Probe:** `php artisan migrate:status | grep Pending`
- **Expected:** No pending migrations.
- **Fix:** Run `php artisan migrate --force` (production flag required).

### Migration Idempotency
- **Symptom:** Re-running migrations on a fresh deploy fails because `IF NOT EXISTS` / `IF EXISTS` guards are missing.
- **Root cause:** Migration uses `$table->addColumn()` without checking if column exists.
- **Probe:** Run `php artisan migrate:fresh --seed` in a staging environment. Must complete without errors.
- **Expected:** Clean run with no exceptions.
- **Fix:** Add `Schema::hasColumn()` guards or use a separate `change()` migration.

### Generated Columns
- **Symptom:** MariaDB throws `Error: 1054 Unknown column` or computed values are stale.
- **Root cause:** Generated/virtual columns referencing renamed base columns; or column not recreated after `->change()` migration.
- **Probe:** `php artisan db:show --json | jq '.tables[] | select(.name=="<table>") | .columns[] | select(.extra | test("GENERATED"))'`
- **Expected:** Generated columns present and formula intact.
- **Fix:** Drop and re-add the generated column in a new migration (can't modify formula in-place on MariaDB < 10.5).

---

## Assets

### Tailwind / Vite Build
- **Symptom:** CSS looks like unstyled HTML; or `Vite manifest not found` exception in production.
- **Root cause:** `npm run build` not run after changes to `tailwind.config.js`, CSS layers, or theme files.
- **Probe:** Check `public/build/manifest.json` exists and its `mtime` is ≥ the latest commit touching `tailwind.config.js`, `resources/css/`, or `resources/js/`.
- **Expected:** Manifest present and newer than any CSS/JS source change in the deploy.
- **Fix:** Run `npm run build` and commit the updated `public/build/` directory (or add to CI pipeline).

### Storage Link
- **Symptom:** Uploaded files (invoice PDFs, media) return 404.
- **Root cause:** `public/storage` symlink missing after fresh deploy.
- **Probe:** `test -L public/storage && echo OK || echo MISSING`
- **Expected:** `OK`.
- **Fix:** Run `php artisan storage:link`.

---

## Application

### APP_DEBUG in Production
- **Symptom:** Stack traces, file paths, env values visible to end users.
- **Root cause:** `APP_DEBUG=true` not switched off before deploy.
- **Probe:** `curl -s <APP_URL>/nonexistent-route-xyz | grep -i "stack trace\|exception\|vendor"` — must return nothing sensitive.
- **Expected:** Generic 404/500 page; no debug output.
- **Fix:** Set `APP_DEBUG=false` and `APP_ENV=production` in `.env`.

### Anthropic API Key
- **Symptom:** Invoice LLM processing silently fails; `LlmLog` entries show `error` status.
- **Root cause:** `ANTHROPIC_API_KEY` not set or expired.
- **Probe:** SSH → `php artisan config:show services.anthropic.key` (or check env). Must be non-empty.
- **Expected:** Key present, non-empty, not the literal string `your-key-here`.
- **Fix:** Set `ANTHROPIC_API_KEY` in production `.env`.

### Magika Binary
- **Symptom:** PDF MIME detection fails; document uploads rejected even for valid PDFs.
- **Root cause:** `magika` binary not installed on production server.
- **Probe:** `which magika && magika --version`
- **Expected:** Binary present, version output.
- **Fix:** Install magika: `pip install magika` (or via system package).

---

## Laravel Production Optimizations

### Config/Route/View Cache
- **Symptom:** App works but is slower than expected; or config values read from `.env` at runtime (risk of stale values).
- **Root cause:** Laravel's config, route, and view caches not generated after deploy.
- **Probe:** `test -f bootstrap/cache/config.php && echo CONFIG_CACHED || echo NOT_CACHED`
- **Expected:** `CONFIG_CACHED` in production.
- **Fix:** Run after every deploy:
  ```bash
  php artisan config:cache
  php artisan route:cache
  php artisan view:cache
  php artisan storage:link --force   # idempotent; also covers storage link
  ```
  Add all four to deploy hook / Makefile.

### Storage Link (deploy hook)
- **Symptom:** Uploaded invoice PDFs return 404 on fresh server or after re-clone.
- **Root cause:** `public/storage` symlink not created; `git clone` does not create it.
- **Probe:** `test -L public/storage && echo OK || echo MISSING`
- **Expected:** `OK`.
- **Fix:** `php artisan storage:link --force` — add to deploy hook so it runs automatically. Discovered on 2026-04-30 dry run.

---

## Adding New Entries

When a new class of production bug is found, append a new section under the relevant category above using the same Symptom/Root cause/Probe/Expected/Fix format. The `/post-deploy-verify` skill reads this file and uses it to generate probes.
