<?php

use App\Modules\Purchasing\Services\ExchangeRateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    Cache::flush();
    $this->service = app(ExchangeRateService::class);
});

// Minimal conversion_rates payload for faking API responses
function fakeRates(array $extra = []): array
{
    return [
        'result' => 'success',
        'base_code' => config('currency.base', 'ZAR'),
        'conversion_rates' => array_merge([
            config('currency.base', 'ZAR') => 1.0,
            'USD' => 0.054674,   // 1 ZAR = 0.054674 USD  →  1 USD ≈ 18.29 ZAR
            'GBP' => 0.043210,
            'EUR' => 0.050800,
        ], $extra),
    ];
}

it('returns 1.0 for the base currency', function (): void {
    // No HTTP call needed — base currency is short-circuited
    $rate = $this->service->getRate(config('currency.base', 'ZAR'));

    expect($rate)->toBe(1.0);
});

it('returns the correct inverted rate for USD', function (): void {
    Http::fake(['*' => Http::response(fakeRates(), 200)]);

    $rate = $this->service->getRate('USD');

    // 1 / 0.054674 ≈ 18.291 (rounded to 6 decimal places)
    expect($rate)->toBeFloat()
        ->and(round($rate, 3))->toBe(round(1 / 0.054674, 3));
});

it('accepts currency codes case-insensitively', function (): void {
    Http::fake(['*' => Http::response(fakeRates(), 200)]);

    $upper = $this->service->getRate('USD');
    Cache::flush();
    Http::fake(['*' => Http::response(fakeRates(), 200)]);
    $lower = $this->service->getRate('usd');

    expect($upper)->toBe($lower);
});

it('throws for an unknown currency code', function (): void {
    Http::fake(['*' => Http::response(fakeRates(), 200)]);

    expect(fn () => $this->service->getRate('XXX'))
        ->toThrow(RuntimeException::class, 'Unknown currency code: XXX');
});

it('throws when the API returns a failure result', function (): void {
    Http::fake(['*' => Http::response(['result' => 'error', 'error-type' => 'invalid-key'], 200)]);

    expect(fn () => $this->service->getRate('USD'))
        ->toThrow(RuntimeException::class, 'Exchange rate fetch failed');
});

it('caches results and does not call the API twice', function (): void {
    Http::fake(['*' => Http::response(fakeRates(), 200)]);

    $this->service->getRate('USD');
    $this->service->getRate('GBP');  // second call, different currency — still same cache entry

    Http::assertSentCount(1);
});

it('returns all supported currency codes', function (): void {
    Http::fake(['*' => Http::response(fakeRates(), 200)]);

    $currencies = $this->service->supportedCurrencies();

    expect($currencies)->toContain('USD', 'GBP', 'EUR', 'ZAR');
});
