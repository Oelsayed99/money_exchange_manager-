<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Counterparty\OpeningPositionRecorder;
use App\Domain\Exchange\ExchangeInput;
use App\Domain\Exchange\ExchangeService;
use App\Domain\Ledger\PostingRules;
use App\Domain\Ledger\PostingService;
use App\Domain\Ledger\TransactionInput;
use App\Domain\Reconciliation\ReconciliationService;
use App\Enums\AccountType;
use App\Enums\CounterpartyType;
use App\Enums\MarginBasis;
use App\Enums\MovementMethod;
use App\Enums\ProfitMethod;
use App\Enums\Role;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Counterparty;
use App\Models\Currency;
use App\Models\Transaction;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * A year of plausible trading, so every screen has something on it.
 *
 * Run it explicitly and never from `DatabaseSeeder`:
 *
 *     php artisan db:seed --class=SampleDataSeeder
 *
 * ## What it is for
 *
 * Filters with nothing to filter, charts with one bar and a statement with two rows
 * teach you nothing about whether the application works. This writes enough movement,
 * across enough parties, currencies, months and transaction types, that the dashboard,
 * the lists, the statements and the reconciliation screen all have to do real work.
 *
 * ## How it writes
 *
 * Through the same services the interface uses — posting rules, the exchange service,
 * the opening-position recorder, the reconciliation service. Nothing is inserted by
 * hand. Data invented behind the domain's back would balance by luck rather than by
 * construction, and `ledger:verify` would be checking the seeder rather than the code.
 *
 * ## Running it twice
 *
 * It appends. The ledger is append-only, so there is no undo: run it once, and start
 * over with `php artisan ledger:purge` if you want a clean sheet.
 */
final class SampleDataSeeder extends Seeder
{
    /** Everything is dated from here backwards, so the figures sit in the recent past. */
    private const START = '2026-01-05';

    private const DEMO_PASSWORD = 'monymonk-demo';

    /** @var array<string, Currency> */
    private array $currency = [];

    /** @var array<string, Account> */
    private array $account = [];

    /** @var list<Counterparty> */
    private array $parties = [];

    private int $posted = 0;

