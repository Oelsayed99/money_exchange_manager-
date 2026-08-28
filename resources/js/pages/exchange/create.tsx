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
import type { BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, ArrowLeftRight, LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

interface Option {
    value: string;
    label: string;
}

interface Props {
    currencies: { id: number; code: string; decimal_places: number }[];
    accounts: { id: number; name: string }[];
    counterparties: { id: number; name: string }[];
    profitMethods: (Option & { needsCostRate: boolean; isStatedDirectly: boolean })[];
    spreadTypes: Option[];
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
    spread_type: string;
    spread_value: string;
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

export default function ExchangeCreate({ currencies, accounts, counterparties, profitMethods, spreadTypes, methods }: Props) {
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
        spread_type: '',
        spread_value: '',
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

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post('/exchange');
    };

    const inexact = solved !== null && !solved.exact && solved.field === 'amount';

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

                            {/* The rate, stated the way a dealer states it. */}
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-sm font-medium">{t('transactions.exchange.rate')}</span>
                                <span className="text-muted-foreground font-mono text-sm" dir="ltr">
                                    1 {baseCode} =
                                </span>
                                <MoneyInput
                                    value={rate}
                                    onChange={(v) => {
                                        setLastEdited('rate');
                                        setRate(v);
                                    }}
                                    className="w-40"
                                    aria-label={t('transactions.exchange.rate')}
                                />
                                <span className="text-muted-foreground font-mono text-sm" dir="ltr">
                                    {quoteCode}
                                </span>
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

                            {selectedMethod?.needsCostRate && (
                                <div className="grid gap-2">
                                    <Label htmlFor="cost_rate">{t('transactions.exchange.cost_rate')}</Label>
                                    <MoneyInput id="cost_rate" value={data.cost_rate} onChange={(v) => setData('cost_rate', v)} />
                                    <p className="text-muted-foreground text-xs">{t('transactions.exchange.cost_rate_hint')}</p>
                                    <InputError message={errors.cost_rate} />
                                </div>
                            )}

                            {/* Section 3: a spread is never a bare number. What it means is
                                always chosen alongside it, because 0.02 as units per unit
                                and 0.02 per cent are wildly different margins. */}
                            {data.profit_method === 'percentage' && (
                                <div className="grid gap-2">
                                    <Label htmlFor="spread_type">{t('transactions.exchange.spread_type')}</Label>
                                    <select
                                        id="spread_type"
                                        value={data.spread_type}
                                        onChange={(e) => setData('spread_type', e.target.value)}
                                        className={selectClass}
                                        required
                                    >
                                        <option value="">—</option>
                                        {spreadTypes.map((s) => (
                                            <option key={s.value} value={s.value}>
                                                {s.label}
                                            </option>
                                        ))}
                                    </select>
                                    <p className="text-xs text-amber-700 dark:text-amber-400">{t('transactions.spread_warning')}</p>
                                    <InputError message={errors.spread_type} />
                                </div>
                            )}

                            {(data.profit_method === 'percentage' || selectedMethod?.isStatedDirectly) && (
                                <div className="grid gap-2">
                                    <Label htmlFor="spread_value">{t('transactions.exchange.spread_value')}</Label>
                                    <MoneyInput id="spread_value" value={data.spread_value} onChange={(v) => setData('spread_value', v)} />
                                    <InputError message={errors.spread_value} />
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
                                        <MoneyInput id={field} value={data[field]} onChange={(v) => setData(field, v)} currency={receivedCode} />
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
                    <aside className="border-sidebar-border/70 dark:border-sidebar-border h-fit rounded-xl border p-4 lg:sticky lg:top-4">
                        <h2 className="mb-3 text-sm font-medium">{t('transactions.preview.title')}</h2>

                        {breakdown === null ? (
                            <p className="text-muted-foreground text-sm">{t('transactions.preview.awaiting')}</p>
                        ) : (
                            <dl className={'space-y-2 text-sm ' + (previewing ? 'opacity-60' : '')}>
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

                                <div className="border-sidebar-border/70 dark:border-sidebar-border my-2 border-t" />

                                <Row label={t('transactions.preview.gross_profit')}>
                                    <MoneyDisplay {...toMoney(breakdown.gross_profit)} signed />
                                </Row>
                                <Row label={t('transactions.preview.fees')}>
                                    <MoneyDisplay {...toMoney(breakdown.fees_charged)} />
                                </Row>
                                <Row label={t('transactions.preview.expenses')}>
                                    <MoneyDisplay {...toMoney(breakdown.expenses)} />
                                </Row>
                                <Row label={t('transactions.preview.commissions')}>
                                    <MoneyDisplay {...toMoney(breakdown.commissions)} />
                                </Row>

                                <div className="border-sidebar-border/70 dark:border-sidebar-border my-2 border-t" />

                                <Row label={<span className="font-medium">{t('transactions.preview.net_profit')}</span>}>
                                    <MoneyDisplay {...toMoney(breakdown.net_profit)} signed className="font-medium" />
                                </Row>
                            </dl>
                        )}

                        {/* Section 3: warn before saving an unexpected loss. The server
                            enforces this too — a warning that can be skipped is not one. */}
                        {breakdown?.is_loss && (
                            <div className="mt-4 space-y-2 rounded-lg border border-amber-600/40 bg-amber-600/10 p-3">
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

                        <Button type="submit" className="mt-4 w-full" disabled={processing}>
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
 * POST JSON with the CSRF token, returning null on anything but a success.
 *
 * A failed conversion leaves the previous figures alone rather than blanking them:
 * mid-typing states produce validation failures constantly, and clearing the form on
 * each one would be unusable.
 */
function postJson<T>(url: string, body: unknown, signal: AbortSignal): Promise<T | null> {
    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? ''),
        },
        body: JSON.stringify(body),
        signal,
    }).then((response) => (response.ok ? (response.json() as Promise<T>) : null));
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

function Row({ label, children }: { label: React.ReactNode; children: React.ReactNode }) {
    return (
        <div className="flex items-baseline justify-between gap-4">
            <dt className="text-muted-foreground">{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}
