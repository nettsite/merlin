---
name: post-deploy-verify
description: "Post-deploy verification for Merlin. Run after any git push to main. Reads .claude/deploy-checklist.md, executes probes against the target environment, reports structured pass/fail per check, diagnoses failures, and proposes (but does NOT auto-merge) fix PRs. Invoke as /post-deploy-verify [--dry-run] [--env=production|staging]."
license: MIT
metadata:
  author: will
---

# post-deploy-verify

Post-deploy verification skill for Merlin. Reads `.claude/deploy-checklist.md`, runs probes, reports pass/fail, and proposes fixes.

---

## Invocation

```
/post-deploy-verify              # probe production (reads PROD_URL from .env.production or asks)
/post-deploy-verify --dry-run    # probe local dev app (APP_URL from .env)
/post-deploy-verify --env=staging
```

---

## Step 0 — Setup

1. Read `.claude/deploy-checklist.md` into memory. This file is the source of truth for all known gotchas.
2. Determine `TARGET_URL`:
   - `--dry-run` → read `APP_URL` from `.env` (e.g. `http://merlin`)
   - `--env=production` → read `PROD_URL` from `.env.production` or ask the user
   - `--env=staging` → read `STAGING_URL` from `.env.staging` or ask the user
3. Determine `SSH_TARGET`:
   - `--dry-run` → no SSH; run artisan commands locally
   - production/staging → SSH target from `DEPLOY_SSH` env var or ask
4. Note the latest commit SHA: `git rev-parse --short HEAD`
5. Print banner:
   ```
   === Merlin post-deploy-verify ===
   Target : <TARGET_URL>
   Mode   : <dry-run|production|staging>
   Commit : <SHA>
   Date   : <ISO date>
   ```

---

## Step 1 — Run Probes

Execute every probe below. For each, record: **CHECK NAME | RESULT (PASS/FAIL/WARN) | DETAIL**.

If `--dry-run`, run artisan commands directly (no SSH prefix). For production/staging, prefix artisan commands with `ssh <SSH_TARGET>`.

### 1.1 Security — CSP Header

```bash
curl -sI <TARGET_URL>/ | grep -i "content-security-policy"
```

- **PASS:** Header present and non-empty.
- **WARN:** Header present but contains `'unsafe-eval'` — log but don't fail.
- **FAIL:** Header absent.

### 1.2 Security — CORS

```bash
curl -sI -H "Origin: https://evil.example.com" <TARGET_URL>/livewire/update \
  | grep -i "access-control-allow-origin"
```

- **PASS:** Header absent (same-origin SPA, CORS not needed) OR header restricts to known domain.
- **FAIL:** `Access-Control-Allow-Origin: *` returned for a cross-origin probe.

### 1.3 Security — APP_DEBUG

```bash
curl -s <TARGET_URL>/nonexistent-route-xyz-9999 \
  | grep -iE "stack trace|exception trace|symfony|laravel.*exception|ErrorException"
```

- **PASS:** No debug output found in response body.
- **FAIL:** Debug traces visible.

### 1.4 Security — HTTPS Redirect (production/staging only)

```bash
curl -sI http://<TARGET_HOST>/ | grep -i "^location:"
```

- **PASS:** Redirects to `https://`.
- **SKIP:** `--dry-run` mode (local dev uses HTTP).
- **FAIL:** No redirect, or redirects to HTTP.

### 1.5 Mail Driver

**dry-run / SSH:**
```bash
php artisan config:show mail.default
```

- **PASS (production/staging):** Not `log` or `array`.
- **PASS (dry-run):** Any value — log what it is but don't fail.
- **FAIL:** `log` or `array` in production/staging.

### 1.6 Queue Connection + Worker

**dry-run:**
```bash
grep QUEUE_CONNECTION .env
```

**production/staging:**
```bash
ssh <SSH_TARGET> "grep QUEUE_CONNECTION .env && ps aux | grep '[q]ueue:work'"
```

- **PASS:** `sync` (acceptable if no async jobs required) OR non-`sync` driver with a running `queue:work` process.
- **FAIL:** Non-`sync` driver but no `queue:work` process found.
- **WARN:** `sync` in production (jobs run inline, no worker needed, but note it).

### 1.7 Morph Map

**dry-run / SSH:**
```bash
php artisan tinker --execute \
  'echo implode(",", array_keys(Illuminate\Database\Eloquent\Relations\Relation::morphMap()));'
```

Expected aliases (from checklist): `account`, `account_group`, `account_type`, `business`, `document`, `document_activity`, `document_line`, `document_relationship`, `llm_log`, `party`, `party_relationship`, `person`, `posting_rule`, `user`.

- **PASS:** All 14 aliases present.
- **FAIL:** Any alias missing from the list.

### 1.8 Pending Migrations

**dry-run / SSH:**
```bash
php artisan migrate:status | grep "Pending\| No "
```

- **PASS:** No lines containing "Pending" or "No" (i.e. no unpublished migrations).
- **FAIL:** One or more pending migrations.

### 1.9 Generated Columns

**dry-run / SSH:**
```bash
php artisan db:show --json 2>/dev/null \
  | python3 -c "import sys,json; [print(t['name']) for t in json.load(sys.stdin).get('tables',[]) if any('GENERATED' in str(c.get('extra','')) or 'generated' in str(c.get('extra','')) for c in t.get('columns',[]))]" 2>/dev/null \
  || echo "(db:show not available or no generated columns found)"
```

