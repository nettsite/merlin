### Invoice Processing Pipeline

`InvoiceProcessingService::process(Document $doc)` orchestrates:

1. **DocumentTextExtractor** — multi-format text extraction (PDF via PdfExtractor, DOCX/XLSX/CSV via `paperdoc-dev/paperdoc-lib`)
2. **MagikaService** — Rust CLI (`magika`) detects actual file type; rejects unsupported formats
3. **LlmService** — calls Anthropic via `laravel/ai`'s `InvoiceExtractionAgent` (`app/Ai/Agents/`, structured-output schema, prompt text still rendered from `resources/views/prompts/invoice-extraction.blade.php`); returns `ExtractedInvoice` DTO; logs tokens to `LlmLog`. Tries `ai.providers.anthropic.models.fast` (Haiku) first; falls back to a full retry on `ai.providers.anthropic.models.strong` (Sonnet) if the response is invalid JSON, the lines don't reconstruct the invoice `total` (grossing each line up by its effective tax rate — per-line, else the purchasing default — and comparing to `total`; also accepts VAT-inclusive lines that already sum to it), or confidence is below `PurchasingSettings::fallback_confidence` (Settings > Purchasing). Reconciliation is against `total` only (not header subtotal/tax) because VAT-inclusive invoices and shipping-outside-subtotal break header arithmetic. A reconciling fast result is kept over a non-reconciling strong one. The parsed confidence is persisted to the `LlmLog` row. This quality-based tier loop is hand-rolled in `LlmService` — `laravel/ai`'s own provider failover only fires on transient/error exceptions, it has no concept of "the output didn't reconcile, try a smarter model." **Separately, `ModelHealthService` provides a model-retirement fallback ladder** `ai.providers.anthropic.models.{fast,strong,backup}` (Haiku→Sonnet→Opus): a 404 `RequestException` from the SDK with `error.type === 'not_found_error'` marks the model down in cache (circuit breaker, `ai.down_ttl`), `ai.alert_recipients` are emailed once, and the call escalates to the next live rung — distinct from the quality fallback above (which retries on bad *output* from a working model). `ModelHealthService::probe()` (used by `models:health-check`, daily 05:30) stays on raw `Http::post()` rather than the SDK — it's an isolated diagnostic ping, not part of the extraction path. See [[project_llm_tiered_extraction]] and [[project_anthropic_model_pinning]] in memory for background on why this split exists
4. **SupplierResolver** — tax number match → name similarity → create pending supplier via `PartyService`
5. **AccountResolver** — history match → LLM suggestion → manual allocation fallback
6. **ExchangeRateService** — fetches rate from ExchangeRate-API (24h cache); env `EXCHANGERATE_API_KEY`
7. **PostingRuleService** — evaluates active rules; auto-posts if confidence/pattern thresholds met

`DocumentService` owns all status transitions and wraps `InvoiceProcessingService::process()` for reprocessing. **Reprocessing must delete existing lines first** — `process()` only appends.

### Document State Machine

Managed by `DocumentService::transition()`. Valid transitions:

```
received ──→ reviewed ──→ approved ──→ posted
    │            │            │
    └──→ approved└──→ posted  └──→ disputed
    │                              │
    └──→ posted                    └──→ reviewed / approved / posted / rejected
    │
    └──→ disputed / rejected
```

Full map (purchase invoices):
- `received` → reviewed, approved, posted, disputed, rejected
- `reviewed` → approved, posted, disputed
- `approved` → posted, disputed
- `disputed` → reviewed, approved, posted, rejected
- `posted` / `rejected` → (terminal)

Methods: `markAsReviewed()`, `approve()`, `post()`, `dispute()`, `reject()`, `reprocess()` (deletes lines, re-runs pipeline).
