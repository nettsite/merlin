<?php

namespace App\Modules\Core\Services;

use App\Exceptions\LlmApiException;
use App\Exceptions\LlmCreditExhaustedException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Core\DTO\ExtractedBankStatement;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\LlmLog;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\DTO\ExtractedInvoice;
use App\Modules\Purchasing\DTO\ExtractedPaymentNotification;
use App\Modules\Purchasing\Settings\PurchasingSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class LlmService
{
    public function __construct(
        private readonly CurrencySettings $currencySettings,
        private readonly PurchasingSettings $purchasingSettings,
        private readonly ModelHealthService $modelHealth,
    ) {}

    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const MAX_TOKENS = 4096;

    private const MAX_TOKENS_BANK_STATEMENT = 8192;

    /**
     * Extract structured invoice data from raw PDF text.
     *
     * Tries the fast model first; if its result is invalid JSON, its totals
     * don't reconcile, or its confidence is below the configured threshold,
     * retries the whole extraction on the configured (stronger) model.
     *
     * @param  array<int, array<string, mixed>>  $supplierHistory
     */
    public function extractInvoice(string $invoiceText, array $supplierHistory = [], ?Model $loggable = null, ?string $supplierPaymentNotes = null): ExtractedInvoice
    {
        $prompt = $this->buildExtractionPrompt($invoiceText, $supplierHistory, $supplierPaymentNotes);

        $fast = null;
        $fastReconciled = false;

        try {
            $fast = $this->extractWith($prompt, config('services.anthropic.model_fast'), $loggable);
            $fastReconciled = $this->isReconciled($fast);

            if ($fastReconciled && $this->meetsConfidenceThreshold($fast)) {
                return $fast;
            }
        } catch (LlmApiException|\RuntimeException) {
            // Fall through to the stronger model.
        }

        try {
            $strong = $this->extractWith($prompt, config('services.anthropic.model'), $loggable);
        } catch (LlmApiException|\RuntimeException $e) {
            // The strong model failed outright; keep a usable fast result if we have one.
            if ($fast !== null) {
                return $fast;
            }

            throw $e;
        }

        // A reconciling fast result (rejected only for low confidence) beats a
        // strong result that doesn't reconcile — e.g. when the fast model captured
        // a shipping line the strong model dropped.
        if ($fastReconciled && ! $this->isReconciled($strong)) {
            return $fast;
        }

        return $strong;
    }

    /**
     * Call the given model, parse the structured result, and record the parsed
     * confidence on the just-created log row. Throws on API or JSON errors.
     */
    private function extractWith(string $prompt, string $model, ?Model $loggable): ExtractedInvoice
    {
        $log = $this->callApi(
            messages: [['role' => 'user', 'content' => $prompt]],
            loggable: $loggable,
            startNs: hrtime(true),
            model: $model,
        );

        $extracted = ExtractedInvoice::fromArray($this->parseJsonResponse($log->response_payload['text']));

        $log->update(['confidence' => $extracted->confidence]);

        return $extracted;
    }

    private function meetsConfidenceThreshold(ExtractedInvoice $extracted): bool
    {
        return $extracted->confidence >= $this->purchasingSettings->fallback_confidence;
    }

    /**
     * Check the extracted lines reconstruct the invoice total before accepting
     * the fast model's result. The total (amount payable) is the one unambiguous
     * figure on an invoice — header subtotal/tax fields vary by presentation
     * (ex-VAT vs VAT-inclusive, shipping in or out of the subtotal), so we
     * reconcile against the total rather than the header arithmetic.
     *
     * Lines may arrive in either shape, so we accept whichever reconstructs the
     * total: ex-VAT line totals grossed up by their effective tax rate (per-line
     * rate, or the purchasing default when the header shows tax — mirroring how
     * InvoiceProcessingService taxes them), or VAT-inclusive line totals that
     * already sum to it.
     */
    private function isReconciled(ExtractedInvoice $extracted): bool
    {
        if ($extracted->lines === []) {
            return false;
        }

        $headerHasTax = $extracted->taxTotal > 0;
        $defaultRate = $this->purchasingSettings->tax_default_rate;

        $netSum = 0.0;
        $grossSum = 0.0;

        foreach ($extracted->lines as $line) {
            $rate = $line->taxRate ?? ($headerHasTax ? $defaultRate : 0.0);
            $netSum += $line->lineTotal;
            $grossSum += $line->lineTotal * (1 + $rate / 100);
        }

        return $this->withinTolerance($grossSum, $extracted->total)
            || $this->withinTolerance($netSum, $extracted->total);
    }

    private function withinTolerance(float $actual, float $expected): bool
    {
        return abs($actual - $expected) <= max(abs($expected) * 0.02, 0.05);
    }

    /**
     * Extract structured bank statement data from raw text.
     *
     * Tries the fast model first; falls back to the strong model if the balance
     * doesn't reconcile (net movements ≠ closing − opening) or confidence is low.
     */
    public function extractBankStatement(string $statementText, ?string $layoutHints = null, ?string $userHint = null, ?Model $loggable = null): ExtractedBankStatement
    {
        $prompt = $this->buildBankStatementPrompt($statementText, $layoutHints, $userHint);

        $fast = null;
        $fastReconciled = false;

        try {
            $fast = $this->extractBankStatementWith($prompt, config('services.anthropic.model_fast'), $loggable);
            $fastReconciled = $fast->isBalanceReconciled();

            if ($fastReconciled && $fast->confidence >= $this->purchasingSettings->fallback_confidence) {
                return $fast;
            }
        } catch (LlmApiException|\RuntimeException) {
            // Fall through to stronger model.
        }

        try {
            $strong = $this->extractBankStatementWith($prompt, config('services.anthropic.model'), $loggable);
        } catch (LlmApiException|\RuntimeException $e) {
            if ($fast !== null) {
                return $fast;
            }

            throw $e;
        }

        if ($fastReconciled && ! $strong->isBalanceReconciled()) {
            return $fast;
        }

        return $strong;
    }

    private function extractBankStatementWith(string $prompt, string $model, ?Model $loggable): ExtractedBankStatement
    {
        $log = $this->callApi(
            messages: [['role' => 'user', 'content' => $prompt]],
            loggable: $loggable,
            startNs: hrtime(true),
            model: $model,
            maxTokens: self::MAX_TOKENS_BANK_STATEMENT,
        );

        $extracted = ExtractedBankStatement::fromArray($this->parseJsonResponse($log->response_payload['text']));

        $log->update(['confidence' => $extracted->confidence]);

        return $extracted;
    }

    private function buildBankStatementPrompt(string $text, ?string $layoutHints, ?string $userHint = null): string
    {
        return view('prompts.bank-statement-extraction', [
            'statement_text' => $text,
            'chart_of_accounts' => $this->getCoaAllForPrompt(),
            'outstanding_invoices' => $this->getOutstandingInvoicesForPrompt(),
            'base_currency' => strtoupper($this->currencySettings->base_currency),
            'layout_hints' => $layoutHints,
            'user_hint' => $userHint,
        ])->render();
    }

    /**
     * Extract structured payment details from a payment notification (PayPal
     * receipt, FNB Connect email, EFT confirmation, etc).
     *
     * Unlike invoice/bank-statement extraction there is no reconciliation
     * check to gate on — these documents are short and unambiguous — so this
     * only falls back to the strong model if the fast model call fails
     * outright (bad JSON or API error).
     */
    public function extractPaymentNotification(string $text, ?Model $loggable = null): ExtractedPaymentNotification
    {
        $prompt = $this->buildPaymentNotificationPrompt($text);

        try {
            return $this->extractPaymentNotificationWith($prompt, config('services.anthropic.model_fast'), $loggable);
        } catch (LlmApiException|\RuntimeException) {
            return $this->extractPaymentNotificationWith($prompt, config('services.anthropic.model'), $loggable);
        }
    }

    private function extractPaymentNotificationWith(string $prompt, string $model, ?Model $loggable): ExtractedPaymentNotification
    {
        $log = $this->callApi(
            messages: [['role' => 'user', 'content' => $prompt]],
            loggable: $loggable,
            startNs: hrtime(true),
            model: $model,
        );

        $extracted = ExtractedPaymentNotification::fromArray($this->parseJsonResponse($log->response_payload['text']));

        $log->update(['confidence' => $extracted->confidence]);

        return $extracted;
    }

    private function buildPaymentNotificationPrompt(string $text): string
    {
        return view('prompts.payment-notification-extraction', [
            'text' => $text,
            'base_currency' => strtoupper($this->currencySettings->base_currency),
        ])->render();
    }

    private function getCoaAllForPrompt(): string
    {
        return Account::active()->postable()
            ->orderBy('code')
            ->get()
            ->map(fn (Account $a) => "{$a->code} — {$a->name}")
            ->implode("\n");
    }

    private function getOutstandingInvoicesForPrompt(): string
    {
        $invoices = Document::salesInvoices()
            ->whereIn('status', ['sent', 'partially_paid'])
            ->where('balance_due', '>', 0)
            ->with('party.business', 'party.person')
            ->orderByDesc('issue_date')
            ->get();

        if ($invoices->isEmpty()) {
            return '(none)';
        }

        return $invoices->map(function (Document $inv): string {
            $client = $inv->party?->displayName ?? 'Unknown';

            return implode(' | ', array_filter([
                $inv->document_number,
                $client,
                "total {$inv->currency} {$inv->total}",
                "balance_due {$inv->currency} {$inv->balance_due}",
                "issued {$inv->issue_date?->toDateString()}",
            ]));
        })->implode("\n");
    }

    /**
     * Generate plain-text layout hints for a bank template from a successful extraction.
     * Returns the raw text from the LLM (not JSON). Empty string on failure.
     */
    public function generateBankTemplateHints(
        string $bankName,
        string $statementText,
        ExtractedBankStatement $extracted,
        ?string $existingHints,
        ?string $userHint,
        ?Model $loggable = null,
    ): string {
        $sampleTransactions = array_slice(
            array_map(fn ($t) => [
                'transaction_date' => $t->transactionDate,
                'description' => $t->description,
                'debit' => $t->debit,
                'credit' => $t->credit,
                'running_balance' => $t->runningBalance,
            ], $extracted->transactions),
            0,
            10,
        );

        $prompt = view('prompts.bank-template-hints', [
            'bank_name' => $bankName,
            'existing_hints' => $existingHints,
            'user_hint' => $userHint,
            'balance_reconciled' => $extracted->isBalanceReconciled(),
            'transaction_count' => count($extracted->transactions),
            'period_from' => $extracted->periodFrom,
            'period_to' => $extracted->periodTo,
            'sample_transactions' => $sampleTransactions,
            'statement_excerpt' => mb_substr($statementText, 0, 3000),
        ])->render();

        try {
            $log = $this->callApi(
                messages: [['role' => 'user', 'content' => $prompt]],
                loggable: $loggable,
                startNs: hrtime(true),
                model: config('services.anthropic.model'),
            );

            return $log->response_payload['text'] ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Extract raw text from a scanned PDF using Claude's document vision.
     * Called by PdfExtractor when pdftotext yields insufficient text.
     */
    public function extractRawTextFromPdf(string $absolutePath, ?Model $loggable = null): string
    {
        $start = hrtime(true);

        $pdfBase64 = base64_encode((string) file_get_contents($absolutePath));

        $messages = [[
            'role' => 'user',
            'content' => [
                [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => 'application/pdf',
                        'data' => $pdfBase64,
                    ],
                ],
                [
                    'type' => 'text',
                    'text' => 'Extract all text from this PDF document. Return only the extracted text, preserving the layout as best as possible. Include all numbers, dates, company names, line items, and totals. Do not summarize or interpret — just extract the text exactly as it appears.',
                ],
            ],
        ]];

        return $this->callApi(messages: $messages, loggable: $loggable, startNs: $start, model: config('services.anthropic.model'))
            ->response_payload['text'];
    }

    private function buildExtractionPrompt(string $text, array $history, ?string $supplierPaymentNotes = null): string
    {
        /** @var View $view */
        $view = view('prompts.invoice-extraction', [
            'invoice_text' => $text,
            'chart_of_accounts' => $this->getCoaForPrompt(),
            'supplier_history' => $history,
            'supplier_payment_notes' => $supplierPaymentNotes,
            'base_currency' => strtoupper($this->currencySettings->base_currency),
        ]);

        return $view->render();
    }

    private function getCoaForPrompt(): string
    {
        return Account::active()->postable()->expenses()
            ->orderBy('code')
            ->get()
            ->map(fn (Account $a) => "{$a->code} — {$a->name}")
            ->implode("\n");
    }

    /**
     * Send messages to the Anthropic API and return the created log row (whose
     * response_payload holds the response text). Logs every call — success or
     * failure — to the llm_logs table.
     *
     * @param  array<int, array<string, mixed>>  $messages
     */
    public function callApi(array $messages, ?Model $loggable, int $startNs, string $model, int $maxTokens = self::MAX_TOKENS): LlmLog
    {
        // Resolve the requested tier to its live escalation chain: the model
        // itself plus the fallback rungs below it, with any retired rung skipped.
        $candidates = $this->modelHealth->escalationFrom($model);
        $lastError = "model `{$model}` is unavailable and has no live fallback";

        foreach ($candidates as $candidate) {
            $body = [
                'model' => $candidate,
                'max_tokens' => $maxTokens,
                'messages' => $messages,
            ];

            try {
                // 110s keeps the HTTP client inside the queue job's 120s budget;
                // vision extraction of large PDFs can far exceed the 30s default.
                $response = Http::connectTimeout(10)
                    ->timeout(110)
                    ->withHeaders([
                        'x-api-key' => config('services.anthropic.key'),
                        'anthropic-version' => '2023-06-01',
                        'anthropic-beta' => 'pdfs-2024-09-25',
                    ])->post(self::API_URL, $body);
            } catch (ConnectionException $e) {
                // Transient — do not escalate the ladder for a network blip.
                $this->log(
                    loggable: $loggable,
                    requestBody: $body,
                    rawResponse: '',
                    startNs: $startNs,
                    error: $e->getMessage(),
                );
                throw new LlmApiException("Anthropic API connection error: {$e->getMessage()}", previous: $e);
            }

            $data = $response->json();

            if ($response->successful() && isset($data['content'][0]['text'])) {
                $usage = $data['usage'] ?? [];

                Cache::forget('anthropic:credit_exhausted');

                return $this->log(
                    loggable: $loggable,
                    requestBody: $body,
                    rawResponse: $data['content'][0]['text'],
                    startNs: $startNs,
                    promptTokens: $usage['input_tokens'] ?? 0,
                    completionTokens: $usage['output_tokens'] ?? 0,
                );
            }

            $error = $data['error']['message'] ?? "HTTP {$response->status()}";

            // Retired/mistyped model: mark it down, alert once, escalate a rung.
            if ($response->status() === 404 && ($data['error']['type'] ?? null) === 'not_found_error') {
                $this->modelHealth->recordUnavailable($candidate, $error);
                $lastError = $error;

                continue;
            }

            // Credit exhausted — stop all processing until credits are added.
            if (str_contains($error, 'credit balance is too low')) {
                Cache::forever('anthropic:credit_exhausted', [
                    'message' => $error,
                    'detected_at' => now()->toIso8601String(),
                ]);
                $this->log(
                    loggable: $loggable,
                    requestBody: $body,
                    rawResponse: '',
                    startNs: $startNs,
                    error: $error,
                );
                throw new LlmCreditExhaustedException("Anthropic credit exhausted: {$error}");
            }

            // Any other error (rate limit, server, bad request) is not a
            // retirement — fail fast rather than burning the fallback ladder.
            $this->log(
                loggable: $loggable,
                requestBody: $body,
                rawResponse: '',
                startNs: $startNs,
                error: $error,
            );
            throw new LlmApiException("Anthropic API error: {$error}");
        }

        $this->log(
            loggable: $loggable,
            requestBody: ['model' => $model, 'max_tokens' => self::MAX_TOKENS, 'messages' => $messages],
            rawResponse: '',
            startNs: $startNs,
            error: $lastError,
        );
        throw new LlmApiException("Anthropic API error: {$lastError}");
    }

    /**
     * @return array<string, mixed>
     */
    public function parseJsonResponse(string $raw): array
    {
        // Strip markdown code fences the LLM sometimes wraps around JSON output.
        $clean = preg_replace('/^```(?:json)?\s*/i', '', trim($raw));
        $clean = preg_replace('/\s*```$/', '', (string) $clean);

        $data = json_decode(trim((string) $clean), true);

        if (! is_array($data)) {
            Log::debug('LlmService: invalid JSON response', ['raw' => substr($raw, 0, 500)]);
            throw new \RuntimeException('LLM returned invalid JSON.');
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $requestBody
     */
    private function log(
        ?Model $loggable,
        array $requestBody,
        string $rawResponse,
        int $startNs,
        int $promptTokens = 0,
        int $completionTokens = 0,
        ?string $error = null,
    ): LlmLog {
        $durationMs = (int) round((hrtime(true) - $startNs) / 1_000_000);

        return LlmLog::create([
            'loggable_type' => $loggable?->getMorphClass(),
            'loggable_id' => $loggable?->getKey(),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'model' => $requestBody['model'] ?? config('services.anthropic.model'),
            'confidence' => null,
            'duration_ms' => $durationMs,
            'request_payload' => $this->withoutBase64Sources($requestBody),
            'response_payload' => $error ? null : ['text' => $rawResponse],
            'error' => $error,
        ]);
    }

    /**
     * Replace base64 document/image sources with a size placeholder so vision
     * calls don't persist multi-MB encoded files into llm_logs.
     *
     * @param  array<string, mixed>  $requestBody
     * @return array<string, mixed>
     */
    private function withoutBase64Sources(array $requestBody): array
    {
        foreach ($requestBody['messages'] ?? [] as $i => $message) {
            if (! is_array($message['content'] ?? null)) {
                continue;
            }

            foreach ($message['content'] as $j => $block) {
                $data = $block['source']['data'] ?? null;

                if (is_string($data)) {
                    $requestBody['messages'][$i]['content'][$j]['source']['data'] =
                        '[base64 omitted: '.strlen($data).' bytes]';
                }
            }
        }

        return $requestBody;
    }
}
