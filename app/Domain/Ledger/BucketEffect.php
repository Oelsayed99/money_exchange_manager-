<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use App\Enums\BalanceBucket;

/**
 * What a transaction type does to one of a counterparty's four positions.
 *
 * Declared on the type so a form can say what a movement is about to do before it
 * does it. Declaring it separately from {@see PostingRules} risks the two drifting
 * apart, which is why `MovementEffectTest` posts every recordable type and checks the
 * ledger actually moved the bucket this claims, in the direction claimed.
 */
final readonly class BucketEffect
{
    public function __construct(
        public BalanceBucket $bucket,
        /** True when the position grows: more owed, more held. */
        public bool $increases,
    ) {}
}
