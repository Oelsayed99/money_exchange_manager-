import { FlashMessage } from '@/components/flash-message';
import InputError from '@/components/input-error';
import { MoneyDisplay } from '@/components/money-display';
import { MoneyInput } from '@/components/money-input';
import { RecordHeading } from '@/components/record-heading';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslations } from '@/lib/i18n';
import { postJson } from '@/lib/post-json';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowDownRight, ArrowLeftRight, LoaderCircle, TrendingDown, TrendingUp } from 'lucide-react';
import { useEffect, useId, useState } from 'react';

interface Option {
    value: string;
    label: string;
}

interface Props {
    currencies: { id: number; code: string; decimal_places: number }[];
    accounts: { id: number; name: string }[];
    counterparties: { id: number; name: string }[];
    profitMethods: (Option & { needsCostRate: boolean; needsValue: boolean; valueLabel: string })[];
    methods: Option[];
}

type MoneyPayload = { amount: string; currency: string };

interface Breakdown {
    customer_rate: string;
    cost_rate: string | null;
    customer_value: MoneyPayload;
    cost_value: MoneyPayload;
    gross_profit: MoneyPayload;
    fees_charged: MoneyPayload;
    expenses: MoneyPayload;
    commissions: MoneyPayload;
    net_profit: MoneyPayload;
    is_loss: boolean;
}

interface Solved {
    solved_for: 'rate' | 'base_amount' | 'quote_amount';
    rate: string;
    base_amount: MoneyPayload;
    quote_amount: MoneyPayload;
    exact: boolean;
}

type ExchangeForm = {
    occurred_at: string;
    received_currency_id: string;
    received_amount: string;
    received_into_id: string;
    delivered_currency_id: string;
    delivered_amount: string;
    delivered_from_id: string;
    profit_method: string;
    cost_rate: string;
    margin_basis: string;
    profit_value: string;
    fees_charged: string;
    expenses: string;
    commissions: string;
    counterparty_id: string;
    method: string;
    reference: string;
    description: string;
    confirm_loss: boolean;
};

/** Which way round the deal is, from the operator's side of the counter. */
type Direction = 'buy' | 'sell';

/** Whether the rate is quoted with the currency being traded first, or the other one. */
type RateBase = 'subject' | 'counter';

/** The field the operator touched last, and so the one the others follow. */
type LastEdited = 'rate' | 'subject' | 'counter';

type AmountField = 'received_amount' | 'delivered_amount';
type CurrencyField = 'received_currency_id' | 'delivered_currency_id';
type AmountSlot = 'base_amount' | 'quote_amount';

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-3 py-1 text-sm focus-visible:ring-1 focus-visible:outline-none';

