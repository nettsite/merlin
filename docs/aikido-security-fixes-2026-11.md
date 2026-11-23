# Aikido Security scan — fixes, 2026-11-23

Aikido Security scanned this repo (`nettsite/merlin`) and reported a batch of
"Improper Access Control" findings, then a much larger "AutoFix" dashboard
report (99+ findings). This document records what was verified and fixed
across three rounds, what turned out to be false positives, and what's still
open — for anyone debugging a regression that traces back to one of these
commits, or picking the remaining findings back up later.

Commits: `b6fd9da`, `ca20310`, `c8da0d9` (all on `main`).

## Important: part of the second report is fabricated

The larger "AutoFix" PDF cited files that never existed in this repo —
`app/Actions/Auth/AcceptInvitation.php`, `app/Actions/Auth/AttemptLogin.php`,
`app/Actions/Register/CreateMember.php`, `app/Actions/Privacy/PurgeMembers.php`,
`app/Actions/Messaging/PostReply.php`, `app/Models/Member.php`. Confirmed via
`git log --all` and a full `app/Actions` directory search — it was never there.
The user confirmed Aikido is pointed at the correct repo
(`https://github.com/nettsite/merlin`), ruling out a wrong-repo cause. These
findings are the AI scanner hallucinating against files that don't exist.
**Any finding citing one of those paths should be ignored, not acted on.**

If this scan is re-run and resurfaces similar findings, re-verify the cited
file actually exists before spending time on it.

## Round 1 (`b6fd9da`)

- **All financial reports had zero authorization** (balance-sheet,
  expenses-by-account, expenses-by-supplier, income-by-account,
  income-by-client, income-statement, trial-balance). Added a
  `financial-reports-view` permission (migration
  `2026_11_23_120000_add_financial_reports_permission.php`) and gated
  `mount()` on each.
- **`posting-rules/index.blade.php` `store()`** had no
  `authorize('create', ...)` — callable directly via `save`, bypassing
  `posting-rules-create`.
- **`roles/index.blade.php` `save()`** let any user with
  `users-update`/`users-create` attach *any* permission to a role —
  privilege escalation to Administrator. Added a check that the acting
  user must already hold every permission they try to grant.
- **`DefaultAdminUserSeeder`** used `firstOrCreate` + unconditional
  `assignRole('Administrator')` — if `admin@example.com` self-registered
  via public `/register` before the seeder ran, it would hand that account
  Administrator. Now skips entirely if the email already exists.

## Round 2 (`ca20310`)

- **`bank-statements/index.blade.php` `createPaymentForLine()`** only
  checked `documents-update`, never `can-record-payments` — any
  document-editor could record arbitrary purchase/sales payments. Added
  the check.
- **`recurring-invoices`, `payment-terms`, `clients` `store()`** — same
  `HasCrudForm` missing-`authorize('create', ...)` gap as posting-rules.
  Fixed.
- **`GenerateBankTemplateHints` job** — a free-text "reprocess hint" on
  bank statements, gated only by `documents-update` on one's own
  statement, fed the LLM and could overwrite the *shared*
  `BankTemplate.layout_hints` used for every future statement from that
  bank. Fixed by dropping the hint server-side unless the user holds
  `bank-templates-update`.
- **Unlocked `$detailId`** on 6 pages (journals, purchase-invoices,
  sales-invoices, quotes, credit-notes, bank-statements) — a plain public
  Livewire property is client-writable, so the "currently open record" id
  could be tampered with in the request payload (IDOR). Added
  `#[Locked]`.
- Determined **not** a bug: `PartyRelationshipPolicy`/`PersonPolicy` exist
  as model-policy boilerplate but are never actually used — relationships
  are correctly gated via the parent Party's `update` ability.
- Flagged as **design judgment calls, not defects** (need a product
  decision, not a fix): all settings pages share one coarse
  `access-panel` permission; `can-record-payments` is shared across sales
  and purchase payment-recording; payment-notification confirmations bind
  loosely to a bare id (lower severity than it reads, since the invoice
  ultimately checked comes from the notification's own server-set
  metadata).

## Round 3 (`c8da0d9`) — the larger AutoFix report

