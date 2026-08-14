<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Domain\Money\Money;
use App\Enums\LegRole;

/**
 * A described flow — what entered or left, and between whom (Section 2).
 *
 * Kept alongside the entries rather than derived from them. One leg can produce
 * several entries — a delivered leg produces both a cash credit and a clearing debit —
 * so the human-readable version is not recoverable from the accounting one.
 */
final readonly class LegDraft
{
    public function __construct(
        public LegRole $role,
        public Money $amount,
        public int $currencyId,
        public ?int $accountId = null,
        public ?int $counterpartyId = null,
    ) {}
}
