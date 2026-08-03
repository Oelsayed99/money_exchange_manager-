<?php

declare(strict_types=1);

namespace App\Domain\Money\Exceptions;

use DomainException;

/**
 * Thrown when an operation would have to discard a significant digit.
 *
 * Nothing in this system rounds. Where a result cannot be represented without losing
 * precision, that is surfaced as a failure rather than absorbed quietly — the one
 * exception being division, which cannot terminate and truncates by documented design.
 */
final class PrecisionLoss extends DomainException
{
    public static function inMultiplication(string $amount, string $factor, string $product, int $scale): self
    {
        return new self(
            "Multiplying [{$amount}] by [{$factor}] gives [{$product}], which needs more than "
            ."{$scale} decimal places. Nothing here rounds, so the result cannot be stored "
            .'without discarding a significant digit. Use a less precise factor, or divide '
            .'explicitly if truncation is acceptable.'
        );
    }
}
