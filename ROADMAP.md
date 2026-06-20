# Roadmap — Parked Items

Things deliberately deferred. Each is viable; none is in current scope. The active
implementation plan lives in `next-steps.mdx`.

---

## Portal — multi-party contact navigation (client switcher)

**Parked.** A single Person can be a contact for many parties (`ContactAssignment` is a
many-to-many with no unique constraint on `person_id` + `party_id`, so the schema already
supports it — e.g. a bookkeeper linked to several of the company's customers).

When such a person logs in to *browse* (rather than following a specific invoice link), they'd
need a way to choose which party they're acting for:

- Account/client **switcher** in the top nav (Xero "My Xero" / QuickBooks company-switcher pattern).
- Session `active_party_id`, validated against the person's `ContactAssignment` rows by middleware; all queries scoped to it.
- Chooser screen on login when >1 party; auto-enter when exactly 1.

**Why parked:** judged an edge case. The primary flow is *follow an invoice link* → the invoice
already determines its party, so context is set automatically with no switcher. Build the
switcher only if multi-party browsing becomes a real need.

## Portal — cross-client overview

**Parked.** An "all my clients" dashboard for a multi-party contact (e.g. everything overdue
across every party they represent). Useful for bookkeepers; depends on the switcher above.

## Portal — supplier self-service

**Parked.** Suppliers logging in to: see purchase invoices Merlin received from them and their
processing status, and submit new invoices straight into the LLM extraction pipeline. Roughly
doubles the portal build. Clients (view + pay sales invoices) ship first.

## Portal — open self-registration

**Parked.** Let anyone self-register and prove ownership of a party (e.g. invoice number +
matching email) with staff approval, instead of invitation-only. More friction and attack
surface; current plan uses invitation / set-password links to existing contacts.

---

## Payment gateways beyond PayFast

**Parked.** The gateway abstraction (`PaymentGateway` / `SupportsRecurring`) is built so these
slot in as new drivers without reworking PayFast or the online page:

- **Peach Payments** — most likely next; supports tokenized recurring.
- **Ozow** — instant EFT; recurring support doubtful (treat as once-off if added).
- **Yoco** — confirm Online Checkout recurring support before building.
- **Netcash** — recurring is a separate debit-order product, different integration path.

---

## From the InvoiceNinja comparison — lower-priority gaps

**Parked** (low value for the ZA SMB use case):

- E-invoicing formats (FatturaPA / ZUGFeRD / Factur-X) — EU regulatory, no local need.
- Delivery notes.
- Gateway-fee surcharging and late fees.
- Full REST API + webhooks for third-party integration.