- **`ProcessInvoiceDocument` job**: unconditionally called
  `$this->document->lines()->delete()` on every run, including retries.
  **Relation `->delete()` on a soft-deleting model (`DocumentLine` uses
  `SoftDeletes`) compiles to a bulk `UPDATE ... SET deleted_at = ...`,
  which bypasses Eloquent model events entirely** — so it never fires
  `DocumentLine`'s `deleting` guard (`guardAgainstPostedMutation()`). A
  retry landing after the same job's prior attempt had already posted the
  invoice (e.g. a 120s timeout firing right after the DB commit) would
  silently wipe a **posted** invoice's lines. Fixed by checking
  `$this->document->is_issued` and failing the job instead of deleting.
  `Purchasing\Services\DocumentService::reprocess()` already guarded this
  correctly (checks status before the same delete pattern) — only the job
  lacked it.
- **`AccountResolver::resolve()`**: the history-match query had no
  `document_type` scope, so a credit note or journal line with the same
  supplier + description could leak its account onto a new purchase
  invoice line. Neither the history path nor the LLM-suggestion path
  checked `Account::is_active` — only `allow_direct_posting` — so a
  deactivated account could still be auto-assigned. Fixed both: added
  `where('document_type', 'purchase_invoice')` and `->active()` on top of
  `->postable()`.
- **`PostingRuleService::isEligible()`**: didn't check supplier status —
  a `pending` (unverified, auto-created from extracted invoice data via
  `SupplierResolver::createPendingSupplier()`) supplier could get
  autonomously posted into the GL before a human confirmed it. Added
  `$document->party?->status !== 'active'` guard.
- **`DocumentService::matchReconciliation()`**: validated only the
  statement-line side (`document_type` in `bank_statement`/
  `credit_card_statement`); the match target `$document` was completely
  unchecked. `matchLine($lineId, $documentId)` in the bank-statements page
  passes a client-supplied `$documentId` straight through — any document
  id (a draft quote, a journal, another statement) was accepted as a
  valid reconciliation match. Added two checks: the target can't be a
  statement itself, and its `contra_account_id` must match the
  statement's (i.e. it's actually a payment on the same bank account).
- **Bank-statements `confirmReprocess()`**: `openReprocess()` (the
  modal-opener) checked `status === 'reconciled'` and blocked, but
  `confirmReprocess()` (the actual mutating action, independently
  reachable via a direct wire call) never re-checked — same
  "UI-opener authorizes, mutator doesn't" class as the `store()` bugs in
  rounds 1–2. Added the same status guard directly to `confirmReprocess()`.
- **`DocumentService::transition()` / `recordPayment()`**: neither locked
  the document row. Both read the document's current status/balance from
  an already-loaded in-memory object, validated against it, then wrote
  inside a `DB::transaction()` — but a plain transaction doesn't take a
  row lock by itself. Two concurrent calls (a double-submit, or a manual
  payment racing the `PaymentEvidenceRecorder` auto-recorder, or a manual
  status change racing autonomous posting) would both validate/compute
  off the same stale snapshot, and the second write silently clobbers the
  first (lost update) — could regress a status transition or let an
  invoice be overpaid without ever tripping the overpayment guard. Fixed
  both by adding `Document::lockForUpdate()->findOrFail($doc->id)` +
  `setRawAttributes()` at the top of the transaction, before any
  validation. This also covers `markAsSent()`'s concurrent-send finding,
  since it goes through the same `transition()`.
- **`recordPayment`'s contra account**: validated only by the form's
  `nullable|uuid|exists:accounts,id` rule — any account in the chart,
  including the AR/AP control account itself, passed. Added
  `DocumentService::assertValidContraAccount()` (reuses the existing
  `controlAccountIds()` used by `postJournal()`), called from both
  `recordPurchasePayment()` and `BillingService::recordPaymentForInvoices()`.
- **`BillingService::recordPaymentForInvoices()`**: never checked
  `document_type === 'sales_invoice'` — only status (`sent`/
  `partially_paid`, which `quote` also has). `DocumentPolicy::view()`/
  `update()` are completely type-agnostic (any document, any type), so a
  user with `documents-view` could open a Quote in the sales-invoices
  page's detail view (the `#[Locked]` `detailId` from round 2 prevents
  tampering, but not opening a wrong-typed document the policy itself
  allows) and get a bogus payment recorded against it. Added an explicit
  `document_type` check, matching `recordPurchasePayment()`'s existing one.
