### Configuration Files

| File | Key env vars / settings |
|---|---|
| `config/ai.php` | `laravel/ai` config. `default` = `AI_PROVIDER` (default `anthropic`). `providers.anthropic.models.{fast,strong,backup}` = the model-fallback ladder (`ANTHROPIC_MODEL_FAST`/`ANTHROPIC_MODEL`/`ANTHROPIC_MODEL_BACKUP`) — adding a second vendor (e.g. OpenAI) means adding a `providers.openai` block with its own `models` ladder, no code changes. `alert_recipients`/`down_ttl` feed `ModelHealthService`. `config/services.php`'s `anthropic.key`/`anthropic.model*` block is kept alongside this, unchanged, only for `ModelHealthService::probe()`'s raw diagnostic ping |
| `config/documents.php` | `INVOICE_WATCH_DIR`, `INVOICE_WATCH_INTERVAL`, `MAGIKA_BINARY`; document type prefixes & statuses |
| `config/currency.php` | `EXCHANGERATE_API_KEY`; cache TTL 86400s |
| `config/party.php` | business types, relationship types, contact roles, address types |
| `config/paperdoc.php` | paperdoc-lib settings |
| `config/nettmail.php` | `NETTMAIL_*` driver/SMTP/provider settings; `nettmail.layout` set to `layout.app` so NettMail admin pages (Templates, Contacts, Lists, Segments, Campaigns under "Emails" nav group) render inside Merlin's layout |

### NettMail Integration

`nettsite/nettmail-laravel` (Composer package, pinned version — bump deliberately) provides transactional + campaign email. Sales invoice emails are authored as Unlayer templates (not static Blade views) — `InvoiceEmailTemplateService` (`app/Modules/Billing/Services/`) renders the template selected via `BillingSettings::invoice_email_template_id` (Settings > Billing) with merge tags. `SalesInvoiceMail` consumes this rendered HTML. NettMail admin pages live under its own routes/nav group "Emails"; both `nav.blade.php` and `top-nav.blade.php` were updated to add this group (see [[feedback_dual_nav]] convention).
