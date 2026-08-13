<?php

declare(strict_types=1);

namespace App\Domain\Money;

use App\Models\Currency;
use RuntimeException;

/**
 * In-memory lookup of currency specifications.
 *
 * Every monetary column needs its currency's precision to be read or written, and a
 * naive cast would issue a query per row. Currencies are few, change rarely, and are
 * needed constantly, so the whole set is loaded once per request and held.
 *
 * Registered as a singleton. Deliberately not a persistent cache: an administrator
 * changing a currency's precision must take effect on the next request, not whenever
 * a cache happens to expire.
 */
final class CurrencyRegistry
{
    /** @var array<int, CurrencySpec>|null */
    private ?array $byId = null;

    /** @var array<string, CurrencySpec>|null */
    private ?array $byCode = null;

    public function byId(int $currencyId): CurrencySpec
    {
        $this->load();

        return $this->byId[$currencyId]
            ?? throw new RuntimeException("No currency with id [{$currencyId}].");
    }

    public function byCode(string $code): CurrencySpec
    {
        $this->load();

        $code = strtoupper(trim($code));

        return $this->byCode[$code]
            ?? throw new RuntimeException("No currency with code [{$code}].");
    }

    public function has(int $currencyId): bool
    {
        $this->load();

        return isset($this->byId[$currencyId]);
    }

    /** Drop the memo. Needed after seeding or when a test alters currencies mid-run. */
    public function flush(): void
    {
        $this->byId = null;
        $this->byCode = null;
    }

    private function load(): void
    {
        if ($this->byId !== null) {
            return;
        }

        $this->byId = [];
        $this->byCode = [];

        foreach (Currency::query()->get() as $currency) {
            $spec = $currency->spec();

            $this->byId[$currency->id] = $spec;
            $this->byCode[$spec->code] = $spec;
        }
    }
}
