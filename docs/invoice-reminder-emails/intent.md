# Manual invoice reminder emails + template system cleanup

**Status:** draft

## Problem

Reminder emails for unpaid invoices currently only go out automatically via the `billing:send-reminders` scheduled command, which matches invoices whose due date + offset (early/first/second/final) lands on today. There is no way to manually trigger a reminder for a specific invoice — e.g. re-sending a reminder on request, sending one out of the normal cadence, or re-sending the original invoice email.

Today, selecting an invoice in the sales invoices table only offers "Cancel invoice."

Separately, the email template setup is confusing:

- **NettMail templates** (`nettmail_templates`, full drag-and-drop branded editor) can optionally be set per purpose (base/invoice/payment) in Billing Settings.
- **BillingEmailTemplate** (Merlin's own simple subject/body rows) is the fallback content, and also the *only* place reminder cadence (`offset_days` per reminder type) lives.
- Reminders currently bypass the NettMail override entirely — `SendReminders` always renders from `BillingEmailTemplate`, so a NettMail template chosen in settings has no effect on reminder emails, unlike invoice/receipt emails.

This split (which system supplies content, which one wins, why reminders behave differently) is not clear from the UI, and isn't clear to the person building it either.

## Proposed outcome

**1. Manual send:**

- From the sales invoices table, selecting one or more invoices offers a new "Send Email" action alongside the existing "Cancel" action.
- "Send Email" presents a choice of: Initial Email (re-send the original invoice email), Early Reminder, First Reminder, Second Reminder, Final Notice, or Custom Email (fully ad hoc, no template).
- Works for a single selected invoice and for a bulk multi-select (same chosen email type sent to all selected invoices).
- Sending reuses the existing render + mail pipeline (`InvoiceEmailTemplateService` → `nettmail` mailer), not a separate path.
- Paid invoices (balance_due = 0) never receive any of the 4 reminder types — manually or automatically. The existing scheduled cadence already excludes paid invoices; the manual action must apply the same rule.
- Before sending, the wording (subject/body) is shown and editable for that send only — the edit never changes the stored template, it only affects the email(s) sent right now.

**2. Template system cleanup:**

- NettMail templates become the single source of truth for billing email *content*, including reminders (closing the gap where reminders currently ignore the NettMail override).
- `BillingEmailTemplate` stops owning subject/body content; it becomes scheduling metadata only — type, `offset_days`, enabled flag, and a link to the NettMail template to use for that reminder type.
- End state: one obvious place to edit what a reminder email says (NettMail), one obvious place to edit when it fires (BillingEmailTemplate's offset/enabled), no silent fallback behaviour that differs by email type.

Success criteria:
- User can send any of the 6 email types for any eligible invoice, on demand, individually or in bulk.
- A paid invoice can never receive a reminder email, manually or automatically.
- A NettMail template assigned to a reminder type actually gets used when that reminder sends (manually or via the scheduled command).
- User can tweak the wording of any send from the UI without it affecting future sends of that same template.
- Settings UI no longer requires understanding two overlapping systems to know what a reminder email will contain.

## Affected users and systems

- **Users:** whoever manages unpaid invoices / billing (admin role), from the sales invoices list page.
- **Systems/components:**
  - `resources/views/livewire/pages/sales-invoices/index.blade.php` — add Send Email bulk/row action + modal.
  - `app/Modules/Billing/Console/SendReminders.php` — scheduled command; manual send should share its render/mail logic, not duplicate it.
  - `app/Modules/Billing/Models/BillingEmailTemplate.php` — model shape changes (drop content fields' role, keep scheduling fields).
  - `InvoiceEmailTemplateService` — render logic; must apply the NettMail override to reminders too.
  - `BillingSettings` — may need per-reminder-type NettMail template id fields (currently only base/invoice/payment).
  - `resources/views/livewire/pages/settings/index.blade.php` — Settings UI for both template systems; needs rework to reflect the simplified model.
  - `database/seeders/BillingEmailTemplateSeeder.php` — seeds the 5 reminder types; may need adjusting if content fields are dropped.

## Constraints

- Must not break the existing automated `billing:send-reminders` cadence — it should use the same template resolution as manual send, just triggered differently.
- Reminder cadence (offset_days per type) must still live somewhere editable — decided to keep `BillingEmailTemplate` rows for this, scheduling only.
- Existing data: any NettMail template IDs already configured for base/invoice/payment in `BillingSettings` should keep working.

## Open questions

- Exact UI for assigning a NettMail template to each reminder type in Settings (one picker per reminder type? reuse the base-template wrapping behaviour?).
- Whether "Initial Email" sent manually from this new action should log/behave identically to the original automatic invoice-send (e.g. audit trail, "already sent" indicators), or is explicitly a resend.
- Migration path for existing `BillingEmailTemplate` subject/body content once NettMail becomes authoritative — discard, or offer to import into a new NettMail template?
- Permission/authorization: which permission gates the new Send Email action (reuse invoice-cancel's permission, or a new one)?