export default function ExchangeCreate({ currencies, accounts, counterparties, profitMethods, methods }: Props) {
    const { t } = useTranslations();

    const [breakdown, setBreakdown] = useState<Breakdown | null>(null);
    const [previewing, setPreviewing] = useState(false);

    // How the deal is being entered. None of this is submitted: the direction and the
    // rate are a way of arriving at the two amounts, and the two amounts are what the
    // ledger records. See ExchangeInput.
    const [direction, setDirection] = useState<Direction>('buy');
    const [rate, setRate] = useState('');
    const [rateBase, setRateBase] = useState<RateBase>('subject');
    const [lastEdited, setLastEdited] = useState<LastEdited>('subject');
    const [solved, setSolved] = useState<{ exact: boolean; field: 'rate' | 'amount' } | null>(null);

    const { data, setData, post, processing, errors } = useForm<ExchangeForm>({
        occurred_at: new Date().toISOString().slice(0, 10),
        received_currency_id: String(currencies[0]?.id ?? ''),
        received_amount: '',
        received_into_id: String(accounts[0]?.id ?? ''),
        delivered_currency_id: String(currencies[1]?.id ?? currencies[0]?.id ?? ''),
        delivered_amount: '',
        delivered_from_id: String(accounts[0]?.id ?? ''),
        profit_method: 'rate_difference',
        cost_rate: '',
        margin_basis: 'received',
        profit_value: '',
        fees_charged: '',
        expenses: '',
        commissions: '',
        counterparty_id: '',
        method: '',
        reference: '',
        description: '',
        confirm_loss: false,
    });

    const selectedMethod = profitMethods.find((m) => m.value === data.profit_method);
    const codeOf = (id: string) => currencies.find((c) => String(c.id) === id)?.code ?? '';
    const receivedCode = codeOf(data.received_currency_id);
    const deliveredCode = codeOf(data.delivered_currency_id);

    // Buying means the currency being traded comes in; selling means it goes out. That
    // single choice is what decides which leg is which, so the operator never has to
    // translate "received" and "delivered" in their head.
    const buying = direction === 'buy';
    const subjectAmountField: AmountField = buying ? 'received_amount' : 'delivered_amount';
    const counterAmountField: AmountField = buying ? 'delivered_amount' : 'received_amount';
    const subjectCurrencyField: CurrencyField = buying ? 'received_currency_id' : 'delivered_currency_id';
    const counterCurrencyField: CurrencyField = buying ? 'delivered_currency_id' : 'received_currency_id';

    const subjectAmount = data[subjectAmountField];
    const counterAmount = data[counterAmountField];
    const subjectCurrencyId = data[subjectCurrencyField];
    const counterCurrencyId = data[counterCurrencyField];
    const subjectCode = codeOf(subjectCurrencyId);
    const counterCode = codeOf(counterCurrencyId);

    // "1 USD = 3.67 AED" and "1 AED = 3.67 USD" are different deals. The quote carries
    // which currency it is per, and the operator picks — because a dealer quotes
    // whichever way the market does, not whichever way our form happens to prefer.
    const quotedBySubject = rateBase === 'subject';
    const baseCurrencyId = quotedBySubject ? subjectCurrencyId : counterCurrencyId;
    const quoteCurrencyId = quotedBySubject ? counterCurrencyId : subjectCurrencyId;
    const baseCode = quotedBySubject ? subjectCode : counterCode;
    const quoteCode = quotedBySubject ? counterCode : subjectCode;
    const subjectSlot: AmountSlot = quotedBySubject ? 'base_amount' : 'quote_amount';
    const counterSlot: AmountSlot = quotedBySubject ? 'quote_amount' : 'base_amount';

    const currenciesReady = baseCurrencyId !== '' && quoteCurrencyId !== '' && baseCurrencyId !== quoteCurrencyId;

    /**
     * Rate and one amount in, the other amount out.
     *
     * Runs whenever the rate or the amount being traded changes, but never when the
     * computed amount changes — that direction is the effect below, and having each
     * one ignore what the other writes is what stops the two chasing each other.
     */
    useEffect(() => {
        if (lastEdited === 'counter') {
            return;
        }

        if (!currenciesReady || rate === '' || subjectAmount === '') {
            setSolved(null);

            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            postJson<Solved>(
                '/exchange/convert',
                {
                    base_currency_id: baseCurrencyId,
                    quote_currency_id: quoteCurrencyId,
                    rate,
                    [subjectSlot]: subjectAmount,
                },
                controller.signal,
            )
                .then((result) => {
                    if (result === null) {
                        return;
                    }

                    setData(counterAmountField, result[counterSlot].amount);
                    setSolved({ exact: result.exact, field: 'amount' });
                })
                .catch(() => undefined);
        }, 300);

        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [rate, subjectAmount, baseCurrencyId, quoteCurrencyId, subjectSlot, counterSlot, counterAmountField, currenciesReady, lastEdited, setData]);

    /**
     * Both amounts in, the rate out.
     *
     * The operator has typed over a computed figure with what they actually settled at.
     * The amounts are the fact and the rate describes them, so the rate follows the
     * money rather than the money following a rate nobody honoured.
     */
    useEffect(() => {
        if (lastEdited !== 'counter') {
            return;
        }

        if (!currenciesReady || subjectAmount === '' || counterAmount === '' || subjectAmount === '0') {
            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            postJson<Solved>(
                '/exchange/convert',
                {
                    base_currency_id: baseCurrencyId,
                    quote_currency_id: quoteCurrencyId,
                    [subjectSlot]: subjectAmount,
                    [counterSlot]: counterAmount,
                },
                controller.signal,
            )
                .then((result) => {
                    if (result === null) {
                        return;
                    }

                    setRate(trimTrailingZeros(result.rate));
                    setSolved({ exact: result.exact, field: 'rate' });
                })
                .catch(() => undefined);
        }, 300);

        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [subjectAmount, counterAmount, baseCurrencyId, quoteCurrencyId, subjectSlot, counterSlot, currenciesReady, lastEdited]);

    /**
     * The calculation comes from the server, debounced.
     *
     * Deliberately not computed here: the same calculator runs when the deal is
     * recorded, so what is shown and what is stored cannot disagree. A second
     * implementation in JavaScript would also be float arithmetic, which is the one
     * thing money must never touch.
     */
    useEffect(() => {
        if (data.received_amount === '' || data.delivered_amount === '') {
            setBreakdown(null);

            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            setPreviewing(true);

            postJson<Breakdown>('/exchange/preview', data, controller.signal)
                .then((result) => setBreakdown(result))
                .catch(() => undefined)
                .finally(() => setPreviewing(false));
        }, 350);

        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [data]);

    /** Buying becomes selling: the same two currencies, the opposite two legs. */
    const changeDirection = (next: Direction) => {
        if (next === direction) {
            return;
        }

        setData({
            ...data,
            received_currency_id: data.delivered_currency_id,
            delivered_currency_id: data.received_currency_id,
            received_amount: data.delivered_amount,
            delivered_amount: data.received_amount,
        });

        setDirection(next);
    };

    /**
     * Quote the rate the other way round.
     *
     * The number is not inverted, it is re-derived from the amounts. Inverting is a
     * division: 1 ÷ 3.67 does not terminate, so flipping and flipping back would not
     * return the operator to the rate they typed.
     */
    const swapRateOrientation = () => {
        setRateBase(quotedBySubject ? 'counter' : 'subject');

        if (subjectAmount !== '' && counterAmount !== '') {
            setLastEdited('counter');

            return;
        }

        setRate('');
        setSolved(null);
    };

    const editAmount = (field: AmountField, value: string) => {
        setLastEdited(field === subjectAmountField ? 'subject' : 'counter');
        setData(field, value);
    };

    const editCurrency = (field: CurrencyField, value: string) => {
        setData(field, value);
        setSolved(null);
    };

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.exchange'), href: '/exchange' }];

    // The method wants a figure and none has been typed. The deal will still record —
    // both legs post exactly as they would have — but with no margin claimed.
    const noMarginWillBeRecorded =
        (selectedMethod?.needsCostRate === true && data.cost_rate.trim() === '') ||
        (selectedMethod?.needsValue === true && data.profit_value.trim() === '');

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post('/exchange');
    };

    const inexact = solved !== null && !solved.exact && solved.field === 'amount';

    /*
        Which leg carries the margin, taken from the way the deal rate is being quoted.

        The rate reads "1 base = X quote", so the quote currency is the one a margin is
        naturally counted in — and the cost rate can then be typed in exactly the same
        terms and applied by multiplication. Selling dollars for pounds that is the
        received leg; buying dollars with pounds it is the delivered leg, which is why
        this is derived rather than fixed. See MarginBasis.
    */
    const marginBasis = quoteCurrencyId === data.received_currency_id ? 'received' : 'delivered';
    const marginCode = marginBasis === 'received' ? receivedCode : deliveredCode;

    useEffect(() => {
        if (data.margin_basis !== marginBasis) {
            setData('margin_basis', marginBasis);
        }
    }, [marginBasis, data.margin_basis, setData]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('transactions.exchange.title')} />

            <div className="flex flex-col gap-6 p-4">
                <FlashMessage />

                <RecordHeading current="exchange" />

                <form onSubmit={submit} className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="space-y-6">
                        {/* The deal as it was negotiated: a direction, an amount, a rate.
                            The two legs below are what this works out to. */}
                        <fieldset className="border-sidebar-border/70 dark:border-sidebar-border grid gap-4 rounded-lg border p-4">
                            <legend className="px-1 text-sm font-medium">{t('transactions.exchange.direction')}</legend>

                            <div className="grid gap-3 sm:grid-cols-[10rem_minmax(0,1fr)_7rem]">
                                <select
                                    value={direction}
                                    onChange={(e) => changeDirection(e.target.value as Direction)}
                                    className={selectClass}
                                    aria-label={t('transactions.exchange.direction')}
                                >
                                    <option value="buy">{t('transactions.exchange.buying')}</option>
                                    <option value="sell">{t('transactions.exchange.selling')}</option>
                                </select>

                                <MoneyInput
                                    value={subjectAmount}
                                    onChange={(v) => editAmount(subjectAmountField, v)}
                                    currency={subjectCode}
                                    aria-label={buying ? t('transactions.exchange.buying') : t('transactions.exchange.selling')}
                                />

                                <select
                                    value={subjectCurrencyId}
                                    onChange={(e) => editCurrency(subjectCurrencyField, e.target.value)}
                                    className={selectClass}
                                    aria-label={t('transactions.exchange.currency')}
                                >
                                    {currencies.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.code}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <p className="text-muted-foreground text-xs">
                                {buying ? t('transactions.exchange.buying_hint') : t('transactions.exchange.selling_hint')}
                            </p>

                            <div className="grid gap-3 sm:grid-cols-[10rem_minmax(0,1fr)_7rem]">
                                <Label htmlFor="counter_amount" className="self-center text-sm">
                                    {buying ? t('transactions.exchange.paying_in') : t('transactions.exchange.paid_in')}
                                </Label>

                                <div className="space-y-1">
                                    <MoneyInput
                                        id="counter_amount"
                                        value={counterAmount}
                                        onChange={(v) => editAmount(counterAmountField, v)}
                                        currency={counterCode}
                                    />
                                    {solved?.field === 'amount' && (
                                        <p className="text-muted-foreground text-xs">
                                            {t('transactions.exchange.computed')} — {t('transactions.exchange.computed_hint')}
                                        </p>
                                    )}
                                </div>

                                <select
                                    value={counterCurrencyId}
                                    onChange={(e) => editCurrency(counterCurrencyField, e.target.value)}
                                    className={selectClass}
                                    aria-label={t('transactions.exchange.currency')}
                                >
                                    {currencies.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.code}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <InputError message={errors.delivered_currency_id} />

                            {/* The rate, stated the way a dealer states it.
                                The quotation is one formula, so the whole of it is a
                                left-to-right island rather than three of them. Marking
                                only the pieces left the row flowing right-to-left in
                                Arabic, which put the equals sign against the label — "=
                                1 USD [box] EUR" — because "=" is the last character of a
                                left-to-right run and therefore its rightmost. Latin
                                currency codes and a number read one way in both
                                languages; only the label beside them turns over. */}
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-sm font-medium">{t('transactions.exchange.rate')}</span>
                                <div className="flex items-center gap-2" dir="ltr">
                                    <span className="text-muted-foreground font-mono text-sm">1 {baseCode} =</span>
                                    <MoneyInput
                                        value={rate}
                                        onChange={(v) => {
                                            setLastEdited('rate');
                                            setRate(v);
                                        }}
                                        className="w-40"
                                        aria-label={t('transactions.exchange.rate')}
                                    />
                                    <span className="text-muted-foreground font-mono text-sm">{quoteCode}</span>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={swapRateOrientation}
                                    title={t('transactions.exchange.swap_rate')}
                                    aria-label={t('transactions.exchange.swap_rate')}
                                >
                                    <ArrowLeftRight className="size-4" aria-hidden="true" />
                                </Button>
                            </div>

                            {/* Truncation, admitted at the moment it happens, in front of
                                the person who can still change the figure. */}
                            {inexact && (
                                <p className="rounded-md border border-amber-600/40 bg-amber-600/10 p-2 text-xs text-amber-800 dark:text-amber-300">
                                    {t('transactions.exchange.inexact')}
                                </p>
                            )}

                            {solved?.field === 'rate' && (
                                <p className="text-muted-foreground text-xs">{t('transactions.exchange.effective_rate_hint')}</p>
                            )}
                        </fieldset>

                        {/* Section 2: two legs, recorded as they happened. Shown here as
                            what will be stored, so the operator can see their deal in the
                            ledger's own terms before committing it. */}
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Leg
                                title={t('transactions.exchange.received')}
                                hint={t('transactions.exchange.received_hint')}
                                amount={data.received_amount}
                                code={receivedCode}
                                accountLabel={t('transactions.exchange.into_account')}
                                accountField="received_into_id"
                                accountValue={data.received_into_id}
                                accounts={accounts}
                                onAccountChange={(v) => setData('received_into_id', v)}
                                error={errors.received_amount}
                            />

                            <Leg
                                title={t('transactions.exchange.delivered')}
                                hint={t('transactions.exchange.delivered_hint')}
                                amount={data.delivered_amount}
                                code={deliveredCode}
                                accountLabel={t('transactions.exchange.from_account')}
                                accountField="delivered_from_id"
                                accountValue={data.delivered_from_id}
                                accounts={accounts}
                                onAccountChange={(v) => setData('delivered_from_id', v)}
                                error={errors.delivered_amount}
                            />
                        </div>

                        <fieldset className="border-sidebar-border/70 dark:border-sidebar-border grid gap-4 rounded-lg border p-4">
                            <legend className="px-1 text-sm font-medium">{t('transactions.exchange.profit')}</legend>

                            <div className="grid gap-2">
                                <Label htmlFor="profit_method">{t('transactions.exchange.profit_method')}</Label>
                                <select
                                    id="profit_method"
                                    value={data.profit_method}
                                    onChange={(e) => setData('profit_method', e.target.value)}
                                    className={selectClass}
                                >
                                    {profitMethods.map((m) => (
                                        <option key={m.value} value={m.value}>
                                            {m.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            {/*
                                The cost rate, stated the way the rate above is stated.
                                It used to be a bare number box, and "per unit" was left
                                to a line of hint text — which is how a test written with
                                the spec open still set a deal up backwards and valued it
                                at a hundred and thirty million.

                                Always this way round, whichever way the rate above is
                                currently quoted: the ledger holds cost per unit
                                delivered, and turning it over would mean dividing, which
                                is where precision goes. What the customer was charged is
                                printed underneath in the same terms, so the difference —
                                which is the whole method — can be read rather than
                                worked out.
                            */}
                            {selectedMethod?.needsCostRate && (
                                <div className="grid gap-2">
                                    <Label htmlFor="cost_rate">{t('transactions.exchange.cost_rate')}</Label>

                                    {/* One left-to-right island, as the deal rate above. */}
                                    <div className="flex flex-wrap items-center gap-2" dir="ltr">
                                        <span className="text-muted-foreground font-mono text-sm">1 {baseCode} =</span>
                                        <MoneyInput
                                            id="cost_rate"
                                            value={data.cost_rate}
                                            onChange={(v) => setData('cost_rate', v)}
                                            className="w-40"
                                        />
                                        <span className="text-muted-foreground font-mono text-sm">{quoteCode}</span>
                                    </div>

                                    {/* What was charged, in the box's own units. The
                                        figure to beat, at the scale it should be typed
                                        at — which is the whole of the method and was
                                        previously left to be worked out. */}
                                    {breakdown !== null && (
                                        <div className="text-muted-foreground flex flex-wrap items-center gap-2 text-xs">
                                            <span>{t('transactions.exchange.customer_rate_inline')}</span>
                                            <span className="font-mono tabular-nums" dir="ltr">
                                                1 {baseCode} = {breakdown.customer_rate} {quoteCode}
                                            </span>
                                        </div>
                                    )}

                                    <p className="text-muted-foreground text-xs">
                                        {t('transactions.exchange.cost_rate_hint', { currency: marginCode })}
                                    </p>
                                    <InputError message={errors.cost_rate} />
                                </div>
                            )}

                            {/* One box, named by the method above it.
                                Section 3's warning — that 0.02 may be two hundredths of a
                                unit or two per cent — used to be printed here beside a
                                second select asking which. The two answers are now two
                                methods, so the label says which reading applies instead of
                                asking the operator to say it twice. */}
                            {selectedMethod?.needsValue && (
                                <div className="grid gap-2">
                                    <Label htmlFor="profit_value">{selectedMethod.valueLabel}</Label>
                                    <MoneyInput id="profit_value" value={data.profit_value} onChange={(v) => setData('profit_value', v)} />
                                    <InputError message={errors.profit_value} />
                                </div>
                            )}

                            <div className="grid gap-3 sm:grid-cols-3">
                                {(
                                    [
                                        ['fees_charged', t('transactions.exchange.fees')],
                                        ['expenses', t('transactions.exchange.expenses')],
                                        ['commissions', t('transactions.exchange.commissions')],
                                    ] as const
                                ).map(([field, label]) => (
                                    <div key={field} className="grid gap-2">
                                        <Label htmlFor={field} className="text-xs">
                                            {label}
                                        </Label>
                                        <MoneyInput id={field} value={data[field]} onChange={(v) => setData(field, v)} currency={marginCode} />
                                        <InputError message={errors[field]} />
                                    </div>
                                ))}
                            </div>
                        </fieldset>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="counterparty_id">{t('transactions.exchange.counterparty')}</Label>
                                <select
                                    id="counterparty_id"
                                    value={data.counterparty_id}
                                    onChange={(e) => setData('counterparty_id', e.target.value)}
                                    className={selectClass}
                                >
                                    <option value="">{t('transactions.exchange.no_counterparty')}</option>
                                    {counterparties.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="method">{t('transactions.exchange.method')}</Label>
                                <select id="method" value={data.method} onChange={(e) => setData('method', e.target.value)} className={selectClass}>
                                    <option value="">—</option>
                                    {methods.map((m) => (
                                        <option key={m.value} value={m.value}>
                                            {m.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="occurred_at">{t('transactions.exchange.occurred_at')}</Label>
                                <Input
                                    id="occurred_at"
                                    type="date"
                                    value={data.occurred_at}
                                    onChange={(e) => setData('occurred_at', e.target.value)}
                                    dir="ltr"
                                    required
                                />
                                <InputError message={errors.occurred_at} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="reference">{t('transactions.exchange.reference')}</Label>
                                <Input id="reference" value={data.reference} onChange={(e) => setData('reference', e.target.value)} />
                            </div>
                        </div>
                    </div>

                    {/* The calculation, alongside the form rather than below it, so the
                        margin is visible while the amounts are being typed. */}
                    <aside className="h-fit space-y-3 lg:sticky lg:top-4">
                        <h2 className="text-sm font-medium">{t('transactions.preview.title')}</h2>

                        {/* A method chosen but its figure left blank records the deal
                            with no margin at all. Said here rather than discovered
                            later in a report: a margin dropped quietly is money. */}
                        {noMarginWillBeRecorded && (
                            <p className="rounded-xl border border-amber-600/40 bg-amber-600/10 p-4 text-sm text-amber-800 dark:text-amber-300">
                                {t('transactions.preview.no_margin')}
                            </p>
                        )}

                        {breakdown === null ? (
                            <p className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground rounded-xl border p-4 text-sm">
                                {t('transactions.preview.awaiting')}
                            </p>
                        ) : (
                            /*
                                Two cards: what the deal earned, and what came off it.
                                It was one column of eleven rows with the margin ninth.

                                Side by side while the aside is full width; stacked once
                                it becomes the narrow rail, where two columns would put
                                three digits on a line.
                            */
                            <div className={'grid gap-3 sm:grid-cols-2 lg:grid-cols-1 ' + (previewing ? 'opacity-60' : '')}>
                                {/*
                                    Green until the deal loses money, then red. The
                                    heading changes with it — Section 13 forbids saying
                                    anything with colour alone, and a red border is not
                                    something a screen reader can read out.
                                */}
                                <Panel
                                    tone={breakdown.is_loss ? 'loss' : 'profit'}
                                    icon={
                                        breakdown.is_loss ? (
                                            <TrendingDown className="size-4 shrink-0" aria-hidden="true" />
                                        ) : (
                                            <TrendingUp className="size-4 shrink-0" aria-hidden="true" />
                                        )
                                    }
                                    title={breakdown.is_loss ? t('transactions.preview.loss_side') : t('transactions.preview.profit_side')}
                                >
                                    <Row label={t('transactions.preview.customer_rate')}>
                                        <span className="font-mono tabular-nums" dir="ltr">
                                            {breakdown.customer_rate}
                                        </span>
                                    </Row>
                                    {breakdown.cost_rate && (
                                        <Row label={t('transactions.preview.cost_rate')}>
                                            <span className="font-mono tabular-nums" dir="ltr">
                                                {breakdown.cost_rate}
                                            </span>
                                        </Row>
                                    )}
                                    <Row label={t('transactions.preview.customer_value')}>
                                        <MoneyDisplay {...toMoney(breakdown.customer_value)} />
                                    </Row>
                                    <Row label={t('transactions.preview.cost_value')}>
                                        <MoneyDisplay {...toMoney(breakdown.cost_value)} />
                                    </Row>
                                    <Row label={t('transactions.preview.gross_profit')}>
                                        <MoneyDisplay {...toMoney(breakdown.gross_profit)} signed />
                                    </Row>
                                    <Row label={t('transactions.preview.fees')}>
                                        <MoneyDisplay {...toMoney(breakdown.fees_charged)} />
                                    </Row>

                                    <div className="my-2 border-t border-current/20" />

                                    <Row label={<span className="font-medium">{t('transactions.preview.net_profit')}</span>}>
                                        <MoneyDisplay {...toMoney(breakdown.net_profit)} signed className="font-medium" />
                                    </Row>
                                </Panel>

                                {/* What comes off the deal. Both figures are already
                                    inside the net profit above; this card is where they
                                    went, which the single column buried among the rates. */}
                                <Panel
                                    tone="loss"
                                    icon={<ArrowDownRight className="size-4 shrink-0" aria-hidden="true" />}
                                    title={t('transactions.preview.deducted_side')}
                                >
                                    <Row label={t('transactions.preview.expenses')}>
                                        <MoneyDisplay {...toMoney(breakdown.expenses)} />
                                    </Row>
                                    <Row label={t('transactions.preview.commissions')}>
                                        <MoneyDisplay {...toMoney(breakdown.commissions)} />
                                    </Row>
                                </Panel>
                            </div>
                        )}

                        {/* Section 3: warn before saving an unexpected loss. The server
                            enforces this too — a warning that can be skipped is not one. */}
                        {breakdown?.is_loss && (
                            <div className="space-y-2 rounded-lg border border-amber-600/40 bg-amber-600/10 p-3">
                                <div className="flex items-center gap-2 text-sm font-medium text-amber-800 dark:text-amber-300">
                                    <AlertTriangle className="size-4 shrink-0" aria-hidden="true" />
                                    {t('transactions.loss.heading')}
                                </div>
                                <p className="text-xs text-amber-800 dark:text-amber-300">{t('transactions.loss.body')}</p>
                                <label className="flex items-start gap-2 text-xs">
                                    <Checkbox checked={data.confirm_loss} onClick={() => setData('confirm_loss', !data.confirm_loss)} />
                                    <span>{t('transactions.loss.confirm')}</span>
                                </label>
                                <InputError message={errors.confirm_loss} />
                            </div>
                        )}

                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />}
                            {t('transactions.exchange.record')}
                        </Button>
                    </aside>
                </form>
            </div>
        </AppLayout>
    );
}

/**
 * One side of the deal as the ledger will hold it.
 *
 * The amount is shown rather than typed: it is set by the deal above, and offering a
 * second place to change it would let the two disagree.
 */
function Leg({
    title,
    hint,
    amount,
    code,
    accountLabel,
    accountField,
    accountValue,
    accounts,
    onAccountChange,
    error,
}: {
    title: string;
    hint: string;
    amount: string;
    code: string;
    accountLabel: string;
    accountField: string;
    accountValue: string;
    accounts: { id: number; name: string }[];
    onAccountChange: (value: string) => void;
    error?: string;
}) {
    return (
        <fieldset className="border-sidebar-border/70 dark:border-sidebar-border grid gap-3 rounded-lg border p-4">
            <legend className="px-1 text-sm font-medium">{title}</legend>
            <p className="text-muted-foreground text-xs">{hint}</p>

            <p className="font-mono text-lg tabular-nums" dir="ltr">
                {amount === '' ? <span className="text-muted-foreground text-sm">—</span> : `${amount} ${code}`}
            </p>
            <InputError message={error} />

            <Label htmlFor={accountField} className="text-xs">
                {accountLabel}
            </Label>
            <select id={accountField} value={accountValue} onChange={(e) => onAccountChange(e.target.value)} className={selectClass}>
                {accounts.map((a) => (
                    <option key={a.id} value={a.id}>
                        {a.name}
                    </option>
                ))}
            </select>
        </fieldset>
    );
}


/**
 * Tidy a rate for display without touching its value.
 *
 * String surgery, not arithmetic: a derived rate arrives padded to twelve places and
 * "54.200542005420" is harder to read than "54.20054200542". Parsing it to trim it
 * would put a float between the server's answer and the operator's eyes.
 */
function trimTrailingZeros(rate: string): string {
    if (!rate.includes('.')) {
        return rate;
    }

    const trimmed = rate.replace(/0+$/, '').replace(/\.$/, '');

    return trimmed === '' || trimmed === '-' ? rate : trimmed;
}

function toMoney(payload: MoneyPayload) {
    return { amount: payload.amount, currency: payload.currency };
}

/**
 * One half of the calculation.
 *
 * The tone is a border and a tint, and it is the *last* thing that says which half
 * this is: the icon and the heading say it first, and both survive a monochrome
 * screen, a colour-blind reader and a screen reader. Section 13.
 */
function Panel({ tone, icon, title, children }: { tone: 'loss' | 'profit'; icon: React.ReactNode; title: string; children: React.ReactNode }) {
    // A <section> is only a landmark once it has a name, and naming it from the heading
    // it already shows beats an aria-label repeating the same words.
    const headingId = useId();

    const tones = {
        loss: 'border-red-600/40 bg-red-600/5 text-red-800 dark:border-red-500/40 dark:text-red-300',
        profit: 'border-green-600/40 bg-green-600/5 text-green-800 dark:border-green-500/40 dark:text-green-300',
    } as const;

    return (
        <section aria-labelledby={headingId} className={'rounded-xl border p-4 ' + tones[tone]}>
            <h3 id={headingId} className="mb-3 flex items-center gap-2 text-xs font-medium tracking-wide uppercase">
                {icon}
                {title}
            </h3>

            {/* The figures themselves stay in the ordinary text colour. Tinting an
                amount to match its panel makes every number on the screen look like a
                status, and one of them is the answer. */}
            <dl className="text-foreground space-y-2 text-sm">{children}</dl>
        </section>
    );
}

function Row({ label, children }: { label: React.ReactNode; children: React.ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-4">
            <dt className="text-muted-foreground">{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}
