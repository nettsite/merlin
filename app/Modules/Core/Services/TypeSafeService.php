<?php

namespace App\Modules\Core\Services;

use App\Modules\Core\DTO\TypeSafeChoiceResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin client for TypeSafe AI's System One evaluation endpoint (Jev).
 *
 * Only wraps the `choice` primitive for now — a closed-list classification
 * judgment (pick 1 of N candidates), which is the shape needed by
 * SupplierResolver's experimental Jev tier. Returns null on any failure
 * (network, auth, rate limit exhausted) so callers can fall through to their
 * existing heuristic/manual path rather than breaking the pipeline.
 */
class TypeSafeService
{
    private const ENDPOINT = '/v1/systemone';

    /**
     * @param  string|array<mixed>  $state  The material to judge.
     * @param  array<string, string|null>  $criteria  Option => rubric description (null for none). Max 255 options.
     */
    public function choice(string|array $state, string $instructions, array $criteria): ?TypeSafeChoiceResult
    {
        $key = config('services.typesafe.key');

        if (! $key) {
            Log::warning('TypeSafe choice skipped: services.typesafe.key is not configured.');

            return null;
        }

        try {
            $response = Http::withToken($key)
                ->timeout(15)
                ->retry(3, 500, fn ($exception, $request) => $request->response?->status() === 429
                    || $request->response?->status() === 529)
                ->post(rtrim((string) config('services.typesafe.base_url'), '/').self::ENDPOINT, [
                    'state' => $state,
                    'model' => config('services.typesafe.model'),
                    'questions' => [
                        'pick' => [
                            'type' => 'choice',
                            'instructions' => $instructions,
                            'criteria' => $criteria,
                        ],
                    ],
                ]);
        } catch (\Throwable $e) {
            Log::warning("TypeSafe choice request failed: {$e->getMessage()}");

            return null;
        }

        if (! $response->successful()) {
            Log::warning("TypeSafe choice returned HTTP {$response->status()}: {$response->body()}");

            return null;
        }

        $answer = $response->json('answers.pick');

        if (! is_array($answer)) {
            Log::warning('TypeSafe choice response missing answers.pick.');

            return null;
        }

        return TypeSafeChoiceResult::fromApiResponse($answer);
    }
}
