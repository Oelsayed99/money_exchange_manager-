<?php

declare(strict_types=1);

namespace App\Domain\Rates;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reference quotes from open.er-api.com.
 *
 * Chosen because it needs no key, covers the currencies this business actually trades —
 * the Egyptian pound and the dirham are missing from the European Central Bank feeds
 * everything else wraps — and its terms permit this use. It publishes once a day.
 *
 * ## Never allowed to break a page
 *
 * A dashboard that fails because somebody else's server is down is a worse dashboard.
 * Every failure path here returns null: a timeout, a bad status, malformed JSON, a
 * missing key. The interface then simply does not show the strip.
 *
 * ## Why the digits are read as text
 *
 * `json_decode` turns `50.252612` into a floating-point number, and this application
 * does not put rates through floating point — not because a display figure would be
 * visibly wrong, but because the moment one exists, somebody eventually multiplies by
 * it. Reading the provider's own characters out of the body keeps that door shut.
 */
final readonly class ExchangeRateApi implements RateProvider
{
    private const CACHE_KEY = 'reference-rates';

    public function __construct(private Cache $cache) {}

    public function latest(): ?ReferenceRates
    {
        if (config('services.rates.enabled') !== true) {
            return null;
        }

        $base = strtoupper((string) config('services.rates.base', 'USD'));
        $ttl = (int) config('services.rates.ttl', 3600);

        /** @var array{base: string, rates: array<string, string>, updated_at: string}|null $cached */
        $cached = $this->cache->remember(
            self::CACHE_KEY.':'.$base,
            $ttl,
            fn (): ?array => $this->fetch($base),
        );

        if ($cached === null) {
            return null;
        }

        return new ReferenceRates(
            base: $cached['base'],
            rates: $cached['rates'],
            updatedAt: Carbon::parse($cached['updated_at']),
        );
    }

    /**
     * @return array{base: string, rates: array<string, string>, updated_at: string}|null
     */
    private function fetch(string $base): ?array
    {
        try {
            $url = rtrim((string) config('services.rates.url'), '/').'/'.$base;

            $response = Http::timeout((int) config('services.rates.timeout', 6))
                ->acceptJson()
                ->get($url);

            if ($response->failed()) {
                return null;
            }

            $body = $response->body();
            $decoded = json_decode($body, true);

            if (! is_array($decoded) || ($decoded['result'] ?? null) !== 'success') {
                return null;
            }

            $rates = $this->digits($body);

            if ($rates === []) {
                return null;
            }

            return [
                'base' => (string) ($decoded['base_code'] ?? $base),
                'rates' => $rates,
                'updated_at' => Carbon::parse((string) ($decoded['time_last_update_utc'] ?? 'now'))->toIso8601String(),
            ];
        } catch (Throwable $exception) {
            // Logged rather than raised: the dashboard's job is to show the ledger, and
            // somebody else's outage is not a reason to withhold it.
            Log::warning('Reference rates could not be fetched.', ['error' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * Pull each quote out of the response as the characters the provider sent.
     *
     * @return array<string, string>
     */
    private function digits(string $body): array
    {
        $start = strpos($body, '"rates"');

        if ($start === false) {
            return [];
        }

        preg_match_all('/"([A-Z]{3})"\s*:\s*(\d+(?:\.\d+)?)/', substr($body, $start), $matches, PREG_SET_ORDER);

        $rates = [];

        foreach ($matches as $match) {
            $rates[$match[1]] = $match[2];
        }

        return $rates;
    }
}
