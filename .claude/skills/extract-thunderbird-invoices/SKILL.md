---
name: extract-thunderbird-invoices
description: "Pull supplier invoice PDFs and payment-confirmation emails out of the _NettSite Thunderbird mail tree into extracted_invoices/ for later manual review/import into invoice_queue/. Uses the thunderbird-mail MCP tools (only work in a live Claude Code session). Invoke as /extract-thunderbird-invoices [--folder=Name] [--dry-run]."
license: MIT
metadata:
  author: will
---

# extract-thunderbird-invoices

Pulls purchasing-related email (supplier invoices, receipts, payment-gateway confirmations) out of the `_NettSite` Thunderbird folder tree and stages it as PDFs in `extracted_invoices/` at the project root — **not** directly into `invoice_queue/` (`INVOICE_WATCH_DIR`). The user reviews `extracted_invoices/` and manually moves/copies what they want into `invoice_queue/`, which then dedupes by SHA-256 content hash and runs through the normal ingestion pipeline (`WatchInvoiceFolderCommand`).

Thunderbird MCP tools (`mcp__thunderbird-mail__*`) only work inside a live Claude Code session — there is no headless/background equivalent. This skill is the fixed, repeatable procedure to follow each time it's invoked; do not improvise a different approach.

---

## Invocation

```
/extract-thunderbird-invoices                     # full scan, all included folders, incremental
/extract-thunderbird-invoices --folder=Expenses    # restrict to one included folder
/extract-thunderbird-invoices --dry-run            # classify and report only, write nothing
```

`--folder` and `--dry-run` can be combined.

---

## Step 0 — Setup

