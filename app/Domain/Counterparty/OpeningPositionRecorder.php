<?php

declare(strict_types=1);

namespace App\Domain\Counterparty;

use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\TransactionInput;
use App\Domain\Money\Money;
use App\Enums\TransactionType;
use App\Models\Counterparty;
use App\Models\CounterpartyOpeningBalance;
use App\Models\Currency;
use App\Models\Transaction;
use DateTimeInterface;

/**
 * Puts a declared opening position into the ledger.
 *
 * One signed figure per party per currency: positive means they owed us on day one,
 * negative that we were already holding money of theirs.
 *
 * ## What happens when a figure changes
 *
 * The ledger cannot un-post. So a change is not an edit; it is a second transaction for
 * the difference, dated when the change was made:
 *
 *   declared 900,000, nothing posted   →  post 900,000
 *   raised to 950,000                  →  post 50,000 more
 *   lowered to 800,000                 →  post 150,000 the other way
 *   removed                            →  post 800,000 the other way, then forget it
 *
 * Both transactions stay. Somebody reading the trail sees the figure was raised, when,
 * and by whom — which is the whole reason for asking.
 *
 * `posted_amount` is what makes this possible: without it, raising a figure from
 * 900,000 to 950,000 is indistinguishable from posting 950,000 for the first time.
 */
final readonly class OpeningPositionRecorder
{
    public function __construct(
        private PostingRules $rules,
        private PostingService $posting,
    ) {}

    /**
     * Bring a party's declared positions into line with the form, posting the changes.
     *
     * @param  list<array{currency_id: int|string, amount: string}>  $rows
     * @return list<Transaction> what was posted, in the order it was posted
     */
    public function sync(Counterparty $party, array $rows, DateTimeInterface $at): array
    {
        $posted = [];
        $keep = [];

        foreach ($rows as $row) {
            $currency = Currency::query()->findOrFail((int) $row['currency_id']);

            $position = $party->openingBalances()->firstOrNew(['currency_id' => $currency->getKey()]);
            $transaction = $this->settle($party, $position, $currency, $currency->money($row['amount']), $at);

            if ($transaction instanceof Transaction) {
                $posted[] = $transaction;
            }

            $keep[] = $position->getKey();
        }

        // Removed from the form means the position is gone, which the ledger has to be
        // told: it is unwound to zero and only then is the row forgotten.
        $removed = $party->openingBalances()
            ->when($keep !== [], fn ($query) => $query->whereNotIn('id', $keep))
            ->get();

        foreach ($removed as $position) {
            $currency = $position->currency;

            if ($currency instanceof Currency) {
                $transaction = $this->settle($party, $position, $currency, Money::zero($currency->spec()), $at);

                if ($transaction instanceof Transaction) {
                    $posted[] = $transaction;
                }
            }

            $position->delete();
        }

        return $posted;
    }

    /**
     * Post whatever gap is left between what was declared and what the ledger has.
     *
     * Returns null when there is nothing to post, which is the ordinary case of saving
     * a counterparty whose figures nobody touched.
     */
    private function settle(
        Counterparty $party,
        CounterpartyOpeningBalance $position,
        Currency $currency,
        Money $declared,
        DateTimeInterface $at,
    ): ?Transaction {
        $alreadyPosted = $position->posted_amount ?? Money::zero($currency->spec());
        $difference = $declared->minus($alreadyPosted);

        $position->fill([
            'currency_id' => $currency->getKey(),
            'amount' => $declared,
            'posted_amount' => $declared,
        ])->save();

        if ($difference->isZero()) {
            return null;
        }

        // The amount carries its own sign here, which the opening-balance rule reads:
        // positive opens a debt to us, negative opens one the other way.
        return $this->posting->post($this->rules->build(new TransactionInput(
            type: TransactionType::OpeningBalance,
            currency: $currency,
            amount: $difference,
            occurredAt: $at,
            counterparty: $party,
            description: __('counterparties.opening_transaction', ['party' => $party->name]),
        )));
    }
}
