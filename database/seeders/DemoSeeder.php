<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Enums\BalanceBucket;
use App\Enums\CounterpartyType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use Illuminate\Database\Seeder;

/**
 * Sample data for looking at the screens.
 *
 * Deliberately NOT called from DatabaseSeeder — run it explicitly:
 *
 *     php artisan db:seed --class=DemoSeeder
 *
 * The counterparty is modelled on a real EGP statement, where a party had handed over
 * money that had not yet been fully settled while also owing a small amount back. In
 * the original spreadsheet those two facts shared one signed column; here they are the
 * separate positions Section 5 requires.
 */
final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $usd = Currency::query()->where('code', 'USD')->firstOrFail();
        $egp = Currency::query()->where('code', 'EGP')->firstOrFail();
        $aed = Currency::query()->where('code', 'AED')->firstOrFail();

        $party = Counterparty::query()->firstOrCreate(
            ['name' => 'سالم التجريبي'],
            [
                'type' => CounterpartyType::Customer,
                'phone' => '+201001234567',
                'country' => 'EG',
                'preferred_currency_id' => $egp->id,
                'is_active' => true,
            ],
        );

        // Money of theirs still sitting with us, and a small amount they owe back —
        // two distinct positions against the same party, at the same time.
        $party->setOpeningBalance(BalanceBucket::CreditTrust, $egp, $egp->money('899510.00'));
        $party->setOpeningBalance(BalanceBucket::Receivable, $egp, $egp->money('14890.00'));

        $office = Account::query()->firstOrCreate(
            ['name' => 'Office safe'],
            [
                'type' => AccountType::Safe,
                'owner' => 'Omar',
                'is_active' => true,
                'sort_order' => 10,
            ],
        );
        $office->setOpeningBalance($usd, $usd->money('25000.00'));
        $office->setOpeningBalance($egp, $egp->money('1250000.00'));

        $bank = Account::query()->firstOrCreate(
            ['name' => 'Emirates NBD current'],
            [
                'type' => AccountType::Bank,
                'owner' => 'Omar',
                'provider' => 'Emirates NBD',
                'identifier' => 'AE070331234567890123456',
                'is_active' => true,
                'sort_order' => 20,
            ],
        );
        $bank->setOpeningBalance($aed, $aed->money('183500.00'));

        // A liability location: their money, in our custody.
        $credit = Account::query()->firstOrCreate(
            ['name' => 'Credit held — سالم التجريبي'],
            [
                'type' => AccountType::CreditTrust,
                'counterparty_id' => $party->id,
                'is_active' => true,
                'sort_order' => 30,
            ],
        );
        $credit->setOpeningBalance($egp, $egp->money('899510.00'));
    }
}
