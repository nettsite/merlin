# Client Billing — Production Cutover

**Status:** accepted

## Problem
Client billing/invoicing in Merlin (clients, payment terms, sales invoices, recurring invoices, payments, PDF/email, GL postings, Ninja migration, bank-statement reconciliation) has been under active development for months and is functionally mature — far beyond the original spec (`.claude/specs/billing.md`, `.claude/plans/billing.md`). It is not yet confirmed production-ready: no audit has been done of what's missing, broken, or untested before replacing the current billing system.

## Proposed outcome
Full cutover: Merlin becomes the live billing system for all clients, replacing InvoiceNinja entirely — not a parallel run or pilot. All existing InvoiceNinja invoices are already paid (no open balances to reconcile), which simplifies migration considerably.

**Top priority: recurring invoices generate and send to clients accurately.** This is the core of what "production" means here — correct amounts, correct clients, correct schedule, correct email delivery.

Success criteria:
- Audit identifies every gap blocking go-live (migration correctness, missing UI/settings, test coverage, security, known open bugs).
- Each identified blocker is resolved or explicitly accepted as a known gap by the user.
- Clients, payment terms, and recurring invoice templates correctly migrated from InvoiceNinja (no balances to carry over — history only, not reconciliation).
- `billing:generate-recurring` produces correct invoices (right client, right lines/amounts, right due date) and `sendInvoice()` delivers them to the right recipients, verified against real InvoiceNinja recurring templates before cutover.
- Full test suite green; `documents:verify-audit-trail` clean.

## Affected users and systems
- Business owner (user) — issues and manages client invoices, needs this live.
- Clients — receive invoices/emails from Merlin instead of InvoiceNinja.
- Systems: `app/Modules/Billing/*` (BillingService, RecurringInvoiceService, ClientReceivableAccountService, PortalInviteService, InvoiceEmailTemplateService, SendReminders, ImportFromNinja), GL/journal/postings layer, Spatie Media (PDF storage), mail delivery (local sendmail → msmtp), bank-statement LLM extraction/matching, client portal.
- Known open items from prior work (per project memory): WHMCS paid-signal / AccountResolver inconsistency bug still open; PayFast (online payment collection) was skipped in the original roadmap — currently no in-app payment gateway, payments reconciled via bank statements only. **Confirmed not needed for initial go-live.**

## Constraints
- **Target date: tomorrow (2026-11-20).** This is very aggressive for "full cutover" combined with "need audit first" — a same-day full audit + fix + cutover is unlikely to be realistic. Flagged for the user to confirm scope or timeline before Design stage.
- Must follow project safety rules: no `migrate:fresh`/`db:seed` against the real dev DB, no destructive commands, confirm before any real-money or real-client-data mutation.
- Must not break existing supplier-invoice pipeline or other modules during cutover work.
- No Filament; Livewire 4 native components only.

## Open questions
- Is "tomorrow" truly fixed, or is it aspirational and movable once the audit shows real scope? (This materially changes what "ASAP" means.)
- Is there a hard cutoff date from InvoiceNinja (subscription end, contract) forcing the deadline, or is "tomorrow" self-imposed?
- Any clients who must NOT be touched yet (e.g. active disputes, non-standard billing arrangements, custom recurring terms InvoiceNinja handles that Merlin's `RecurringInvoice` model may not cover)?
- Who verifies each migrated recurring template against InvoiceNinja before the first live run — the user manually comparing, or an automated diff/report?
- Does the WHMCS paid-signal/AccountResolver bug noted in memory actually affect this InvoiceNinja cutover, or was that a separate system (if WHMCS isn't in scope here, drop it)?
