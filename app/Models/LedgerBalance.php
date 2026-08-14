<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * The cached balance of one ledger account.
 *
 * A cache, not a fact. `ledger:rebuild` recomputes every row from entries alone; if
 * the two ever disagree, this row is wrong by definition. It exists so that showing a
 * balance does not mean summing every entry ever written.
 *
 * @property int $id
 * @property int $ledger_account_id
 * @property string $confirmed_amount
 * @property string $pending_decrease_amount
 * @property int|null $last_entry_id
 */
final class LedgerBalance extends Model
{
    protected $fillable = [
        'ledger_account_id',
        'confirmed_amount',
        'pending_decrease_amount',
        'last_entry_id',
    ];

    /** @return BelongsTo<LedgerAccount, $this> */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    /**
     * The account this balance belongs to, guaranteed present. A balance without an
     * account would be a number describing nothing.
     */
    public function account(): LedgerAccount
    {
        $account = $this->ledgerAccount;

        if (! $account instanceof LedgerAccount) {
            throw new RuntimeException("Ledger balance #{$this->id} has no ledger account.");
        }

        return $account;
    }

    /** What the account holds, counting only completed movements. */
    public function confirmed(): Money
    {
        return Money::of($this->confirmed_amount, $this->account()->spec());
    }

    /**
     * What can actually be spent: confirmed, less movements already committed to.
     *
     * Pending inflows are excluded on purpose. Money somebody has promised is not
     * money you can spend, and a balance that counts it will eventually authorise a
     * payment that bounces.
     */
    public function available(): Money
    {
        $spec = $this->account()->spec();

        return Money::of($this->confirmed_amount, $spec)
            ->minus(Money::of($this->pending_decrease_amount, $spec));
    }
}