- **5× `HasCrudForm` pages** (`account-groups`, `accounts`, `clients`,
  `posting-rules`, `payment-terms`): `update()` was missing a
  re-authorize call. `fillForm()` (the edit-opener) authorizes, but
  `update()` (the independently-reachable mutating action) didn't.
  `editingId` is `#[Locked]`, so this isn't a hard IDOR — but it violates
  the project's own rule (see `~/.claude/laravel-safety.md` /
  `feedback_livewire_security` memory: *"authorize() inside every
  mutating action method... each public action that writes data must
  re-check authorization independently, regardless of what
  create()/edit() checked before it"*). Fixed all 5, mirroring the
  `store()` fix from rounds 1–2.
- **3× `HasCrudForm` pages** (`accounts`, `bank-templates`,
  `account-groups`): `store()` was missing `authorize('create', ...)` —
  found via a sweep of all 8 pages using the trait, not from the report
  itself.

### A regression caught by the test suite, not inspection

While wiring `$contraAccountId`/`$finaliseRate` through `recordPurchasePayment()`,
a closure's `use (...)` list lost `$data`, which silently made
`$data['finalise_rate'] ?? false` evaluate to `false` always. This broke
exactly one test (`PurchaseInvoicePaymentTest`, the exchange-rate
finalization case) with a misleading error ("payment exceeds balance due")
that looked like a lockForUpdate side effect but wasn't. Caught by running
`composer run test`, not by re-reading the diff. **Lesson: always rerun the
full suite after any edit that touches a closure's captured variables, even
if the specific line "looks" correct.**

## Still open (not yet reviewed/fixed)

From the AutoFix report:

- Negative credit-note prices allowing document-edit bypass
- `InvoiceEmailTemplateService` — merge values inserted into outbound mail
  without apparent sanitization
- `SupplierPayableAccountService` — metadata bypass
- Purchase-invoice ZIP upload: unbounded size / rejected-line mutation
- `HasCrudTable` — unbounded page-size request
- Profile email-change: persistence of old session/verification state

From round 2, design judgment calls needing a product decision (not a code
defect — see above): coarse `access-panel`/`can-record-payments`
permissions, loose payment-notification-confirmation binding.

Deep LLM-trust-boundary items, flagged but deliberately not patched (no
"current user" context exists in the async job paths where these run, so
this needs a product decision on how much to trust LLM-read invoice text,
not a quick `authorize()` fix):

- Document upload can auto-record a real GL payment when extracted text
  contains paid-evidence language and fuzzy-matches an existing invoice
  (`InvoiceProcessingService` / `PaymentEvidenceRecorder`).
- Unverified invoice supplier matching — a forged invoice whose extracted
  tax number/name matches a real supplier gets silently attributed to
  that supplier's account.
- Ambiguous fuzzy duplicate matching (`DuplicateInvoiceMerger`) — the
  code's own comments already acknowledge this risk and scope the
  fallback narrowly; treated as a conscious existing trade-off, not
  re-litigated.

Also still outstanding: 16 subissues from the *first* Aikido report were
hidden behind a "Show 16 more" toggle that the original PDF export never
captured. They were never reviewed.

## How to use this if something breaks

- If a posted/issued invoice's lines went missing unexpectedly: check
  `ProcessInvoiceDocument` retry behavior and the `is_issued` guard added
  in `c8da0d9` — if it's regressed, that guard is the place to look first.
- If a payment or status transition looks double-applied or lost under
  load: check `DocumentService::transition()`/`recordPayment()` locking —
  this is new behavior as of `c8da0d9`, and if removed or bypassed
  (e.g. by a new caller that reads the document *outside* this method and
  trusts its own copy afterward), the race reopens.
- If reconciliation matching rejects something that should be valid: the
  `contra_account_id` equality check in `matchReconciliation()` is the
  new restriction — a legitimate payment type that doesn't carry
  `contra_account_id` the same way existing ones do will fail this check
  and needs the check (not removal) revisited.
