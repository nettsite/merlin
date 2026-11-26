# Manual invoice reminder emails + template system cleanup

**Status:** draft
**Derived from:** [intent.md](./intent.md)

## 1. Manual "Send Email" action

### 1.1 UI

`resources/views/livewire/pages/sales-invoices/index.blade.php`:

- A `Send email` `<flux:button>` is added to the bulk-action bar next to the existing `Cancel invoices` button, gated the same way (`@can('update', new \App\Modules\Core\Models\Document)`).
- Clicking it opens a new modal (`showSendEmailModal`) with a single-select of 6 email types:
  - Initial Email
  - Early Reminder
  - First Reminder
  - Second Reminder
  - Final Notice
  - Custom Email (ad hoc — §1.6)
- The 4 reminder options are built from `BillingEmailTemplate::reminder()->orderBy('offset_days')->get()` (so they always match whatever reminder templates/names exist — not hardcoded strings). If a reminder template is `enabled = false`, it still appears in the manual list but with a "disabled" hint — manual send is explicit intent and shouldn't be blocked by the automatic-cadence toggle.
- "Initial Email" has no `BillingEmailTemplate` row; it is a fixed option that maps to the existing invoice-send pipeline.
- Works for the current selection exactly like `openCancelModal`/`confirmCancel` (`targetInvoices()`), including `selectAllFiltered`.
- After picking a type (other than Custom Email), the modal advances to an **edit step** showing the resolved subject/body for that type, editable before sending — §1.6.

### 1.2 Eligibility

- Only sales invoices that are not `draft` and not `cancelled` can receive any of the 6 email types (an invoice must have been issued once before you email about it; a cancelled invoice has nothing to remind about).
- The 4 **reminder** types additionally require `balance_due > 0` — a fully paid invoice is excluded from every reminder type, manual send included. This matches `SendReminders`' existing `document_balances.balance_due > 0` filter; the manual action must apply the same rule so a paid invoice can never be sent a reminder either way. **Initial Email** and **Custom Email** are not balance-gated (resending the original invoice, or an ad hoc note, can be legitimate even once paid).
- Invoices with no resolvable recipients (`BillingService::resolveRecipients()` returns empty) are skipped, same as `SendReminders` does today.
- Modal shows eligible / skipped counts before sending, mirroring `cancelEligible`/`cancelSkipped`. Skip reasons (draft/cancelled, paid, or no recipients) are merged into one "skipped" count with an explanatory line, consistent with the existing cancel modal's level of detail.

### 1.3 Livewire component

New public properties/methods on the sales-invoices index component:

```php
public bool $showSendEmailModal = false;
public ?string $selectedEmailType = null; // 'initial' | 'custom' | a BillingEmailTemplate id
public string $emailSubjectOverride = '';
public string $emailBodyOverride = '';
public int $sendEligible = 0;
public int $sendSkipped = 0;

public function openSendEmailModal(): void    // mirrors openCancelModal
public function selectEmailType(): void        // loads the edit step's starting subject/body (§1.6)
public function confirmSendEmail(): void       // mirrors confirmCancel
```

`confirmSendEmail()`:

1. `$this->authorize('update', new Document)`.
2. Re-resolve `targetInvoices()` and re-check eligibility (never trust modal state, same rule as cancel).
3. For each eligible invoice, `$this->authorize('update', $document)`, then dispatch to `BillingService`:
   - `selectedEmailType === 'initial'` → `BillingService::sendInvoice($document, Auth::user())` (existing method; already handles resend vs. first-send, PDF regeneration, and `recordResend`/`markAsSent`). No override support for Initial Email in this first pass — see §1.6 note.
   - `selectedEmailType === 'custom'` → `BillingService::sendCustomEmail($document, $emailSubjectOverride, $emailBodyOverride, Auth::user())` (new method, §1.6).
   - otherwise → `BillingService::sendReminder($document, $reminderTemplate, Auth::user(), subjectOverride: $emailSubjectOverride, bodyOverride: $emailBodyOverride)` (new method, §1.4; overrides null unless the user actually edited the text).
4. Flash a summary message: "Sent {n} {email type}. Skipped {m}." — same tone as the cancel flow's flash message.
5. Close modal, clear selection.

