<?php

namespace App\Modules\Purchasing\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ExchangeRateService
{
    public function __construct(private readonly string $baseCurrency) {}

    /**
     * Returns the display symbol for a currency code.
     * e.g. 'USD' → '$', 'GBP' → '£', 'ZAR' → 'R'.
     * Falls back to the ISO code if the symbol cannot be determined.
     */
    public static function currencySymbol(string $currency): string
    {
        $code = strtoupper($currency);

        $fmt = new \NumberFormatter('en@currency='.$code, \NumberFormatter::CURRENCY);
        $symbol = $fmt->getSymbol(\NumberFormatter::CURRENCY_SYMBOL);

        // intl returns the ISO code itself for ZAR with the 'en' locale
        return ($symbol !== $code) ? $symbol : match ($code) {
            'ZAR' => 'R',
            default => $code,
        };
    }

    /**
     * Returns the number of base-currency units per 1 unit of $currency.
     * e.g. getRate('USD') returns ~18.35 when 1 USD = 18.35 ZAR.
     * Returns 1.0 when $currency equals the base currency.
     *
     * @throws RuntimeException for unknown currency codes or API failures
     */
    public function getRate(string $currency): float
    {
        $code = strtoupper($currency);

        if ($code === strtoupper($this->baseCurrency)) {
            return 1.0;
        }

        $rates = $this->fetchRates();

        if (! isset($rates[$code])) {
            throw new RuntimeException("Unknown currency code: {$code}");
        }

        // API gives: 1 ZAR = X {currency}
        // We want:   1 {currency} = Y ZAR  →  Y = 1 / X
        return round(1 / $rates[$code], 6);
    }

    /**
     * Returns all supported ISO currency codes.
     *
     * @return array<int, string>
     */
    public function supportedCurrencies(): array
    {
        return array_keys($this->fetchRates());
    }

    /**
     * Fetches and caches the full conversion_rates array from the provider.
     * Values are "1 base = X {currency}" as returned by the API.
     *
     * @return array<string, float>
     */
    private function fetchRates(): array
    {
        $base = strtoupper($this->baseCurrency);

        return Cache::remember(
            "exchange_rates_{$base}",
            config('currency.cache_ttl', 86400),
            function () use ($base): array {
                $key = config('currency.providers.exchangerate_api.key');
                $url = config('currency.providers.exchangerate_api.url');

                $response = Http::get("{$url}/{$key}/latest/{$base}");

                if (! $response->successful() || $response->json('result') !== 'success') {
                    throw new RuntimeException(
                        "Exchange rate fetch failed: {$response->body()}"
                    );
                }

                return $response->json('conversion_rates');
            }
        );
    }
}
