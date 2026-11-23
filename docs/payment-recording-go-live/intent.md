# Payment Recording — Go-Live Verification

**Status:** draft

## Problem
First batch of recurring client invoices went out Friday (2026-11-20), and a payment has come in against one of them. "Record Payment" on the sales invoice view already existed (`BillingService::recordPayment()`) and had been used before in dev/testing, but this was the first real payment under the new go-live billing system, and it hadn't been exercised against live production data. Verifying it against a dev copy of the live database (started 2026-11-23) surfaced a string of real gaps that turned into same-day feature work: no way to see a rolled-up control-account balance, no receipt for a client who pays, no way to record one payment against several invoices at once, and no way to control invoice/payment numbering.

## Proposed outcome
What shipped today (2026-11-23), in the order it came up:

1. **Payment recording verified.** Recorded a real payment in dev against invoice R6552 via the existing "Record Payment" action; confirmed correct invoice balance/status, GL payment Document, and ledger entries.
2. **Account ledger fixes.** Account names on the accounts list are now links; a control account's ledger (e.g. Accounts Receivable) rolls up postings made to its sub-accounts instead of showing empty, since control accounts never take direct postings themselves.
3. **Automatic payment receipts.** Recording a payment now generates a brief PDF receipt (separate from the invoice, since one payment can cover several invoices) and emails it automatically, using its own configurable "Payment Receipt" email template — independent of the invoice email template, with an optional NettMail override (`payment_email_template_id`) matching how the invoice email already worked.
4. **Payments page.** New `/payments` list (Number, Client, Date, Amount) with View (opens the receipt PDF inline) and Send/Resend actions, linked into both nav menus.
5. **Multi-invoice payments.** A single payment can now be recorded against several of one client's open invoices at once — one payment Document, linked to every invoice it covers — via a "New Payment" action on the Payments page (`BillingService::recordPaymentForInvoices()`).
6. **Configurable numbering.** Payment numbers switched from `PAY-2026-NNNNN` to plain digits (new sequence, restarting at 1 — existing historical numbers are untouched). Sales invoice numbers switched from the InvoiceNinja `R`-prefix to plain digits, continuing the same count (R6557 → 6558). Because both types can now share a number, `document_number` uniqueness was rescoped from global to per-`document_type`. Both numbering sequences also got a "Next Invoice/Receipt Number" setting (Settings → Billing → Numbering) to jump the sequence forward on demand; each auto-advances after use and can never undercut a number already issued.

Still outstanding from the original go-live plan:
- Repeat the verified payment-recording action against production (deferred until the business owner is ready).
- Bank-statement reconciliation (matching a payment against an imported PDF statement line) — separate, later concern, untouched by today's work.

Success criteria:
- Payment recorded in dev produces correct invoice balance, status, GL payment Document, and ledger entries. ✅ verified.
- Receipt PDF/email generation works end-to-end for both single- and multi-invoice payments. ✅ verified (automated tests + live dev checks).
- No surprises found before doing the same thing live. Still to confirm once production is attempted.

## Affected users and systems
- Business owner (user) — records payments, reviews receipts, manages numbering settings, and will later do the bank reconciliation.
- Clients — now receive an automatic payment receipt by email.
- Systems: `app/Modules/Billing/Services/BillingService` (`recordPayment()`, `recordPaymentForInvoices()`, `generateReceiptPdf()`, `sendReceipt()`), `app/Modules/Billing/Services/InvoiceEmailTemplateService`, `app/Traits/HasDocumentNumber`, `app/Modules/Billing/Settings/BillingSettings`, `config/documents.php`, the new Payments page (`resources/views/livewire/pages/payments/index.blade.php`), the accounts ledger page (`resources/views/livewire/pages/accounts/show.blade.php`), and (later) `DocumentService::matchReconciliation()` / the bank-statements reconciliation page.
- The live database (backup/restore) and the dev database it gets restored into — restored and reset more than once today during testing.

## Constraints
- Must not touch the live database directly while testing — dev restore only, per project safety rules (no destructive operations against real data).
- User performs the backup/restore themselves; this isn't something Claude does.
- `db:seed` and destructive artisan commands stay blocked for Claude — the user runs the `BillingEmailTemplateSeeder` reseed and any `php artisan migrate` themselves when asked.
- Bank-statement reconciliation remains out of scope for this intent; handled separately once a statement is imported.
- Existing historical payment numbers (`PAY-2026-NNNNN`) and invoice numbers (`RNNNN`) are left exactly as they are — only new documents use the new formats.

## Open questions
- None currently. Remaining work is operational: repeat payment recording in production when ready, and reconcile once a bank statement is available.
