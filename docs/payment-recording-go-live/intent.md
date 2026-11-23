# Payment Recording — Go-Live Verification

**Status:** draft

## Problem
First batch of recurring client invoices went out Friday (2026-11-20), and a payment has come in against one of them. "Record Payment" on the sales invoice view already exists (`BillingService::recordPayment()`) and has been used before in dev/testing, but this is the first real payment under the new go-live billing system, and it hasn't been exercised against live production data. Before recording it for real, want to prove the flow against a dev copy of the live database.

## Proposed outcome
Record this one payment safely and correctly, with confidence it will hold up once bank-statement reconciliation runs against it later.

Steps:
1. Back up the live database and restore it into dev (user does this manually).
2. In dev, use the existing "Record Payment" action on the invoice to record this payment (amount, date, reference).
3. Verify the result: invoice status/balance updates correctly, a `payment` Document is created (inbound, linked via `DocumentRelationship` as `payment_for`, with `receivable_account_id` and `contra_account_id` set), and the ledger/trial balance reflects it correctly.
4. Once verified in dev, repeat the same action against production.

Bank-statement reconciliation (matching this payment against an imported PDF statement line) is a separate, later concern — it'll be tested and refined once a statement is actually available. The payment record just needs to be the normal shape `BillingService::recordPayment()` already produces; no reconciliation testing now.

Success criteria:
- Payment recorded in dev produces correct invoice balance, status, GL payment Document, and ledger entries.
- No surprises found before doing the same thing live.

## Affected users and systems
- Business owner (user) — recording the payment and later doing the bank reconciliation.
- Client who paid (Fitting & Handle Distributors (Pty) Ltd, invoice R6557, R3,395.00) — not otherwise affected.
- Systems: `app/Modules/Billing/Services/BillingService::recordPayment()`, `app/Modules/Core/Services/DocumentService::recordPayment()`, `DocumentRelationship`, ledger/trial balance, and (later) `app/Modules/Core/Services/DocumentService::matchReconciliation()` / the bank-statements reconciliation Livewire page.
- The live database (backup/restore) and the dev database it gets restored into.

## Constraints
- Must not touch the live database directly while testing — dev restore only, per project safety rules (no destructive operations against real data).
- User performs the backup/restore themselves; this isn't something Claude does.
- Follows existing `[[project_virtual_gl_payment_documents]]` / `[[project_payment_evidence_recorder]]` conventions already in the codebase — no new payment-recording mechanism needed, this is a verification pass on the existing one.
- Bank-statement reconciliation is out of scope for this intent; it will be handled and tested separately once a statement is imported.

## Open questions
- None currently — "Record Payment" already exists and does what's needed (`BillingService::recordPayment()`).
