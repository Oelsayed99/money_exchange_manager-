<?php

declare(strict_types=1);

namespace App\Domain\Rates;

use App\Domain\Money\Money;
use Illuminate\Support\Carbon;

/**
 * What the market was quoting, for reference only.
 *
 * ## These are not money and never become money
 *
 * Nothing here is a {@see Money}, nothing here is ever multiplied by
 * an amount, and no ledger entry has ever been derived from one. They exist so the
 * person at the counter can see where the market is before they agree a rate — the rate
 * they then agree is typed by hand, and what the ledger records is the two amounts that
 * actually moved.
 *
 * That separation is the whole reason a deal recorded in June cannot change value in
 * December, and `RatesStayOutOfTheLedgerTest` is what keeps it true.
 *
 * ## They are daily, not live
 *
 * The free source updates once a day. For a business that quotes intraday this is a
 * reference point, not a price — which is why the interface prints when it was last
 * updated rather than implying it is current.
 */
final readonly class ReferenceRates
{
    /**
     * @param  array<string, string>  $rates  currency code => units per one of the base,
     *                                        as the provider's own digits, never parsed
     *                                        into a floating-point number
     */
    public function __construct(
        public string $base,
        public array $rates,
        public Carbon $updatedAt,
    ) {}

    /**
     * @param  list<string>  $codes
     * @return list<array{code: string, rate: string}> in the order asked for
     */
    public function forCodes(array $codes): array
    {
        $quoted = [];

        foreach ($codes as $code) {
            if ($code !== $this->base && isset($this->rates[$code])) {
                $quoted[] = ['code' => $code, 'rate' => $this->rates[$code]];
            }
        }

        return $quoted;
    }
}
