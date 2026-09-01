import { FlashMessage } from '@/components/flash-message';
import InputError from '@/components/input-error';
import { MoneyDisplay } from '@/components/money-display';
import { MoneyInput } from '@/components/money-input';
import { RecordHeading } from '@/components/record-heading';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslations } from '@/lib/i18n';
import { postJson } from '@/lib/post-json';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

type MoneyPayload = { amount: string; currency: string };

/** What /exchange/convert answers with. `exact` is false when the division was cut. */
interface Converted {
    base_amount: MoneyPayload;
    quote_amount: MoneyPayload;
    exact: boolean;
}

interface TypeOption {
    value: string;
    label: string;
    needsCounterparty: boolean;
    needsDestinationAccount: boolean;
    needsBucket: boolean;
    mayConvert: boolean;
    increases: boolean | null;
}

interface Props {
    types: TypeOption[];
    accounts: { id: number; name: string }[];
    currencies: { id: number; code: string }[];
    counterparties: { id: number; name: string }[];
    methods: { value: string; label: string }[];
}

/** Where the party stands, and where this movement would leave them. */
interface Standing {
    balance: MoneyPayload;
    after: MoneyPayload | null;
    they_owe_us: boolean;
    /** Whether the relationship reads the other way once this is recorded. */
    turns_over: boolean;
}

