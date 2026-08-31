<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\TransactionInput;
use App\Domain\Tenancy\BusinessProvisioner;
use App\Domain\Tenancy\CurrentBusiness;
use App\Enums\AccountType;
use App\Enums\CounterpartyType;
use App\Enums\Role;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * A known starting state for the browser tests.
 *
 * Deliberately small and deliberately fixed: an end-to-end test that asserts a figure
 * needs to know what that figure should be, and random factory data cannot tell it.
 *
 * Refuses to run against anything but the e2e database. `migrate:fresh` has already
 * destroyed this application's data twice during development, both times because a
 * command was pointed at the wrong environment, and a seeder is exactly the sort of
 * thing somebody runs without reading first.
 */
final class E2eSeeder extends Seeder
{
    public const string DATABASE = 'finance_e2e';

    public const string PASSWORD = 'e2e-password';

    public function run(): void
    {
        $database = config('database.connections.'.config('database.default').'.database');

        if ($database !== self::DATABASE) {
            throw new RuntimeException(
                "E2eSeeder refuses to run against [{$database}]. It is only ever for ["
                .self::DATABASE.'], because it assumes an empty database and the browser '
                .'tests assume its exact contents.'
            );
        }

        $this->call(RolePermissionSeeder::class);

        // Provision exactly as registration does. This creates the business first,
        // then binds it while currencies and the initial safe are written. Calling
        // CurrencySeeder globally stopped being valid once every financial record
        // belonged to a business.
        $owner = app(BusinessProvisioner::class)->provision(
            businessName: 'E2E Exchange',
            name: 'E2E Owner',
            email: 'owner@e2e.test',
            attributes: [
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
            ],
        );

        app(CurrentBusiness::class)->set($owner->business()->firstOrFail());

        $clerk = User::query()->create([
            'name' => 'E2E Clerk',
            'email' => 'clerk@e2e.test',
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
            'business_id' => $owner->business_id,
        ]);
        $clerk->assignRole(Role::Owner->value);

        $egp = Currency::query()->where('code', 'EGP')->sole();
        $usd = Currency::query()->where('code', 'USD')->sole();

        $safe = Account::query()->where('name', 'Main safe')->sole();

        Account::query()->create([
            'name' => 'Bank',
            'type' => AccountType::Bank,
            'is_active' => true,
            'sort_order' => 2,
        ]);

        // The counterparty from the owner's own statement, so the browser tests read
        // against figures that mean something rather than invented ones.
        $party = Counterparty::query()->create([
            'name' => 'سالم التجريبي',
            'type' => CounterpartyType::Customer,
            'is_active' => true,
        ]);

        Counterparty::query()->create([
            'name' => 'Quiet Client',
            'type' => CounterpartyType::Customer,
            'is_active' => true,
        ]);

        $rules = app(PostingRules::class);
        $posting = app(PostingService::class);

        // Nine deposits totalling 3,957,540, exactly as the sheet has them.
        foreach ([500000, 500000, 400000, 457540, 400000, 500000, 400000, 400000, 400000] as $index => $amount) {
            $posting->post($rules->build(new TransactionInput(
                type: TransactionType::In,
                currency: $egp,
                amount: $egp->money((string) $amount),
                occurredAt: new \DateTimeImmutable('2026-06-'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)),
                account: $safe,
                counterparty: $party,
                reference: 'DEP-'.($index + 1),
            )));
        }

        // A margin attached to one of those deposits.
        //
        // Contrived, and deliberately so. A currency exchange settled in cash touches no
        // counterparty account (ADR 0009), so it never reaches a client's statement —
        // which means an exchange cannot be used to prove that a client's copy hides the
        // margin. The browser test needs a movement that is both on the statement and
        // carries a profit, and this is the shortest honest way to have one.
        Transaction::query()
            ->where('reference', 'DEP-1')
            ->update([
                'net_profit' => '14000.0000000000',
                'gross_profit' => '14000.0000000000',
                'profit_currency_id' => $egp->id,
            ]);

        // Cash to exchange from, so the exchange screen has something to deliver.
        $posting->post($rules->build(new TransactionInput(
            type: TransactionType::Deposit,
            currency: $usd,
            amount: $usd->money('200000'),
            occurredAt: new \DateTimeImmutable('2026-05-01'),
            account: $safe,
        )));
    }
}
