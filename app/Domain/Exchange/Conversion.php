<?php

declare(strict_types=1);

namespace App\Domain\Exchange;

use App\Domain\Money\Money;
use JsonSerializable;

/**
 * One side of a deal, worked out from the other side and a rate.
 *
 * The exactness flag is the whole reason this is not just a Money. A converted amount
 * that came out even is a fact; one that had to be truncated is a proposal, and the
 * operator needs to be told which they are looking at before they agree a figure with
 * somebody.
 */
final readonly class Conversion implements JsonSerializable
{
    public function __construct(
        public Money $amount,
        public bool $exact,
    ) {}

    /**
     * Serialised for the live calculation in the exchange form.
     *
     * The amount goes across as a string, like every other amount that crosses this
     * boundary: JSON numbers become float64 in the browser, and a float64 cannot hold
     * the figures this application exists to keep exact.
     *
     * @return array{amount: array{amount: string, currency: string}, exact: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'amount' => $this->amount->jsonSerialize(),
            'exact' => $this->exact,
        ];
    }
}