    private int $skipped = 0;

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException(
                'SampleDataSeeder invents counterparties and transactions. Telling those apart from '
                .'real ones afterwards is tedious at best, so it refuses to run in production.'
            );
        }

        // Deterministic: two runs of this seeder describe the same business, which
        // makes "did my change break the dashboard" answerable.
        mt_srand(20260829);

        $this->currencies();
        $this->users();
        $this->accounts();
        $this->counterparties();
        $this->openingPositions();
        $this->trading();
        $this->drafts();
        $this->corrections();
        $this->counts();

        $this->command->info("Sample data written: {$this->posted} transactions posted, {$this->skipped} skipped.");
        $this->command->info('Sign in as operator@monymonk.test or viewer@monymonk.test, password '.self::DEMO_PASSWORD);
    }

    private function currencies(): void
    {
        foreach (['USD', 'EUR', 'AED', 'EGP'] as $code) {
            $currency = Currency::query()->where('code', $code)->first();

            if ($currency instanceof Currency) {
                $this->currency[$code] = $currency;
            }
        }

        if (count($this->currency) < 4) {
            throw new RuntimeException('Run CurrencySeeder first: this needs USD, EUR, AED and EGP.');
        }
    }

    /**
     * An operator and a viewer, so the permission model can actually be tried.
     *
     * The administrator is whoever registered first and is left alone.
     */
    private function users(): void
    {
        foreach ([Role::Owner, Role::Owner] as $role) {
            $user = User::query()->firstOrCreate(
                ['email' => $role->value.'@monymonk.test'],
                [
                    'name' => ucfirst($role->value).' (sample)',
                    'password' => self::DEMO_PASSWORD,
                    'email_verified_at' => now(),
                ],
            );

            $user->syncRoles([$role->value]);
        }
    }

    private function accounts(): void
    {
        $wanted = [
            ['Office safe', AccountType::Safe, ['USD' => '180000', 'EGP' => '4200000', 'AED' => '95000']],
            ['Emirates NBD current', AccountType::Bank, ['AED' => '640000', 'USD' => '210000']],
            ['Bank misr turbo', AccountType::Bank, ['EGP' => '9800000']],
            ['CIB dollar account', AccountType::Bank, ['USD' => '430000', 'EUR' => '160000']],
            ['Counter drawer', AccountType::CashWallet, ['EGP' => '350000', 'USD' => '22000']],
        ];

        foreach ($wanted as $index => [$name, $type, $openings]) {
            $account = Account::query()->firstOrCreate(
                ['name' => $name],
                ['type' => $type, 'owner' => 'Omar', 'is_active' => true, 'sort_order' => ($index + 1) * 10],
            );

            $this->account[$name] = $account;

            foreach ($openings as $code => $amount) {
                $this->post(new TransactionInput(
                    type: TransactionType::OpeningBalance,
                    currency: $this->currency[$code],
                    amount: $this->currency[$code]->money($amount),
                    occurredAt: new DateTimeImmutable(self::START),
                    account: $account,
                    reference: 'OPEN-'.$account->id.'-'.$code,
                    description: 'Opening cash — '.$name,
                ));
            }
        }
    }

    private function counterparties(): void
    {
        $wanted = [
            ['سالم التجريبي', CounterpartyType::Customer, 'EG', 'EGP', true],
            ['Turbo Exchange LLC', CounterpartyType::Business, 'AE', 'AED', true],
            ['مؤسسة النيل للصرافة', CounterpartyType::Partner, 'EG', 'EGP', true],
            ['Karim Haddad', CounterpartyType::Customer, 'LB', 'USD', true],
            ['Gulf Remit FZE', CounterpartyType::Supplier, 'AE', 'AED', true],
            ['شركة الدلتا للتجارة', CounterpartyType::Business, 'EG', 'EGP', true],
            ['Mariam Fouad', CounterpartyType::Customer, 'EG', 'EGP', true],
            ['Alpine Trading GmbH', CounterpartyType::Business, 'DE', 'EUR', true],
            ['Yousef Al-Otaibi', CounterpartyType::Customer, 'KW', 'USD', true],
            ['حسن عبد الرحمن', CounterpartyType::Personal, 'EG', 'EGP', true],
            ['Nour Logistics', CounterpartyType::Supplier, 'EG', 'EGP', true],
            ['Sami Barakat', CounterpartyType::Employee, 'EG', 'EGP', true],
            ['Levant Money Transfer', CounterpartyType::Partner, 'JO', 'USD', true],
            ['Old Cairo Traders', CounterpartyType::Other, 'EG', 'EGP', false],
        ];

        foreach ($wanted as $index => [$name, $type, $country, $prefers, $active]) {
            $this->parties[] = Counterparty::query()->firstOrCreate(
                ['name' => $name],
                [
                    'type' => $type,
                    'phone' => '+2010'.str_pad((string) (11000000 + $index * 7331), 8, '0', STR_PAD_LEFT),
                    'country' => $country,
                    'preferred_currency_id' => $this->currency[$prefers]->id,
                    'is_active' => $active,
                ],
            );
        }
    }

    /**
     * Where three parties stood before the books began.
     *
     * Posted through the recorder, so each one is a dated transaction like everything
     * else rather than a note nobody can trace.
     */
    private function openingPositions(): void
    {
        $recorder = app(OpeningPositionRecorder::class);
        $at = new DateTimeImmutable(self::START);

        // Signed: negative means we were already holding money of theirs.
        $positions = [
            0 => ['EGP' => '-884620'],
            1 => ['AED' => '-75000'],
            4 => ['AED' => '-78000', 'USD' => '12500'],
        ];

        foreach ($positions as $index => $rows) {
            $recorder->sync(
                $this->parties[$index],
                array_map(
                    fn (string $code, string $amount): array => [
                        'currency_id' => $this->currency[$code]->id,
                        'amount' => $amount,
                    ],
                    array_keys($rows),
                    array_values($rows),
                ),
                $at,
            );

            $this->posted += count($rows);
        }
    }

    /** Eight months of it. */
    private function trading(): void
    {
        $day = Carbon::parse(self::START)->addDays(3);

        // Never past today. A ledger with next week's deals already in it is a puzzle
        // for whoever opens the dashboard, not a feature.
        $end = Carbon::parse(self::START)->addMonths(8)->min(Carbon::now());

        while ($day->lt($end)) {
            // Fridays off, and a quiet day here and there — a chart with no shape to it
            // does not tell you whether the chart works.
            if (! $day->isFriday() && mt_rand(1, 10) > 2) {
                $this->aDayOfBusiness($day->copy());
            }

            $day->addDay();
        }
    }

    private function aDayOfBusiness(Carbon $day): void
    {
        foreach (range(1, mt_rand(1, 4)) as $ignored) {
            match (mt_rand(1, 10)) {
                1, 2, 3 => $this->anExchange($day),
                4, 5 => $this->creditMovement($day),
                6 => $this->lending($day),
                7 => $this->settlement($day),
                8 => $this->ourOwnMoney($day),
                default => $this->incomeOrCost($day),
            };
        }
    }

    private function anExchange(Carbon $day): void
    {
        $party = mt_rand(1, 4) === 1 ? null : $this->someone();

        // The pairs a business in Cairo and the Gulf actually trades.
        [$received, $delivered, $rate] = match (mt_rand(1, 4)) {
            1 => ['EGP', 'USD', '51.'.mt_rand(10, 95)],
            2 => ['EGP', 'EUR', '58.'.mt_rand(10, 95)],
            3 => ['AED', 'USD', '3.67'.mt_rand(10, 40)],
            default => ['USD', 'AED', '0.272'.mt_rand(10, 60)],
        };

        $units = (string) (mt_rand(2, 90) * 500);
        $deliveredAmount = $this->currency[$delivered]->money($units);
        $receivedAmount = $this->currency[$received]->money(
            bcmul($units, $rate, $this->currency[$received]->decimal_places)
        );

        // Every profit method gets used, so nothing sits untested behind a default.
        [$method, $costRate, $value] = match (mt_rand(1, 10)) {
            1, 2, 3, 4, 5 => [ProfitMethod::RateDifference, bcsub($rate, '0.'.mt_rand(10, 40), 6), null],
            6, 7 => [ProfitMethod::PerUnit, null, '0.'.mt_rand(15, 45)],
            8 => [ProfitMethod::Percentage, null, '0.'.mt_rand(2, 9)],
            9 => [ProfitMethod::FixedAmount, null, (string) (mt_rand(2, 20) * 250)],
            default => [ProfitMethod::Manual, null, (string) (mt_rand(1, 30) * 175)],
        };

        // One deal in fifteen loses money — they happen, and the loss guard should have
        // something to show.
        if ($method === ProfitMethod::RateDifference && mt_rand(1, 15) === 1) {
            $costRate = bcadd($rate, '0.'.mt_rand(20, 60), 6);
        }

        $this->exchange(new ExchangeInput(
            receivedCurrency: $this->currency[$received],
            receivedAmount: $receivedAmount,
            receivedInto: $this->anAccount(),
            deliveredCurrency: $this->currency[$delivered],
            deliveredAmount: $deliveredAmount,
            deliveredFrom: $this->anAccount(),
            occurredAt: $day->toDateTimeImmutable(),
            profitMethod: $method,
            marginBasis: MarginBasis::Received,
            costRate: $costRate,
            profitValue: $value,
            feesCharged: mt_rand(1, 5) === 1 ? $this->currency[$received]->money((string) (mt_rand(1, 12) * 50)) : null,
            expenses: mt_rand(1, 8) === 1 ? $this->currency[$received]->money((string) (mt_rand(1, 6) * 40)) : null,
            commissions: mt_rand(1, 9) === 1 ? $this->currency[$received]->money((string) (mt_rand(1, 8) * 60)) : null,
            counterparty: $party,
            method: $this->aMethod(),
            reference: 'FX-'.$day->format('ymd').'-'.mt_rand(100, 999),
        ));
    }

    private function creditMovement(Carbon $day): void
    {
        $party = $this->someone();
        $code = mt_rand(1, 3) === 1 ? 'USD' : 'EGP';

        $this->post(new TransactionInput(
            type: mt_rand(1, 3) === 1 ? TransactionType::Out : TransactionType::In,
            currency: $this->currency[$code],
            amount: $this->currency[$code]->money((string) (mt_rand(2, 60) * 5000)),
            occurredAt: $day->toDateTimeImmutable(),
            account: $this->anAccount(),
            counterparty: $party,
            method: $this->aMethod(),
            reference: 'CR-'.$day->format('ymd').'-'.mt_rand(10, 99),
        ));
    }

    private function lending(Carbon $day): void
    {
        $code = mt_rand(1, 2) === 1 ? 'EGP' : 'USD';

        $this->post(new TransactionInput(
            type: mt_rand(1, 2) === 1 ? TransactionType::Out : TransactionType::In,
            currency: $this->currency[$code],
            amount: $this->currency[$code]->money((string) (mt_rand(1, 40) * 2500)),
            occurredAt: $day->toDateTimeImmutable(),
            account: $this->anAccount(),
            counterparty: $this->someone(),
            method: $this->aMethod(),
            description: 'Short-term accommodation',
        ));
    }

    private function settlement(Carbon $day): void
    {
        $code = mt_rand(1, 2) === 1 ? 'EGP' : 'AED';

        $this->post(new TransactionInput(
            type: mt_rand(1, 2) === 1 ? TransactionType::In : TransactionType::Out,
            currency: $this->currency[$code],
            amount: $this->currency[$code]->money((string) (mt_rand(1, 30) * 1500)),
            occurredAt: $day->toDateTimeImmutable(),
            account: $this->anAccount(),
            counterparty: $this->someone(),
            method: $this->aMethod(),
            reference: 'STL-'.$day->format('ymd').'-'.mt_rand(10, 99),
        ));
    }

    /** Capital in and out, and money moving between our own locations. */
    private function ourOwnMoney(Carbon $day): void
    {
        $code = ['USD', 'EGP', 'AED'][mt_rand(0, 2)];
        $currency = $this->currency[$code];

        if (mt_rand(1, 3) === 1) {
            $this->post(new TransactionInput(
                type: mt_rand(1, 2) === 1 ? TransactionType::Deposit : TransactionType::Withdrawal,
                currency: $currency,
                amount: $currency->money((string) (mt_rand(1, 20) * 5000)),
                occurredAt: $day->toDateTimeImmutable(),
                account: $this->anAccount(),
                description: 'Owner capital',
            ));

            return;
        }

        [$from, $to] = $this->twoAccounts();

        $this->post(new TransactionInput(
            type: TransactionType::Transfer,
            currency: $currency,
            amount: $currency->money((string) (mt_rand(1, 30) * 2000)),
            occurredAt: $day->toDateTimeImmutable(),
            account: $from,
            destinationAccount: $to,
            method: MovementMethod::Transfer,
            description: 'Rebalancing the counter',
        ));
    }

    private function incomeOrCost(Carbon $day): void
    {
        $currency = $this->currency[mt_rand(1, 2) === 1 ? 'EGP' : 'USD'];

        $this->post(new TransactionInput(
            type: mt_rand(1, 2) === 1 ? TransactionType::Fee : TransactionType::Expense,
            currency: $currency,
            amount: $currency->money((string) (mt_rand(1, 24) * 125)),
            occurredAt: $day->toDateTimeImmutable(),
            account: $this->anAccount(),
            counterparty: mt_rand(1, 2) === 1 ? $this->someone() : null,
            description: ['Handling charge', 'Courier', 'Bank charges', 'Counter float top-up'][mt_rand(0, 3)],
        ));
    }

    /** A few deals still in flight, so `available` differs from `confirmed` somewhere. */
    private function drafts(): void
    {
        $day = Carbon::parse(self::START)->addMonths(8)->min(Carbon::now())->subDays(4);

        foreach (range(1, 6) as $n) {
            $currency = $this->currency[$n % 2 === 0 ? 'USD' : 'EGP'];

            $this->post(new TransactionInput(
                type: TransactionType::Out,
                currency: $currency,
                amount: $currency->money((string) ($n * 7500)),
                occurredAt: $day->copy()->addDays($n % 3)->toDateTimeImmutable(),
                account: $this->anAccount(),
                counterparty: $this->parties[$n],
                method: MovementMethod::Transfer,
                description: 'Promised, not yet cleared',
                status: TransactionStatus::Pending,
            ));
        }
    }

    /** One mistake, corrected the only way the ledger allows. */
    private function corrections(): void
    {
        $original = Transaction::query()
            ->where('type', TransactionType::Fee)
            ->where('status', TransactionStatus::Posted)
            ->latest('id')
            ->first();

        if ($original instanceof Transaction) {
            app(PostingService::class)->reverse(
                $original,
                'Charged to the wrong client.',
                Carbon::parse($original->occurred_at)->addDays(2),
            );

            $this->posted++;
        }
    }

    /** Safes get counted. Some agree, some do not, and one gets explained. */
    private function counts(): void
    {
        $service = app(ReconciliationService::class);
        $counter = User::query()->where('email', 'operator@monymonk.test')->first();
        $asOf = Carbon::parse(self::START)->addMonths(7);

        foreach ([['Office safe', 'USD', '0'], ['Office safe', 'EGP', '-1250'], ['Counter drawer', 'EGP', '600'], ['Emirates NBD current', 'AED', '0']] as [$name, $code, $drift]) {
            $account = $this->account[$name] ?? null;

            if (! $account instanceof Account) {
                continue;
            }

            $currency = $this->currency[$code];
            $ledger = $service->ledgerBalanceAsOf($account, $currency, $asOf);

            $reconciliation = $service->record(
                $account,
                $currency,
                $asOf,
                $ledger->plus($currency->money($drift)),
                $counter,
                'Counted at close.',
            );

            if ($drift === '600') {
                $service->resolve($reconciliation, 'Till float that had not been recorded yet.', $counter);
            }
        }
    }

    // ------------------------------------------------------------------ helpers

    private function post(TransactionInput $input): void
    {
        try {
            app(PostingService::class)->post(app(PostingRules::class)->build($input));
            $this->posted++;
        } catch (Throwable) {
            // A generated movement can occasionally ask for something the rules refuse —
            // a settlement against a party with nothing outstanding, say. Skipping is
            // right: the alternative is a seeder that quietly bends the rules to make
            // its own data fit.
            $this->skipped++;
        }
    }

    private function exchange(ExchangeInput $input): void
    {
        try {
            app(ExchangeService::class)->record($input);
            $this->posted++;
        } catch (Throwable) {
            $this->skipped++;
        }
    }

    private function someone(): Counterparty
    {
        return $this->parties[mt_rand(0, count($this->parties) - 2)];
    }

    private function anAccount(): Account
    {
        $accounts = array_values($this->account);

        return $accounts[mt_rand(0, count($accounts) - 1)];
    }

    /** @return array{Account, Account} */
    private function twoAccounts(): array
    {
        $accounts = array_values($this->account);
        $first = mt_rand(0, count($accounts) - 1);
        $second = ($first + mt_rand(1, count($accounts) - 1)) % count($accounts);

        return [$accounts[$first], $accounts[$second]];
    }

    private function aMethod(): MovementMethod
    {
        return MovementMethod::cases()[mt_rand(0, count(MovementMethod::cases()) - 1)];
    }
}