Each invoice send runs in its own try/catch (like `SendReminders` does per-invoice) so one failure doesn't abort the batch; failures are counted separately from "skipped" and logged via `Log::error`.

### 1.4 `BillingService::sendReminder()` (new, extracted from `SendReminders`)

```php
public function sendReminder(
    Document $invoice,
    BillingEmailTemplate $reminder,
    ?User $by,
    ?string $subjectOverride = null,
    ?string $bodyOverride = null,
): void {
    $recipients = $this->resolveRecipients($invoice);

    if ($recipients === []) {
        throw new RuntimeException('No recipients found for '.($invoice->party?->displayName ?? 'this client').'.');
    }

    $templates = app(InvoiceEmailTemplateService::class);

    foreach ($recipients as $recipient) {
        $firstName = explode(' ', $recipient['name'])[0] ?? '';

        $rendered = $subjectOverride !== null || $bodyOverride !== null
            ? $templates->renderWithOverride($invoice, $reminder, $subjectOverride, $bodyOverride, $firstName)
            : $templates->render($invoice, $reminder, $firstName);

        Mail::mailer('nettmail')->to($recipient['email'])->send(
            new SalesInvoiceMail($invoice, $rendered['subject'], $rendered['html']),
        );
    }

    app(DocumentService::class)->recordActivity(
        $invoice, $by, 'reminder_sent', "Reminder email ({$reminder->name}) sent to ".implode(', ', array_column($recipients, 'email')).'.',
    );
}
```

`SendReminders::handle()` is updated to call this same method (with no overrides) per matched invoice instead of duplicating the render/mail/recipient-resolve logic inline — the scheduled command and the manual action become two callers of one code path. `DocumentService::recordActivity` must be `public` (check current visibility; `recordResend`/`cancelInvoice` already call a `recordActivity` helper — confirm it's usable from `BillingService` or expose a thin public wrapper if it's currently `private`).

### 1.5 Permission

Reuses the existing `update` ability on `Document` — the same one that gates `Cancel invoices` today. No new permission is introduced; sending a reminder is judged the same sensitivity as cancelling an invoice (both mutate how/whether the client is contacted about money owed).

### 1.6 Edit wording before send (applies to this send only) + Custom Email

**Edit step.** After choosing a type, the modal shows an editable Subject field and an editable Body field, pre-filled with that type's *template text* — i.e. the raw subject/body with merge tags still in place (`{{client_name}}`, `{{invoice_number}}`, etc.), not a fully-substituted preview. Editing happens before merge-tag substitution so one edited piece of text still works correctly across a bulk batch of different invoices — each invoice gets its own merge-tag substitution applied to the (possibly edited) text at send time, exactly like the unedited path does today.

- For a type backed by legacy `subject`/`body` (`nettmail_template_id` is null): the fields are pre-filled from `$reminder->subject` / `$reminder->body`.
- For a type backed by a full NettMail template (`nettmail_template_id` set, or Initial Email with `invoice_email_template_id` set): the Subject field pre-fills from the NettMail template's subject; the Body field pre-fills from its HTML. Editing raw branded HTML in a textarea is rough but functional — flagged as a UX follow-up, not blocking this spec. **Initial Email is excluded from the edit step in this first pass** (§1.3) because `BillingService::sendInvoice()` doesn't currently take override content and changing its signature touches the first-send path too — revisit once the override mechanism is proven on reminders.
- Nothing typed here is written back to `BillingEmailTemplate` or the NettMail `Template` row — it only affects the emails sent in this one action.
- `InvoiceEmailTemplateService` gains:

```php
public function renderWithOverride(
    Document $invoice,
    BillingEmailTemplate $emailTemplate,
    ?string $subjectOverride,
    ?string $bodyOverride,
    string $recipientFirstName = '',
): array {
    $values = $this->mergeTagValues($invoice, $recipientFirstName);
    $nettMailTemplate = $emailTemplate->nettmail_template_id ? Template::find($emailTemplate->nettmail_template_id) : null;

    $subjectTemplate = $subjectOverride ?? ($nettMailTemplate?->subject ?? $emailTemplate->subject);
    $bodyTemplate = $bodyOverride ?? ($nettMailTemplate?->html ?? $emailTemplate->body);

    if ($nettMailTemplate !== null) {
        $renderer = new MergeTagRenderer;

        return ['subject' => $renderer->render($subjectTemplate, $values), 'html' => $renderer->render($bodyTemplate, $this->htmlValues($values))];
    }

    $subject = $this->substitute($subjectTemplate, $values);

    return ['subject' => $subject, 'html' => $this->wrapInBaseTemplate($this->substitute($bodyTemplate, $this->htmlValues($values)), $this->htmlValues($values))];
}
```

