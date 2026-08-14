import { FlashMessage } from '@/components/flash-message';
import InputError from '@/components/input-error';
import { MoneyDisplay } from '@/components/money-display';
import { MoneyInput } from '@/components/money-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslations } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, LoaderCircle } from 'lucide-react';
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

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-3 py-1 text-sm focus-visible:ring-1 focus-visible:outline-none';

export default function ExchangeCreate({ currencies, accounts, counterparties, profitMethods, spreadTypes, methods }: Props) {
    const { t } = useTranslations();

    const [breakdown, setBreakdown] = useState<Breakdown | null>(null);
    const [previewing, setPreviewing] = useState(false);

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
    const receivedCode = currencies.find((c) => String(c.id) === data.received_currency_id)?.code ?? '';
    const deliveredCode = currencies.find((c) => String(c.id) === data.delivered_currency_id)?.code ?? '';

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

            fetch('/exchange/preview', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? ''),
                },
                body: JSON.stringify(data),
                signal: controller.signal,
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((result) => setBreakdown(result))
                .catch(() => undefined)
                .finally(() => setPreviewing(false));
        }, 350);

        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [data]);

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.exchange'), href: '/exchange' }];

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post('/exchange');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('transactions.exchange.title')} />

            <div className="flex flex-col gap-6 p-4">
                <FlashMessage />

                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">{t('transactions.exchange.title')}</h1>
                    <p className="text-muted-foreground max-w-2xl text-sm">{t('transactions.exchange.description')}</p>
                </div>

                <form onSubmit={submit} className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="space-y-6">
                        {/* Section 2: two legs, recorded as they happened. */}
                        <div className="grid gap-4 sm:grid-cols-2">
                            <fieldset className="border-sidebar-border/70 dark:border-sidebar-border grid gap-3 rounded-lg border p-4">
                                <legend className="px-1 text-sm font-medium">{t('transactions.exchange.received')}</legend>
                                <p className="text-muted-foreground text-xs">{t('transactions.exchange.received_hint')}</p>

                                <select
                                    value={data.received_currency_id}
                                    onChange={(e) => setData('received_currency_id', e.target.value)}
                                    className={selectClass}
                                    aria-label={t('transactions.exchange.received')}
                                >
                                    {currencies.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.code}
                                        </option>
                                    ))}
                                </select>

                                <MoneyInput value={data.received_amount} onChange={(v) => setData('received_amount', v)} currency={receivedCode} />
                                <InputError message={errors.received_amount} />

                                <Label htmlFor="received_into_id" className="text-xs">
                                    {t('transactions.exchange.into_account')}
                                </Label>
                                <select
                                    id="received_into_id"
                                    value={data.received_into_id}
                                    onChange={(e) => setData('received_into_id', e.target.value)}
                                    className={selectClass}
                                >
                                    {accounts.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.name}
                                        </option>
                                    ))}
                                </select>
                            </fieldset>

                            <fieldset className="border-sidebar-border/70 dark:border-sidebar-border grid gap-3 rounded-lg border p-4">
                                <legend className="px-1 text-sm font-medium">{t('transactions.exchange.delivered')}</legend>
                                <p className="text-muted-foreground text-xs">{t('transactions.exchange.delivered_hint')}</p>

                                <select
                                    value={data.delivered_currency_id}
                                    onChange={(e) => setData('delivered_currency_id', e.target.value)}
                                    className={selectClass}
                                    aria-label={t('transactions.exchange.delivered')}
                                >
                                    {currencies.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.code}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={errors.delivered_currency_id} />

                                <MoneyInput value={data.delivered_amount} onChange={(v) => setData('delivered_amount', v)} currency={deliveredCode} />
                                <InputError message={errors.delivered_amount} />

                                <Label htmlFor="delivered_from_id" className="text-xs">
                                    {t('transactions.exchange.from_account')}
                                </Label>
                                <select
                                    id="delivered_from_id"
                                    value={data.delivered_from_id}
                                    onChange={(e) => setData('delivered_from_id', e.target.value)}
                                    className={selectClass}
                                >
                                    {accounts.map((a) => (
                                        <option key={a.id} value={a.id}>
                                            {a.name}
                                        </option>
                                    ))}
                                </select>
                            </fieldset>
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
