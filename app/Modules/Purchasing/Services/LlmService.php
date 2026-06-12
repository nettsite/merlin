<?php

namespace App\Modules\Purchasing\Services;

use App\Exceptions\LlmApiException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Core\Settings\CurrencySettings;
use App\Modules\Purchasing\DTO\ExtractedInvoice;
use App\Modules\Purchasing\Models\LlmLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class LlmService
{
    public function __construct(
        private readonly CurrencySettings $currencySettings,
    ) {}

    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const MAX_TOKENS = 4096;

    /**
     * Extract structured invoice data from raw PDF text.
     *
     * @param  array<int, array<string, mixed>>  $supplierHistory
     */
    public function extractInvoice(string $invoiceText, array $supplierHistory = [], ?Model $loggable = null): ExtractedInvoice
    {
        $prompt = $this->buildExtractionPrompt($invoiceText, $supplierHistory);
        $start = hrtime(true);

        $raw = $this->callApi(
            messages: [['role' => 'user', 'content' => $prompt]],
            loggable: $loggable,
            startNs: $start,
        );

        return ExtractedInvoice::fromArray($this->parseJsonResponse($raw));
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

        return $this->callApi(messages: $messages, loggable: $loggable, startNs: $start);
    }

    private function buildExtractionPrompt(string $text, array $history): string
    {
        /** @var View $view */
        $view = view('prompts.invoice-extraction', [
            'invoice_text' => $text,
            'chart_of_accounts' => $this->getCoaForPrompt(),
            'supplier_history' => $history,
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
     * Send messages to the Anthropic API and return the text content of the response.
     * Logs every call — success or failure — to the llm_logs table.
     *
     * @param  array<int, array<string, mixed>>  $messages
     */
    private function callApi(array $messages, ?Model $loggable, int $startNs): string
    {
        $body = [
            'model' => config('services.anthropic.model'),
            'max_tokens' => self::MAX_TOKENS,
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

        if (! $response->successful() || ! isset($data['content'][0]['text'])) {
            $error = $data['error']['message'] ?? "HTTP {$response->status()}";
            $this->log(
                loggable: $loggable,
                requestBody: $body,
                rawResponse: '',
                startNs: $startNs,
                error: $error,
            );
            throw new LlmApiException("Anthropic API error: {$error}");
        }

        $text = $data['content'][0]['text'];
        $usage = $data['usage'] ?? [];

        $this->log(
            loggable: $loggable,
            requestBody: $body,
            rawResponse: $text,
            startNs: $startNs,
            promptTokens: $usage['input_tokens'] ?? 0,
            completionTokens: $usage['output_tokens'] ?? 0,
        );

        return $text;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseJsonResponse(string $raw): array
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
    ): void {
        $durationMs = (int) round((hrtime(true) - $startNs) / 1_000_000);

        LlmLog::create([
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