**Custom Email (ad hoc).** No underlying `BillingEmailTemplate` row at all — the modal's edit step opens with blank Subject/Body (merge tags documented via `InvoiceEmailTemplateService::availableShortcodes()`, same tag list as other billing emails). Always wrapped in the configured base NettMail layout (`BillingSettings::base_email_template_id`) for consistent branding, same as legacy-content emails.

```php
public function sendCustomEmail(Document $invoice, string $subject, string $body, ?User $by): void
{
    $recipients = $this->resolveRecipients($invoice);

    if ($recipients === []) {
        throw new RuntimeException('No recipients found for '.($invoice->party?->displayName ?? 'this client').'.');
    }

    $templates = app(InvoiceEmailTemplateService::class);

    foreach ($recipients as $recipient) {
        $rendered = $templates->renderCustom($invoice, $subject, $body, explode(' ', $recipient['name'])[0] ?? '');

        Mail::mailer('nettmail')->to($recipient['email'])->send(
            new SalesInvoiceMail($invoice, $rendered['subject'], $rendered['html']),
        );
    }

    app(DocumentService::class)->recordActivity(
        $invoice, $by, 'custom_email_sent', 'Custom email sent to '.implode(', ', array_column($recipients, 'email')).'.',
    );
}
```

`InvoiceEmailTemplateService::renderCustom()` is the same substitute-and-wrap logic as the legacy branch of `renderWithOverride()`, just without a `BillingEmailTemplate` to fall back to (subject/body come only from the caller).

## 2. Template system consolidation

### 2.1 Schema change

New migration, adds one nullable column to `billing_email_templates`:

```php
Schema::table('billing_email_templates', function (Blueprint $table): void {
    $table->uuid('nettmail_template_id')->nullable()->after('type');
});
```

- `billing_email_templates` keeps `type`, `name`, `offset_days`, `enabled` as scheduling/identity metadata — unchanged.
- `subject` and `body` are kept (not dropped) as the **legacy fallback content**, used only when `nettmail_template_id` is null. This avoids a forced content migration and keeps existing reminder rows working unchanged until someone opts a row into a NettMail template.
- `nettmail_template_id`, when set, points at a `nettmail_templates` row holding the complete email (subject + HTML) for that reminder stage — same shape as `BillingSettings::invoice_email_template_id` / `payment_email_template_id` already use for invoice/payment emails.

This is on the row itself (not a new `BillingSettings` field per reminder type) because `BillingEmailTemplate` already *is* the one row per reminder stage; adding settings fields would duplicate that identity in a second place.

### 2.2 `InvoiceEmailTemplateService::render()` change

Current signature already accepts `?BillingEmailTemplate $emailTemplate`. Today, when `$emailTemplate !== null` (the reminder path), it never consults NettMail at all — that's the bug. New logic:

```php
public function render(Document $invoice, ?BillingEmailTemplate $emailTemplate = null, string $recipientFirstName = ''): array
{
    $values = $this->mergeTagValues($invoice, $recipientFirstName);

    // Resolve which NettMail template (if any) supplies full content for this email:
    // - invoice email (no $emailTemplate passed): BillingSettings::invoice_email_template_id
    // - a specific template row (reminders, and invoice when explicitly passed one): its own nettmail_template_id
    $nettMailTemplate = $emailTemplate === null
        ? $this->invoiceEmailTemplate()
        : ($emailTemplate->nettmail_template_id ? Template::find($emailTemplate->nettmail_template_id) : null);

    if ($nettMailTemplate !== null) {
        $renderer = new MergeTagRenderer;

        return [
            'subject' => $renderer->render($nettMailTemplate->subject ?: self::DEFAULT_INVOICE_SUBJECT, $values),
            'html' => $renderer->render($nettMailTemplate->html ?? '', $this->htmlValues($values)),
        ];
    }

    $emailTemplate ??= BillingEmailTemplate::forInvoice();

    if ($emailTemplate === null) {
        throw new RuntimeException('No invoice email template configured. Run the BillingEmailTemplateSeeder.');
    }

    // ...unchanged legacy subject/body + wrapInBaseTemplate path
}
```