- **PASS:** Command completes; report table names with generated columns for review.
- **WARN:** If any generated column table was part of the current deploy diff (check `git diff HEAD~1 --name-only | grep migrations`), flag for manual verification.
- **FAIL:** Command throws an exception.

### 1.10 Vite/Tailwind Build

```bash
test -f public/build/manifest.json && echo "MANIFEST_EXISTS" || echo "MANIFEST_MISSING"
```

Then compare timestamps:
```bash
stat -c %Y public/build/manifest.json 2>/dev/null
git log -1 --format="%ct" -- tailwind.config.js resources/css/ resources/js/ 2>/dev/null
```

- **PASS:** `manifest.json` exists AND its mtime ≥ latest commit touching CSS/JS source.
- **WARN:** `manifest.json` exists but is older than the latest CSS/JS commit (assets may be stale).
- **FAIL:** `manifest.json` missing.

### 1.11 Storage Link

```bash
test -L public/storage && echo "SYMLINK_OK" || echo "SYMLINK_MISSING"
```

- **PASS:** `SYMLINK_OK`.
- **FAIL:** `SYMLINK_MISSING`.

### 1.12 Anthropic API Key

**dry-run / SSH:**
```bash
php artisan tinker --execute \
  'echo config("services.anthropic.key") ? "SET" : "MISSING";'
```

- **PASS:** `SET`.
- **FAIL:** `MISSING` or empty.

### 1.13 Magika Binary

**dry-run / SSH:**
```bash
which magika && magika --version || echo "MISSING"
```

- **PASS:** Binary found, version output present.
- **FAIL:** `MISSING`.

### 1.14 HTTP Health (Login Page)

```bash
curl -so /dev/null -w "%{http_code}" <TARGET_URL>/login
```

- **PASS:** `200`.
- **FAIL:** Any non-200 (especially `500`).

### 1.15 HTTP Health (Dashboard redirect)

```bash
curl -so /dev/null -w "%{http_code}" <TARGET_URL>/dashboard
```

- **PASS:** `302` (redirects to login because unauthenticated — expected).
- **FAIL:** `500` or `200` with error content.

---

## Step 2 — Report

Print a structured results table:

```
=== RESULTS ===

CHECK                          STATUS   DETAIL
─────────────────────────────────────────────────────────────────
1.1  CSP Header                PASS     header present
1.2  CORS                      PASS     no wildcard
1.3  APP_DEBUG                 PASS     no debug output
1.4  HTTPS Redirect            SKIP     dry-run mode
1.5  Mail Driver               WARN     log driver (local only)
1.6  Queue Connection          PASS     sync (local)
1.7  Morph Map                 PASS     14/14 aliases present
1.8  Pending Migrations        PASS     none pending
1.9  Generated Columns         PASS     no generated columns found
1.10 Vite/Tailwind Build       PASS     manifest present and current
1.11 Storage Link              PASS     symlink exists
1.12 Anthropic API Key         PASS     key set
1.13 Magika Binary             PASS     magika 0.x.x
1.14 Login Page                PASS     HTTP 200
1.15 Dashboard Redirect        PASS     HTTP 302

SUMMARY: 14 PASS  1 SKIP  0 WARN  0 FAIL
```

---

## Step 3 — Diagnose Failures

For each FAIL or WARN:

1. Re-read the corresponding entry in `.claude/deploy-checklist.md`.
2. Print the root cause and fix recommendation from the checklist.
3. If the fix involves a code change (not just an env var), generate a fix diff and describe the proposed PR:
   - **Title:** `fix: <short description>`
   - **Branch:** `fix/<kebab-description>`
   - **Body:** Root cause, symptom, proposed change, test steps.
   - Do NOT create the PR automatically. Print the draft and ask: _"Create this fix PR? (yes/no)"_
4. If the fix is an env var change, print the exact line to add/change in `.env`.

Example failure output:
```
=== FAILURES ===

[FAIL] 1.11 Storage Link
  Root cause : public/storage symlink missing after fresh deploy
  Fix (manual): ssh <host> && cd /var/www/merlin && php artisan storage:link
  Fix (code)  : Add `php artisan storage:link` to deploy script / Dockerfile CMD

  Proposed fix PR:
    Branch : fix/storage-link-deploy
    Title  : fix: run storage:link in deploy hook
    Body   : Newly provisioned servers are missing the public/storage symlink.
             Adding artisan storage:link to the post-deploy Makefile target ensures
             uploads are accessible after each fresh deploy.
    Create this PR? (yes/no)
```

---

## Step 4 — Update Checklist (if new bug class found)

If a probe reveals a failure that is NOT covered by an existing checklist entry:

1. Tell the user: _"This failure pattern is not in the checklist. Adding it now."_
2. Read `.claude/deploy-checklist.md`.
3. Append a new section under the correct category using the Symptom/Root cause/Probe/Expected/Fix format.
4. Show the diff before writing.

---

## Step 5 — Exit Summary

Print:
```
=== DONE ===
<N> checks run. <N> passed, <N> skipped, <N> warnings, <N> failures.
Next step: <none / "Review failures above and fix before next deploy">
```

If all checks pass (ignoring skips): print `✓ Deploy looks healthy.`

---

## Notes

- **Never auto-merge** any proposed fix PR.
- **Never run `php artisan migrate --force`** automatically — always ask.
- **Never push** to any branch without explicit user approval.
- This skill is safe to re-run at any time; all probes are read-only.
