<?php

namespace App\Modules\Core\Services;

use App\Ai\Agents\BankStatementExtractionAgent;
use App\Ai\Agents\BankTemplateHintsAgent;
use App\Ai\Agents\InvoiceExtractionAgent;
use App\Ai\Agents\PaymentNotificationExtractionAgent;
use App\Ai\Agents\PdfVisionExtractionAgent;
use App\Exceptions\LlmApiException;
use App\Exceptions\LlmCreditExhaustedException;
use App\Exceptions\LlmUnusableOutputException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Core\DTO\ExtractedBankStatement;
use App\Modules\Core\Models\Document;
use App\Modules\Core\Models\LlmLog;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\DTO\ExtractedInvoice;
use App\Modules\Purchasing\DTO\ExtractedPaymentNotification;
use App\Modules\Purchasing\Settings\PurchasingSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Exceptions\InsufficientCreditsException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Document as AiDocument;
use Laravel\Ai\Files\File;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

class LlmService
{
    public function __construct(
        private readonly CurrencySettings $currencySettings,
        private readonly PurchasingSettings $purchasingSettings,
        private readonly ModelHealthService $modelHealth,
        private readonly InvoiceExtractionAgent $invoiceAgent,
        private readonly BankStatementExtractionAgent $bankStatementAgent,
        private readonly PaymentNotificationExtractionAgent $paymentNotificationAgent,
        private readonly BankTemplateHintsAgent $bankTemplateHintsAgent,
        private readonly PdfVisionExtractionAgent $pdfVisionAgent,
    ) {}

    private const REQUEST_TIMEOUT = 110;

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
            $fast = $this->extractWith($prompt, config('ai.providers.anthropic.models.fast'), $loggable);
            $fastReconciled = $this->isReconciled($fast);