This makes reminders behave exactly like invoice/payment emails: a NettMail template wins when assigned, otherwise fall back to the Merlin-native subject/body wrapped in the base layout. No separate code path for reminders anymore.

### 2.3 Settings UI

`resources/views/livewire/pages/settings/index.blade.php`, reminder template rows (currently ~lines 163–220, 389–396, 506–509, 619+):

- Each reminder row gets a template picker: reuse the `<x-searchable-select>` component (already used elsewhere for large-list selects, per project memory) listing `nettmail_templates`, same as the existing "Invoice email" / "Payment email" pickers in the base/invoice/payment section.
- When a NettMail template is picked for a row, the row's `subject`/`body` fields are hidden (collapsed behind a "Legacy content (unused while a NettMail template is selected)" disclosure) rather than removed from the form — editing them still updates the fallback, it's just de-emphasized since it has no effect while a NettMail template is active.
- `offset_days` and `enabled` stay exactly where they are — this is the "when" unaffected by the "what" change.
- No wording in the UI should need to explain "NettMail vs BillingEmailTemplate" as two systems — the row just has one "Email content" picker (NettMail) and an "Advanced: legacy content" fallback section, the same pattern on every row (invoice, payment, and all 4 reminder stages). That consistency is the actual fix for the confusion described in the intent.

### 2.4 Data migration

No automatic conversion of existing `subject`/`body` reminder content into new NettMail templates (per intent's open question — decided: leave manual). Existing reminder rows keep sending their current legacy content unchanged until an admin assigns a NettMail template to that row.

## 3. Out of scope

- Redesigning the NettMail template editor itself.
- Changing the reminder cadence/offsets seeded by `BillingEmailTemplateSeeder`.
- Client portal changes.

## 4. Testing

- Feature test: bulk "Send email" sends the correct template type to multiple selected invoices, skips ineligible ones (draft/cancelled/no recipients), and reports correct counts.
- Feature test: a fully paid invoice (`balance_due = 0`) is skipped for all 4 reminder types, both via the manual action and via `billing:send-reminders`, but remains eligible for Initial Email and Custom Email.
- Feature test: `BillingService::sendReminder()` sends via the NettMail template when the reminder row has `nettmail_template_id` set, and via legacy subject/body + base wrapper when it's null — covering both branches of the `InvoiceEmailTemplateService::render()` change.
- Feature test: editing the subject/body in the Send Email modal before confirming changes the rendered content for that send, without persisting any change to the underlying `BillingEmailTemplate` or NettMail `Template` row.
- Feature test: Custom Email sends the ad hoc subject/body (merge tags substituted per invoice, wrapped in the base layout) with no `BillingEmailTemplate` row involved, and creates a `custom_email_sent` activity entry.
- Regression test: `billing:send-reminders` (scheduled command) still matches/sends exactly as before now that it calls the shared `sendReminder()` method.
- Regression test: existing invoice-email and payment-receipt rendering tests still pass unchanged (the `render()`/`renderReceipt()` NettMail-override behavior for those two is untouched).

## Open questions

- Confirm `DocumentService`'s activity-recording helper (`recordActivity` or equivalent) is public/reusable from `BillingService`, or needs a thin wrapper.
- Exact copy for the "skipped" explanation in the Send Email modal (draft/cancelled/paid vs. no recipients) — can follow the cancel modal's existing wording style.
- Initial Email has no edit-before-send support in this first pass (§1.6) — acceptable, or does Initial Email need it too from day one?
- Editing raw NettMail HTML in a plain textarea (for NettMail-backed types) is rough UX — acceptable for v1, or should those types be excluded from editing (subject-only edit) until there's a richer editor?
