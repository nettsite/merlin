<?php

namespace App\Modules\Purchasing\Services;

use App\Mail\ModelHealthAlertMail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Tracks Anthropic model availability and drives the retirement fallback ladder.
 *
 * The ladder is fast -> model -> backup (Haiku -> Sonnet -> Opus by default).
 * When a model returns not_found_error (retired or mistyped), it is marked
 * "down" in the cache, an alert email is sent once, and callers escalate to the
 * next live rung. The cache makes the decision sticky for `down_ttl` seconds so
 * subsequent extractions skip the dead rung outright — no repeat 404s, no email
 * storm — until a health check clears it or a human re-pins the config.
 */
class ModelHealthService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    private const DOWN_PREFIX = 'anthropic:model-down:';

    /**
     * Ordered fallback ladder, de-duplicated and stripped of empty rungs.
     *
     * @return list<string>
     */
    public function ladder(): array
    {
        return array_values(array_unique(array_filter([
            (string) config('services.anthropic.model_fast'),
            (string) config('services.anthropic.model'),
            (string) config('services.anthropic.model_backup'),
        ])));
    }

    /**
     * Parsed alert recipients.
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        $raw = (string) config('services.anthropic.alert_recipients');

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function isDown(string $model): bool
    {
        return Cache::has(self::DOWN_PREFIX.$model);
    }

    /**
     * The live escalation chain starting at $model's rung, skipping down rungs.
     * If $model isn't on the ladder it leads, followed by the ladder.
     *
     * @return list<string>
     */
    public function escalationFrom(string $model): array
    {
        $ladder = $this->ladder();
        $start = array_search($model, $ladder, true);

        $chain = $start === false
            ? array_merge([$model], $ladder)
            : array_slice($ladder, $start);

        return array_values(array_filter(
            array_unique($chain),
            fn (string $candidate): bool => ! $this->isDown($candidate),
        ));
    }

    /**
     * Mark a model unavailable and alert once on the transition to down.
     */
    public function recordUnavailable(string $model, string $reason): void
    {
        $ttl = (int) config('services.anthropic.down_ttl', 3600);
        $firstFailure = Cache::add(self::DOWN_PREFIX.$model, $reason, now()->addSeconds($ttl));

        Log::warning("Anthropic model unavailable: {$model} — {$reason}");

        if (! $firstFailure) {
            return;
        }

        // escalationFrom now skips the just-marked model, so its first entry is
        // the live successor (or none, if this was the last rung).
        $successor = $this->escalationFrom($model)[0] ?? null;

        $fallbackLine = $successor !== null
            ? "Now falling back to: {$successor}."
            : 'No fallback model is available — invoice extraction is FAILING.';

        $this->alert(
            "Merlin: Anthropic model `{$model}` unavailable",
            "Model `{$model}` returned not_found_error:\n  {$reason}\n\n".
            "{$fallbackLine}\n\n".
            'Choose a replacement and update ANTHROPIC_MODEL / ANTHROPIC_MODEL_FAST / '.
            'ANTHROPIC_MODEL_BACKUP in .env, then run `php artisan config:clear`.',
        );
    }

    public function clearDown(string $model): void
    {
        Cache::forget(self::DOWN_PREFIX.$model);
    }

    /**
     * Fire a 1-token probe at a model. Returns null when healthy, else the error.
     */
    public function probe(string $model): ?string
    {
        try {
            $response = Http::connectTimeout(10)
                ->timeout(30)
                ->withHeaders([
                    'x-api-key' => config('services.anthropic.key'),
                    'anthropic-version' => '2023-06-01',
                ])
                ->post(self::API_URL, [
                    'model' => $model,
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'ping']],
                ]);
        } catch (\Throwable $e) {
            return "connection error: {$e->getMessage()}";
        }

        if ($response->successful()) {
            return null;
        }

        return $response->json('error.message') ?? "HTTP {$response->status()}";
    }

    public function alert(string $subject, string $body): void
    {
        $recipients = $this->recipients();

        if ($recipients === []) {
            Log::error("Model health alert has no recipients; not sent: {$subject}");

            return;
        }

        Mail::to($recipients)->send(new ModelHealthAlertMail($subject, $body));
    }
}