type MovementForm = {
    type: string;
    occurred_at: string;
    currency_id: string;
    amount: string;
    account_id: string;
    destination_account_id: string;
    counterparty_id: string;
    cash_currency_id: string;
    cash_amount: string;
    rate: string;
    method: string;
    reference: string;
    description: string;
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-3 py-1 text-sm focus-visible:ring-1 focus-visible:outline-none';

export default function RecordMovement({ types, accounts, currencies, counterparties, methods }: Props) {
    const { t } = useTranslations();

    const [state, setState] = useState<Standing | null>(null);

    const { data, setData, post, processing, errors, reset } = useForm<MovementForm>({
        type: types[0]?.value ?? '',
        occurred_at: new Date().toISOString().slice(0, 10),
        currency_id: String(currencies[0]?.id ?? ''),
        amount: '',
        account_id: String(accounts[0]?.id ?? ''),
        destination_account_id: '',
        counterparty_id: '',
        cash_currency_id: '',
        cash_amount: '',
        rate: '',
        method: '',
        reference: '',
        description: '',
    });

    const selected = types.find((type) => type.value === data.type);
    const cashCode = currencies.find((c) => String(c.id) === data.cash_currency_id)?.code ?? '';

    /*
        The two amounts and the rate, with the third worked out from the other two.

        This used to multiply in JavaScript and print the answer as a hint, leaving the
        operator to type it in themselves. Two problems with that. It was float
        arithmetic on money, which Section 16 exists to prevent — and it was the wrong
        way round for half the job: recording a client's 1,000,000 EGP as euros means
        knowing the euros, and nothing worked them out.

        So it goes to the server, to the same exact converter the exchange screen uses,
        and the answer lands in the field. Whichever amount the operator is not editing
        is the one that gets computed, so it works in both directions: type the dollars
        and get the pounds, or type the pounds and get the dollars.
    */
    const [lastEdited, setLastEdited] = useState<'amount' | 'cash_amount' | null>(null);
    const [computed, setComputed] = useState<{ field: 'amount' | 'cash_amount'; exact: boolean } | null>(null);

    const converting = selected?.mayConvert === true && data.cash_currency_id !== '' && data.currency_id !== '';

    useEffect(() => {
        // The field the operator is in is the fact; the other one follows it. Without
        // this the two would overwrite each other on every keystroke.
        const solveFor = lastEdited === 'cash_amount' ? 'amount' : 'cash_amount';
        const given = solveFor === 'cash_amount' ? data.amount : data.cash_amount;

        if (!converting || data.rate === '' || given === '') {
            setComputed(null);

            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            postJson<Converted>(
                '/exchange/convert',
                {
                    // Base is the money that actually moved; quote is the currency the
                    // movement is being recorded in. The rate reads quote per base,
                    // which is how it is written on the form: 10,000 USD @ 50.85 = EGP.
                    base_currency_id: data.cash_currency_id,
                    quote_currency_id: data.currency_id,
                    rate: data.rate,
                    ...(solveFor === 'cash_amount' ? { quote_amount: data.amount } : { base_amount: data.cash_amount }),
                },
                controller.signal,
            )
                .then((result) => {
                    if (result === null) {
                        return;
                    }

                    setData(solveFor, solveFor === 'cash_amount' ? result.base_amount.amount : result.quote_amount.amount);
                    setComputed({ field: solveFor, exact: result.exact });
                })
                .catch(() => undefined);
        }, 300);

        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [converting, data.rate, data.amount, data.cash_amount, data.cash_currency_id, data.currency_id, lastEdited, setData]);

    // Clearing the currency clears what depended on it, so a half-filled conversion
    // cannot be submitted by accident.
    useEffect(() => {
        if (data.cash_currency_id === '' && (data.cash_amount !== '' || data.rate !== '')) {
            setData((current) => ({ ...current, cash_amount: '', rate: '' }));
        }
    }, [data.cash_currency_id, data.cash_amount, data.rate, setData]);
    const currencyCode = currencies.find((c) => String(c.id) === data.currency_id)?.code ?? '';

    /**
     * The counterparty's four positions, and what this movement would do to one.
     *
     * From the server, debounced, using the same effect the posting rules apply — so
     * what the screen promises and what the ledger does cannot disagree.
     */
    useEffect(() => {
        if (data.counterparty_id === '' || data.currency_id === '') {
            setState(null);

            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch('/movements/positions', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? ''),
                },
                body: JSON.stringify({
                    counterparty_id: data.counterparty_id,
                    currency_id: data.currency_id,
                    type: data.type,
                    amount: data.amount,
                }),
                signal: controller.signal,
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((result: Standing | null) => setState(result))
                .catch(() => undefined);
        }, 300);

        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [data.counterparty_id, data.currency_id, data.type, data.amount]);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post('/movements', { onSuccess: () => reset('amount', 'reference', 'description') });
    };

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('movements.title'), href: '/movements' }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('movements.title')} />

            <div className="flex flex-col gap-6 p-4">
                <FlashMessage />

                <RecordHeading current="movement" />

                <form onSubmit={submit} className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="space-y-4">
                        <Field label={t('movements.type')} htmlFor="type" error={errors.type}>
                            <select id="type" value={data.type} onChange={(e) => setData('type', e.target.value)} className={selectClass}>
                                {types.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        {/* What this type does, said in words before it is done. */}
                        {selected?.increases != null && (
                            <p className="text-muted-foreground text-xs">
                                {selected.increases ? t('movements.increases') : t('movements.decreases')}
                            </p>
                        )}

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field
                                label={t('movements.amount')}
                                htmlFor="amount"
                                error={errors.amount}
                                note={computed?.field === 'amount' ? t('transactions.exchange.computed') : undefined}
                            >
                                <MoneyInput
                                    id="amount"
                                    value={data.amount}
                                    onChange={(v) => {
                                        setLastEdited('amount');
                                        setData('amount', v);
                                    }}
                                    currency={currencyCode}
                                />
                            </Field>

                            <Field label={t('movements.currency')} htmlFor="currency_id" error={errors.currency_id}>
                                <select
                                    id="currency_id"
                                    value={data.currency_id}
                                    onChange={(e) => setData('currency_id', e.target.value)}
                                    className={selectClass}
                                >
                                    {currencies.map((currency) => (
                                        <option key={currency.id} value={currency.id}>
                                            {currency.code}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            {/*
                                Recording in one currency what moved in another.

                                Take 10,000 dollars and book it against the client as
                                pounds at an agreed rate: the dollars really arrive, the
                                client's account really moves in pounds, and both facts
                                are kept. The amount above is the client's side; this is
                                the money that actually changed hands.
                            */}
                            {selected?.mayConvert === true && (
                                <div className="grid gap-3 rounded-lg border border-dashed p-3 sm:col-span-2 sm:grid-cols-3">
                                    <p className="text-muted-foreground text-xs sm:col-span-3">{t('movements.convert_hint')}</p>

                                    <Field
                                        label={t('movements.cash_amount')}
                                        htmlFor="cash_amount"
                                        error={errors.cash_amount}
                                        note={computed?.field === 'cash_amount' ? t('transactions.exchange.computed') : undefined}
                                    >
                                        <MoneyInput
                                            id="cash_amount"
                                            value={data.cash_amount}
                                            onChange={(v) => {
                                                setLastEdited('cash_amount');
                                                setData('cash_amount', v);
                                            }}
                                            currency={cashCode}
                                        />
                                    </Field>

                                    <Field label={t('movements.cash_currency')} htmlFor="cash_currency_id" error={errors.cash_currency_id}>
                                        <select
                                            id="cash_currency_id"
                                            value={data.cash_currency_id}
                                            onChange={(e) => setData('cash_currency_id', e.target.value)}
                                            className={selectClass}
                                        >
                                            <option value="">{t('movements.same_currency')}</option>
                                            {currencies
                                                .filter((currency) => String(currency.id) !== data.currency_id)
                                                .map((currency) => (
                                                    <option key={currency.id} value={currency.id}>
                                                        {currency.code}
                                                    </option>
                                                ))}
                                        </select>
                                    </Field>

                                    <Field label={t('movements.rate')} htmlFor="rate" error={errors.rate}>
                                        <MoneyInput id="rate" value={data.rate} onChange={(v) => setData('rate', v)} />
                                    </Field>

                                    {/* What the two amounts and the rate say, read back
                                        as one line. A single LTR container, not three:
                                        in Arabic, separate islands put the equals sign
                                        in the wrong place. */}
                                    {converting && data.cash_amount !== '' && data.amount !== '' && data.rate !== '' && (
                                        <p className="text-muted-foreground text-xs sm:col-span-3" dir="ltr">
                                            {data.cash_amount} {cashCode} @ {data.rate} = {data.amount} {currencyCode}
                                        </p>
                                    )}

                                    {/* Division does not always terminate. The figure is
                                        cut rather than rounded, so it can never come out
                                        above the true value — and the operator is told,
                                        because the amount they settled at is the fact. */}
                                    {computed !== null && ! computed.exact && (
                                        <p className="text-xs text-amber-700 sm:col-span-3 dark:text-amber-400">
                                            {t('transactions.exchange.inexact')}
                                        </p>
                                    )}
                                </div>
                            )}

                            <Field label={t('movements.account')} htmlFor="account_id" error={errors.account_id}>
                                <select
                                    id="account_id"
                                    value={data.account_id}
                                    onChange={(e) => setData('account_id', e.target.value)}
                                    className={selectClass}
                                >
                                    {accounts.map((account) => (
                                        <option key={account.id} value={account.id}>
                                            {account.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            {selected?.needsDestinationAccount === true && (
                                <Field
                                    label={t('movements.destination_account')}
                                    htmlFor="destination_account_id"
                                    error={errors.destination_account_id}
                                >
                                    <select
                                        id="destination_account_id"
                                        value={data.destination_account_id}
                                        onChange={(e) => setData('destination_account_id', e.target.value)}
                                        className={selectClass}
                                    >
                                        <option value="">—</option>
                                        {accounts.map((account) => (
                                            <option key={account.id} value={account.id}>
                                                {account.name}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            )}

                            <Field label={t('movements.counterparty')} htmlFor="counterparty_id" error={errors.counterparty_id}>
                                <select
                                    id="counterparty_id"
                                    value={data.counterparty_id}
                                    onChange={(e) => setData('counterparty_id', e.target.value)}
                                    className={selectClass}
                                >
                                    <option value="">—</option>
                                    {counterparties.map((party) => (
                                        <option key={party.id} value={party.id}>
                                            {party.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <Field label={t('movements.occurred_at')} htmlFor="occurred_at" error={errors.occurred_at}>
                                <Input
                                    id="occurred_at"
                                    type="date"
                                    dir="ltr"
                                    value={data.occurred_at}
                                    onChange={(e) => setData('occurred_at', e.target.value)}
                                    required
                                />
                            </Field>

                            <Field label={t('movements.method')} htmlFor="method" error={errors.method}>
                                <select id="method" value={data.method} onChange={(e) => setData('method', e.target.value)} className={selectClass}>
                                    <option value="">—</option>
                                    {methods.map((method) => (
                                        <option key={method.value} value={method.value}>
                                            {method.label}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <Field label={t('movements.reference')} htmlFor="reference" error={errors.reference}>
                                <Input id="reference" value={data.reference} onChange={(e) => setData('reference', e.target.value)} />
                            </Field>

                            <Field label={t('movements.note')} htmlFor="description" error={errors.description}>
                                <Input id="description" value={data.description} onChange={(e) => setData('description', e.target.value)} />
                            </Field>
                        </div>
                    </div>

                    <aside className="border-sidebar-border/70 dark:border-sidebar-border h-fit space-y-3 rounded-xl border p-4 lg:sticky lg:top-4">
                        <div>
                            <h2 className="text-sm font-medium">{t('movements.positions')}</h2>
                            <p className="text-muted-foreground text-xs">{t('movements.positions_hint')}</p>
                        </div>

                        {state === null ? (
                            <p className="text-muted-foreground text-sm">{t('movements.pick_counterparty')}</p>
                        ) : (
                            <>
                                {/* One figure, and what it becomes. Both, and said in
                                    words — a minus sign is the easiest thing on a screen
                                    to misread. */}
                                <Standing label={t('movements.balance_now')} amount={state.balance} />

                                {state.after !== null && (
                                    <div className="border-sidebar-border/70 dark:border-sidebar-border border-t pt-3">
                                        <Standing label={t('movements.after')} amount={state.after} emphasised />
                                    </div>
                                )}

                                {/* The moment worth flagging: they were holding our money
                                    and now they owe us, or the other way about. The owner's
                                    decision stands — said out loud, never blocked. */}
                                {state.turns_over && (
                                    <div className="space-y-1 rounded-lg border border-amber-600/40 bg-amber-600/10 p-3">
                                        <div className="flex items-center gap-2 text-sm font-medium text-amber-800 dark:text-amber-300">
                                            <AlertTriangle className="size-4 shrink-0" aria-hidden="true" />
                                            {t('movements.turns_over')}
                                        </div>
                                        <p className="text-xs text-amber-800 dark:text-amber-300">{t('movements.turns_over_body')}</p>
                                    </div>
                                )}
                            </>
                        )}

                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />}
                            {t('movements.record')}
                        </Button>
                    </aside>
                </form>
            </div>
        </AppLayout>
    );
}

function Field({
    label,
    htmlFor,
    error,
    note,
    children,
}: {
    label: string;
    htmlFor: string;
    error?: string;
    /** Said beside the label rather than under the field: "worked out for you". */
    note?: string;
    children: React.ReactNode;
}) {
    return (
        <div className="grid gap-1.5">
            <div className="flex items-baseline justify-between gap-2">
                <Label htmlFor={htmlFor} className="text-xs">
                    {label}
                </Label>
                {note !== undefined && <span className="text-muted-foreground text-[11px]">{note}</span>}
            </div>
            {children}
            <InputError message={error} />
        </div>
    );
}

/**
 * A balance, and which way it runs.
 *
 * The figure is printed without its sign and the sentence beneath carries the meaning,
 * because "-884,620" and "they are holding 884,620 of ours" are the same fact and only
 * one of them can be misread at a glance.
 */
function Standing({ label, amount, emphasised = false }: { label: string; amount: MoneyPayload; emphasised?: boolean }) {
    const { t } = useTranslations();

    const value = Number(amount.amount);
    const magnitude = { ...amount, amount: amount.amount.replace('-', '') };

    return (
        <div className="space-y-0.5">
            <div className="text-muted-foreground text-xs">{label}</div>
            <MoneyDisplay {...magnitude} className={cn('text-sm', emphasised && 'font-medium')} />
            <div
                className={cn(
                    'text-xs',
                    value === 0 && 'text-muted-foreground',
                    value > 0 && 'text-green-700 dark:text-green-400',
                    value < 0 && 'text-red-700 dark:text-red-400',
                )}
            >
                {value === 0 ? t('counterparties.settled') : value > 0 ? t('counterparties.they_owe_us') : t('counterparties.we_owe_them')}
            </div>
        </div>
    );
}