            if ($fastReconciled && $this->meetsConfidenceThreshold($fast)) {
                return $fast;
            }
        } catch (LlmCreditExhaustedException $e) {
            // Stop all processing until credits are added — never burn a
            // second billable call on the strong model for this reason.
            throw $e;
        } catch (LlmApiException|LlmUnusableOutputException) {
            // Fall through to the stronger model.
        }

        try {
            $strong = $this->extractWith($prompt, config('ai.providers.anthropic.models.strong'), $loggable);
        } catch (LlmCreditExhaustedException $e) {
            throw $e;
        } catch (LlmApiException|LlmUnusableOutputException $e) {
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
        $log = $this->callAgent(
            agent: $this->invoiceAgent,
            prompt: $prompt,
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
            $fast = $this->extractBankStatementWith($prompt, config('ai.providers.anthropic.models.fast'), $loggable);
            $fastReconciled = $fast->isBalanceReconciled();

            if ($fastReconciled && $fast->confidence >= $this->purchasingSettings->fallback_confidence) {
                return $fast;
            }
        } catch (LlmCreditExhaustedException $e) {
            throw $e;
        } catch (LlmApiException|LlmUnusableOutputException) {
            // Fall through to stronger model.
        }

        try {
            $strong = $this->extractBankStatementWith($prompt, config('ai.providers.anthropic.models.strong'), $loggable);
        } catch (LlmCreditExhaustedException $e) {
            throw $e;
        } catch (LlmApiException|LlmUnusableOutputException $e) {
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
        $log = $this->callAgent(
            agent: $this->bankStatementAgent,
            prompt: $prompt,
            loggable: $loggable,
            startNs: hrtime(true),
            model: $model,
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
            return $this->extractPaymentNotificationWith($prompt, config('ai.providers.anthropic.models.fast'), $loggable);
        } catch (LlmCreditExhaustedException $e) {
            throw $e;
        } catch (LlmApiException|LlmUnusableOutputException) {
            return $this->extractPaymentNotificationWith($prompt, config('ai.providers.anthropic.models.strong'), $loggable);
        }
    }

    private function extractPaymentNotificationWith(string $prompt, string $model, ?Model $loggable): ExtractedPaymentNotification
    {
        $log = $this->callAgent(
            agent: $this->paymentNotificationAgent,
            prompt: $prompt,
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
            $log = $this->callAgent(
                agent: $this->bankTemplateHintsAgent,
                prompt: $prompt,
                loggable: $loggable,
                startNs: hrtime(true),
                model: config('ai.providers.anthropic.models.strong'),
            );

            return $log->response_payload['text'] ?? '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Extract raw text from a scanned PDF using Claude's document vision.
     * Called by PdfExtractor when pdftotext yields insufficient text.
     */
    public function extractRawTextFromPdf(string $absolutePath, ?Model $loggable = null): string
    {
        $log = $this->callAgent(
            agent: $this->pdfVisionAgent,
            prompt: 'Extract all text from this PDF document.',
            loggable: $loggable,
            startNs: hrtime(true),
            model: config('ai.providers.anthropic.models.strong'),
            attachments: [AiDocument::fromPath($absolutePath)],
        );

        return $log->response_payload['text'];
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
     * Invoke the given agent through Anthropic and return the created log row
     * (whose response_payload holds the response text). Logs every call —
     * success or failure — to the llm_logs table.
     *
     * Escalates through ModelHealthService's live fallback ladder only when
     * Anthropic reports the requested model as retired/not found; any other
     * error fails fast rather than burning the ladder.
     *
     * @param  array<int, File>  $attachments
     */
    private function callAgent(Agent $agent, string $prompt, ?Model $loggable, int $startNs, string $model, array $attachments = []): LlmLog
    {
        $candidates = $this->modelHealth->escalationFrom($model);
        $lastError = "model `{$model}` is unavailable and has no live fallback";

        foreach ($candidates as $candidate) {
            $requestBody = ['model' => $candidate, 'prompt' => $prompt];

            try {
                /** @var AgentResponse $response */
                $response = $agent->prompt(
                    $prompt,
                    attachments: $attachments,
                    provider: 'anthropic',
                    model: $candidate,
                    timeout: self::REQUEST_TIMEOUT,
                );
            } catch (RequestException $e) {
                $error = $e->response?->json('error.message') ?? $e->getMessage();

                // Retired/mistyped model: mark it down, alert once, escalate a rung.
                if ($e->response?->status() === 404 && $e->response->json('error.type') === 'not_found_error') {
                    $this->modelHealth->recordUnavailable($candidate, $error);
                    $lastError = $error;

                    continue;
                }

                // Any other error (bad request, auth, server) is not a
                // retirement — fail fast rather than burning the fallback ladder.
                $this->log(loggable: $loggable, requestBody: $requestBody, rawResponse: '', startNs: $startNs, error: $error);
                throw new LlmApiException("Anthropic API error: {$error}");
            } catch (InsufficientCreditsException $e) {
                // Credit exhausted — stop all processing until credits are added.
                $error = $this->underlyingErrorMessage($e);
                Cache::forever('anthropic:credit_exhausted', [
                    'message' => $error,
                    'detected_at' => now()->toIso8601String(),
                ]);
                $this->log(loggable: $loggable, requestBody: $requestBody, rawResponse: '', startNs: $startNs, error: $error);
                throw new LlmCreditExhaustedException("Anthropic credit exhausted: {$error}");
            } catch (ProviderConnectionException $e) {
                // Transient — do not escalate the ladder for a network blip.
                $error = $this->underlyingErrorMessage($e);
                $this->log(loggable: $loggable, requestBody: $requestBody, rawResponse: '', startNs: $startNs, error: $error);
                throw new LlmApiException("Anthropic API connection error: {$error}", previous: $e);
            } catch (RateLimitedException|ProviderOverloadedException $e) {
                $error = $this->underlyingErrorMessage($e);
                $this->log(loggable: $loggable, requestBody: $requestBody, rawResponse: '', startNs: $startNs, error: $error);
                throw new LlmApiException("Anthropic API error: {$error}");
            }

            Cache::forget('anthropic:credit_exhausted');

            return $this->log(
                loggable: $loggable,
                requestBody: $requestBody,
                rawResponse: $response->text,
                startNs: $startNs,
                promptTokens: $response->usage->promptTokens,
                completionTokens: $response->usage->completionTokens,
            );
        }

        $this->log(loggable: $loggable, requestBody: ['model' => $model, 'prompt' => $prompt], rawResponse: '', startNs: $startNs, error: $lastError);
        throw new LlmApiException("Anthropic API error: {$lastError}");
    }

    /**
     * Failover exceptions carry a generic message; the original Anthropic error
     * detail lives on the wrapped RequestException, if there is one.
     */
    private function underlyingErrorMessage(Throwable $e): string
    {
        $previous = $e->getPrevious();

        if ($previous instanceof RequestException && $previous->response !== null) {
            return $previous->response->json('error.message') ?? $e->getMessage();
        }

        return $previous?->getMessage() ?? $e->getMessage();
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
            throw new LlmUnusableOutputException('LLM returned invalid JSON.');
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
            'model' => $requestBody['model'] ?? config('ai.providers.anthropic.models.strong'),
            'confidence' => null,
            'duration_ms' => $durationMs,
            'request_payload' => $requestBody,
            'response_payload' => $error ? null : ['text' => $rawResponse],
            'error' => $error,
        ]);
    }
}