1. If the `mcp__thunderbird-mail__*` tools are deferred, load them: `ToolSearch("select:mcp__thunderbird-mail__listFolders,mcp__thunderbird-mail__searchMessages,mcp__thunderbird-mail__getMessage,mcp__thunderbird-mail__getAccountAccess")`.
2. Call `getAccountAccess` — confirm the Thunderbird MCP extension can see `account1` (william@nettsite.co.za). If not, stop and tell the user to check Tools > Add-ons > Thunderbird MCP > Options in Thunderbird.
3. Resolve the folder scope:
   - **Included** (purchasing-related): `Expenses`, `HostAfrica`, `Supersonic`, `Domains`, `Laravel Cloud`, `Mail Blaze` — all direct children of `_NettSite`.
   - **Excluded**: `Projects/*`, `Clients/*`, `Tax`, `Marketing`, `Jobvine` (freelance lead notifications, not purchasing), `Google` (IARC rating notices / Business Profile reports, not billing — confirmed by inspection 2026-07-20) — never scan these, even if `--folder` isn't given.
   - If `--folder=Name` is passed, restrict to just that one folder (must be in the included list — if not, tell the user and stop).
   - Folder URI base: `imap://william%40nettsite.co.za@mail.nettmail.co.za/_NettSite/<FolderName>` (use `listFolders` if a name's exact path/casing is unclear, e.g. `Mail Blaze` has a space).
4. Ensure `/home/will/Projects/nettsite/merlin/extracted_invoices/` exists (`mkdir -p`, skip if `--dry-run`).
5. Ensure `extracted_invoices/` is in `.git/info/exclude` (append if missing, skip if `--dry-run`):
   ```bash
   grep -qxF 'extracted_invoices/' .git/info/exclude || printf 'extracted_invoices/\n' >> .git/info/exclude
   ```
6. Load `extracted_invoices/.manifest.json` (a JSON object mapping `"<folderPath>#<messageId>"` → filename written). Treat missing file as `{}`.

---

## Step 1 — Enumerate messages per folder

For each included folder (or the single `--folder` target):

1. `searchMessages(query:"", folderPath:<folder URI>, includeSubfolders:false, maxResults:200, sortOrder:"asc")`.
2. If the folder has more than 200 messages (check `totalMatches`/`hasMore` if present, or compare count returned to `listFolders`' `totalMessages` for that folder), page with `offset` until exhausted. Note in the final report if this happened — large folders like `Expenses` (119 at last check) are fine today but may grow past 200.
3. For each message, build the manifest key `"<folderPath>#<messageId>"`. If already in the manifest, count it as **skipped** and move on — do not re-fetch it.

---

## Step 2 — Classify and extract each new message

For every message not already in the manifest:

1. Call `getMessage(messageId, folderPath, saveAttachments:true, bodyFormat:"html")`.
2. **If the response includes one or more attachments with a `.pdf` filename** (check the `filePath`/`attachments` info returned, saved under `<OS temp dir>/thunderbird-mcp/<messageId>/`):
   - For each PDF attachment, copy it into `extracted_invoices/` under the filename built in Step 3.
   - Ignore non-PDF attachments (images, `.ics`, signature files, etc.).
3. **If there is no PDF attachment**:
   - Build a small HTML document: a header block with From / To / Subject / Date, then the message's HTML (or markdown-converted, if HTML unavailable) body.
   - Write it to a scratch file, e.g. `/tmp/claude-*/.../scratchpad/thunderbird-render/<messageId>.html`.
   - Render to PDF:
     ```bash
     google-chrome --headless --disable-gpu --no-sandbox \
       --print-to-pdf=<extracted_invoices/target-filename.pdf> \
       file://<scratch>.html
     ```
   - This unconditionally covers payment-gateway confirmations (FNB, PayFast, PayPal, and anything else with no attachment) as well as invoice/receipt emails with no PDF — no sender-based filtering needed.
4. On any failure for a given message (fetch error, render error), log it as a **failure** in the report and continue — do not abort the whole run.
5. In `--dry-run` mode: do everything above except the actual copy/render/write — just compute and report what *would* happen (classification + target filename).

---

## Step 3 — Filename convention

```
YYYYMMDDTHHMMSS__<folder-leaf>__<slug>.pdf
```

- `YYYYMMDDTHHMMSS` — the message's date (not extraction time), so lexicographic sort ≈ chronological order (an invoice generally sorts before its later payment confirmation).
- `<folder-leaf>` — last path segment of the source folder (e.g. `HostAfrica`, `Expenses`, `Mail Blaze` → `Mail-Blaze`, spaces to hyphens).
- `<slug>` — for attachment case: slugified original attachment filename (strip extension, kebab-case, keep it recognizable). For rendered case: slugified email subject. Truncate to ~80 chars total filename length.
- Collisions: if the target filename already exists in `extracted_invoices/`, append `-2`, `-3`, etc. before `.pdf`.

Update the manifest (in memory, then persist per Step 4) with `"<folderPath>#<messageId>" → "<final filename>"` immediately after a successful write — don't batch this till the end, so a crash mid-run doesn't lose progress already made.

---

## Step 4 — Persist manifest

After each successful extraction (not just at the end), rewrite `extracted_invoices/.manifest.json` with the updated map. In `--dry-run` mode, never write the manifest.

---

## Step 5 — Report

Print a summary table:

```
=== extract-thunderbird-invoices ===
Mode: <full | --folder=X> <dry-run|live>

FOLDER            SCANNED  SKIPPED(manifest)  PDF-ATTACH  RENDERED  FAILED
Expenses          119      110                6           3         0
HostAfrica        48       48                 0           0         0
Supersonic        9        9                  0           0         0
Domains           22       22                 0           0         0
Laravel Cloud     10       10                 0           0         0
Mail Blaze         0        0                 0           0         0
─────────────────────────────────────────────────────────────────────
TOTAL             208      199                 6           3         0

extracted_invoices/ now contains <N> files.
Next step: review extracted_invoices/ and move wanted files into invoice_queue/
(dedup is automatic there via SHA-256 content hash — safe to copy freely).
```

If any folder exceeded 200 messages and required paging, note it explicitly. If any failures occurred, list each one (`folder / messageId / subject / error`).

---

## Notes

- This skill **never writes to `invoice_queue/` directly** — always stages to `extracted_invoices/` for manual review, since bulk historical import could otherwise trigger hundreds of LLM extraction calls / auto-postings at once.
- Safe to re-run at any time — the manifest makes reruns incremental (only new mail since the last run gets processed).
- If the included/excluded folder list needs to change (new supplier folder added in Thunderbird, etc.), update Step 0.3 in this file directly.
- `searchMessages` only returns headers/metadata — actual body/attachments require `getMessage` per message, which is why this is inherently a lot of tool calls for a large mailbox. That's expected, not a bug.
