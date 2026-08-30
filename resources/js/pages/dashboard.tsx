import { ChartEmpty, ChartPanel, ExactTooltip, axisTick, plot } from '@/components/charts';
import { MoneyDisplay } from '@/components/money-display';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslations } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { Bar, BarChart, CartesianGrid, Cell, Legend, Pie, PieChart, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

type MoneyPayload = { amount: string; currency: string };
type ByCurrency = Record<string, MoneyPayload>;

interface PartyRow {
    id: number;
    name: string;
    status: string;
    status_by_currency: Record<string, string>;
    /** Currency code to one signed balance. Positive means they owe us. */
    positions: ByCurrency;
}

interface DashboardData {
    currencies: string[];
    cash_on_hand: ByCurrency;
    owed_to_us: ByCurrency;
    owed_to_them: ByCurrency;
    received: ByCurrency;
    delivered: ByCurrency;
    profit: ByCurrency;
    monthly_profit: Record<string, string>;
    monthly_flow: Record<string, { in: string; out: string }>;
    status_counts: Record<string, number>;
    top_clients: { id: number; name: string; owed_to_us: MoneyPayload; owed_to_them: MoneyPayload }[];
    counterparties: PartyRow[];
}

interface Filters {
    counterparty: number | null;
    currency: string | null;
    status: string | null;
    from: string | null;
    to: string | null;
}

/** What the market is quoting. Reading only — see ReferenceRates. */
interface Rates {
    base: string;
    updated_at: string;
    quotes: { code: string; rate: string }[];
}

interface Props {
    dashboard: DashboardData;
    rates: Rates | null;
    filters: Filters;
    options: {
        counterparties: { id: number; name: string }[];
        currencies: { code: string }[];
        statuses: { value: string; label: string }[];
    };
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-3 py-1 text-sm focus-visible:ring-1 focus-visible:outline-none';

export default function Dashboard({ dashboard, rates, filters, options }: Props) {
    const { t } = useTranslations();

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('dashboard.title'), href: '/dashboard' }];

    const apply = (changes: Partial<Filters>) =>
        router.get('/dashboard', clean({ ...filters, ...changes }), { preserveState: true, preserveScroll: true });

    const filtered = Object.values(filters).some((value) => value !== null && value !== '');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('dashboard.title')} />

            <div className="flex flex-col gap-6 p-4">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">{t('dashboard.title')}</h1>
                    <p className="text-muted-foreground text-sm">{t('dashboard.description')}</p>
                </div>

                <ReferenceRateStrip rates={rates} />

                {/* Filters live in the URL so a view can be linked to and come back the same. */}
                <div className="flex flex-wrap items-end gap-3">
                    <Field label={t('dashboard.filters.client')} htmlFor="counterparty">
                        <select
                            id="counterparty"
                            value={filters.counterparty ?? ''}
                            onChange={(e) => apply({ counterparty: e.target.value === '' ? null : Number(e.target.value) })}
                            className={selectClass}
                        >
                            <option value="">{t('dashboard.filters.all')}</option>
                            {options.counterparties.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label={t('dashboard.filters.currency')} htmlFor="currency">
                        <select
                            id="currency"
                            value={filters.currency ?? ''}
                            onChange={(e) => apply({ currency: e.target.value === '' ? null : e.target.value })}
                            className={selectClass}
                        >
                            <option value="">{t('dashboard.filters.all')}</option>
                            {options.currencies.map((c) => (
                                <option key={c.code} value={c.code}>
                                    {c.code}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label={t('dashboard.filters.status')} htmlFor="status">
                        <select
                            id="status"
                            value={filters.status ?? ''}
                            onChange={(e) => apply({ status: e.target.value === '' ? null : e.target.value })}
                            className={selectClass}
                        >
                            <option value="">{t('dashboard.filters.all')}</option>
                            {options.statuses.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label={t('dashboard.filters.from')} htmlFor="from">
                        <Input
                            id="from"
                            type="date"
                            dir="ltr"
                            className="w-40"
                            value={filters.from ?? ''}
                            onChange={(e) => apply({ from: e.target.value === '' ? null : e.target.value })}
                        />
                    </Field>

                    <Field label={t('dashboard.filters.to')} htmlFor="to">
                        <Input
                            id="to"
                            type="date"
                            dir="ltr"
                            className="w-40"
                            value={filters.to ?? ''}
                            onChange={(e) => apply({ to: e.target.value === '' ? null : e.target.value })}
                        />
                    </Field>

                    {filtered && (
                        <Button type="button" variant="ghost" size="sm" onClick={() => router.get('/dashboard', {}, { preserveScroll: true })}>
                            {t('dashboard.filters.clear')}
                        </Button>
                    )}
                </div>

                <p className="text-muted-foreground text-xs">{t('dashboard.period_note')}</p>

                {dashboard.currencies.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border p-6 text-sm">{t('dashboard.no_data')}</p>
                ) : (
                    <>
                        {/* One card per currency. Figures are never added across
                            currencies: there is no base currency, so a combined total
                            would need a rate and would move when the market did. */}
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {dashboard.currencies.map((code) => (
                                <div key={code} className="space-y-3 rounded-xl border p-4">
                                    <div className="font-mono text-sm font-medium" dir="ltr">
                                        {code}
                                    </div>

                                    <dl className="space-y-2 text-sm">
                                        <Figure label={t('dashboard.cards.cash_on_hand')} amount={dashboard.cash_on_hand[code]} />
                                        <Figure label={t('dashboard.cards.owed_to_us')} amount={dashboard.owed_to_us[code]} />
                                        <Figure label={t('dashboard.cards.owed_to_them')} amount={dashboard.owed_to_them[code]} />

                                        <div className="border-sidebar-border/70 dark:border-sidebar-border my-2 border-t" />

                                        <Figure label={t('dashboard.cards.received')} amount={dashboard.received[code]} />
                                        <Figure label={t('dashboard.cards.delivered')} amount={dashboard.delivered[code]} />
                                        <Figure label={t('dashboard.cards.profit')} amount={dashboard.profit[code]} signed />
                                    </dl>
                                </div>
                            ))}
                        </div>

                        <div className="grid gap-4 xl:grid-cols-2">
                            <MonthlyProfit monthly={dashboard.monthly_profit} currency={filters.currency} />
                            <MonthlyFlow monthly={dashboard.monthly_flow} currency={filters.currency} />
                            <StatusSplit counts={dashboard.status_counts} />
                            <TopClients clients={dashboard.top_clients} currency={filters.currency} />
                        </div>

                        <div className="space-y-2">
                            <h2 className="text-sm font-medium">{t('dashboard.parties.title')}</h2>

                            <div className="overflow-x-auto rounded-lg border">
                                <table className="w-full min-w-2xl text-sm">
                                    <thead className="bg-muted/50 text-muted-foreground">
                                        <tr>
                                            <th scope="col" className="p-2 text-start font-medium">
                                                {t('dashboard.parties.name')}
                                            </th>
                                            <th scope="col" className="p-2 text-start font-medium">
                                                {t('dashboard.parties.status')}
                                            </th>
                                            <th scope="col" className="p-2 text-start font-medium">
                                                {t('dashboard.parties.balance')}
                                            </th>
                                            <th scope="col" className="p-2" />
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {dashboard.counterparties.length === 0 && (
                                            <tr>
                                                <td colSpan={4} className="text-muted-foreground p-6 text-center">
                                                    {t('dashboard.parties.none')}
                                                </td>
                                            </tr>
                                        )}

                                        {dashboard.counterparties.map((party) => (
                                            <tr key={party.id} className="border-t align-top">
                                                <td className="p-2">{party.name}</td>
                                                <td className="p-2">
                                                    <StatusBadge status={party.status} label={t(`dashboard.statuses.${party.status}`)} />
                                                </td>
                                                <td className="p-2">
                                                    <PartyBalance party={party} />
                                                </td>
                                                <td className="p-2 text-end">
                                                    <Button variant="ghost" size="sm" asChild>
                                                        <Link href={`/counterparties/${party.id}/statement`}>
                                                            <FileText className="size-4" aria-hidden="true" />
                                                            {t('dashboard.parties.statement')}
                                                        </Link>
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}

/**
 * Where one client stands, per currency — the same one signed figure the client list
 * shows, and worded the same way.
 *
 * Currencies are never added together: a client owing dollars and holding pounds has
 * two balances, and the only honest summary of that is the status badge beside them.
 */
function PartyBalance({ party }: { party: PartyRow }) {
    const { t } = useTranslations();

    const carrying = Object.entries(party.positions).filter(([, money]) => Number(money.amount) !== 0);

    if (carrying.length === 0) {
        return <span className="text-muted-foreground">{t('counterparties.settled')}</span>;
    }

    return (
        <div className="space-y-1">
            {carrying.map(([code, money]) => {
                const theyOweUs = Number(money.amount) > 0;

                return (
                    <div key={code} className="flex flex-wrap items-baseline gap-2">
                        <MoneyDisplay {...money} signed />
                        <span
                            className={
                                'text-xs ' + (theyOweUs ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400')
                            }
                        >
                            {theyOweUs ? t('counterparties.they_owe_us') : t('counterparties.we_owe_them')}
                        </span>
                    </div>
                );
            })}
        </div>
    );
}

/**
 * Margin by month, for one currency.
 *
 * Only rendered with a currency chosen. Several currencies on one axis would compare
 * figures that share no scale — the base-currency mistake, drawn rather than written.
 */
function MonthlyProfit({ monthly, currency }: { monthly: Record<string, string>; currency: string | null }) {
    const { t } = useTranslations();

    if (currency === null) {
        return <ChartEmpty title={t('dashboard.chart.title')} message={t('dashboard.chart.pick_currency')} />;
    }

    const data = Object.entries(monthly).map(([month, amount]) => plot(month, amount));

    if (data.length === 0) {
        return <ChartEmpty title={t('dashboard.chart.title')} message={t('dashboard.no_data')} />;
    }

    return (
        <ChartPanel
            title={
                <>
                    {t('dashboard.chart.title')} · <span dir="ltr">{currency}</span>
                </>
            }
            hint={t('dashboard.chart.hint')}
        >
            <div className="h-64" dir="ltr">
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={data} margin={{ top: 8, right: 8, bottom: 8, left: 8 }}>
                        <CartesianGrid strokeDasharray="3 3" className="stroke-muted" vertical={false} />
                        <XAxis dataKey="label" tick={{ fontSize: 11 }} />
                        <YAxis tick={{ fontSize: 11 }} width={90} tickFormatter={axisTick} />
                        <Tooltip
                            cursor={{ className: 'fill-muted/40' }}
                            content={({ active, payload, label }) => {
                                const point = payload?.[0]?.payload as { exact: string } | undefined;

                                if (active !== true || point === undefined) {
                                    return null;
                                }

                                return (
                                    <ExactTooltip
                                        title={String(label)}
                                        currency={currency}
                                        rows={[{ label: t('dashboard.cards.profit'), amount: point.exact }]}
                                    />
                                );
                            }}
                        />
                        <Bar dataKey="height" className="fill-primary" radius={[2, 2, 0, 0]} />
                    </BarChart>
                </ResponsiveContainer>
            </div>
        </ChartPanel>
    );
}

/**
 * Money in against money out, month by month.
 *
 * Two bars rather than a net line. A month where a million came in and a million went
 * out is not the same as a quiet month, and a net of zero would show them identically.
 */
function MonthlyFlow({ monthly, currency }: { monthly: Record<string, { in: string; out: string }>; currency: string | null }) {
    const { t } = useTranslations();

    if (currency === null) {
        return <ChartEmpty title={t('dashboard.flow.title')} message={t('dashboard.chart.pick_currency')} />;
    }

    const data = Object.entries(monthly).map(([month, sides]) => ({
        label: month,
        inHeight: Number(sides.in),
        outHeight: Number(sides.out),
        exactIn: sides.in,
        exactOut: sides.out,
    }));

    if (data.length === 0) {
        return <ChartEmpty title={t('dashboard.flow.title')} message={t('dashboard.no_data')} />;
    }

    return (
        <ChartPanel
            title={
                <>
                    {t('dashboard.flow.title')} · <span dir="ltr">{currency}</span>
                </>
            }
            hint={t('dashboard.flow.hint')}
        >
            <div className="h-64" dir="ltr">
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={data} margin={{ top: 8, right: 8, bottom: 8, left: 8 }}>
                        <CartesianGrid strokeDasharray="3 3" className="stroke-muted" vertical={false} />
                        <XAxis dataKey="label" tick={{ fontSize: 11 }} />
                        <YAxis tick={{ fontSize: 11 }} width={90} tickFormatter={axisTick} />
                        <Tooltip
                            cursor={{ className: 'fill-muted/40' }}
                            content={({ active, payload, label }) => {
                                const point = payload?.[0]?.payload as { exactIn: string; exactOut: string } | undefined;

                                if (active !== true || point === undefined) {
                                    return null;
                                }

                                return (
                                    <ExactTooltip
                                        title={String(label)}
                                        currency={currency}
                                        rows={[
                                            { label: t('dashboard.cards.received'), amount: point.exactIn },
                                            { label: t('dashboard.cards.delivered'), amount: point.exactOut },
                                        ]}
                                    />
                                );
                            }}
                        />
                        <Legend
                            formatter={(value: string) => (value === 'inHeight' ? t('dashboard.cards.received') : t('dashboard.cards.delivered'))}
                            wrapperStyle={{ fontSize: 11 }}
                        />
                        <Bar dataKey="inHeight" className="fill-emerald-600 dark:fill-emerald-500" radius={[2, 2, 0, 0]} />
                        <Bar dataKey="outHeight" className="fill-amber-600 dark:fill-amber-500" radius={[2, 2, 0, 0]} />
                    </BarChart>
                </ResponsiveContainer>
            </div>
        </ChartPanel>
    );
}

/**
 * How many clients sit on each side.
 *
 * The one chart here that needs no currency: it counts relationships rather than
 * adding money, and counting across currencies is meaningful in a way adding is not.
 */
function StatusSplit({ counts }: { counts: Record<string, number> }) {
    const { t } = useTranslations();

    const tone: Record<string, string> = {
        owes_us: 'fill-amber-500',
        has_credit: 'fill-sky-500',
        mixed: 'fill-purple-500',
    };

    const data = Object.entries(counts)
        .filter(([, count]) => count > 0)
        .map(([status, count]) => ({ status, label: t(`dashboard.statuses.${status}`), count }));

    if (data.length === 0) {
        return <ChartEmpty title={t('dashboard.split.title')} message={t('dashboard.no_data')} />;
    }

    return (
        <ChartPanel title={t('dashboard.split.title')} hint={t('dashboard.split.hint')}>
            <div className="h-64" dir="ltr">
                <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                        <Pie data={data} dataKey="count" nameKey="label" innerRadius="45%" outerRadius="75%" paddingAngle={2}>
                            {data.map((slice) => (
                                <Cell key={slice.status} className={tone[slice.status] ?? 'fill-muted-foreground'} />
                            ))}
                        </Pie>
                        {/* Counts, so there is no exact-decimal concern here at all. */}
                        <Tooltip
                            content={({ active, payload }) => {
                                const slice = payload?.[0]?.payload as { label: string; count: number } | undefined;

                                if (active !== true || slice === undefined) {
                                    return null;
                                }

                                return (
                                    <div className="bg-background rounded-md border px-2 py-1 text-xs shadow-sm">
                                        {slice.label}: {slice.count}
                                    </div>
                                );
                            }}
                        />
                        <Legend wrapperStyle={{ fontSize: 11 }} />
                    </PieChart>
                </ResponsiveContainer>
            </div>
        </ChartPanel>
    );
}

/**
 * The largest few positions, both sides shown separately.
 *
 * A client on both sides gets two bars. One bar would have to net an obligation
 * against a holding to decide its length, which is the thing the four buckets exist to
 * prevent — see ADR 0007.
 */
function TopClients({
    clients,
    currency,
}: {
    clients: { id: number; name: string; owed_to_us: MoneyPayload; owed_to_them: MoneyPayload }[];
    currency: string | null;
}) {
    const { t } = useTranslations();

    if (currency === null) {
        return <ChartEmpty title={t('dashboard.top.title')} message={t('dashboard.chart.pick_currency')} />;
    }

    if (clients.length === 0) {
        return <ChartEmpty title={t('dashboard.top.title')} message={t('dashboard.no_data')} />;
    }

    const data = clients.map((client) => ({
        label: client.name,
        usHeight: Number(client.owed_to_us.amount),
        themHeight: Number(client.owed_to_them.amount),
        exactUs: client.owed_to_us.amount,
        exactThem: client.owed_to_them.amount,
    }));

    return (
        <ChartPanel
            title={
                <>
                    {t('dashboard.top.title')} · <span dir="ltr">{currency}</span>
                </>
            }
            hint={t('dashboard.top.hint')}
        >
            <div className="h-64" dir="ltr">
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={data} layout="vertical" margin={{ top: 8, right: 8, bottom: 8, left: 8 }}>
                        <CartesianGrid strokeDasharray="3 3" className="stroke-muted" horizontal={false} />
                        <XAxis type="number" tick={{ fontSize: 11 }} tickFormatter={axisTick} />
                        <YAxis type="category" dataKey="label" tick={{ fontSize: 11 }} width={110} />
                        <Tooltip
                            cursor={{ className: 'fill-muted/40' }}
                            content={({ active, payload, label }) => {
                                const point = payload?.[0]?.payload as { exactUs: string; exactThem: string } | undefined;

                                if (active !== true || point === undefined) {
                                    return null;
                                }

                                return (
                                    <ExactTooltip
                                        title={String(label)}
                                        currency={currency}
                                        rows={[
                                            { label: t('dashboard.cards.owed_to_us'), amount: point.exactUs },
                                            { label: t('dashboard.cards.owed_to_them'), amount: point.exactThem },
                                        ]}
                                    />
                                );
                            }}
                        />
                        <Legend
                            formatter={(value: string) =>
                                value === 'usHeight' ? t('dashboard.cards.owed_to_us') : t('dashboard.cards.owed_to_them')
                            }
                            wrapperStyle={{ fontSize: 11 }}
                        />
                        <Bar dataKey="usHeight" className="fill-amber-600 dark:fill-amber-500" radius={[0, 2, 2, 0]} />
                        <Bar dataKey="themHeight" className="fill-sky-600 dark:fill-sky-500" radius={[0, 2, 2, 0]} />
                    </BarChart>
                </ResponsiveContainer>
            </div>
        </ChartPanel>
    );
}

/**
 * Where the market is, for the person about to quote a price.
 *
 * Deliberately plain and deliberately labelled. These figures never enter a deal: the
 * rate on an exchange is typed by hand and the ledger records the two amounts that
 * actually moved, which is why a deal cannot change value after the fact. The strip
 * says when the feed last published rather than implying it is current, because the
 * free source publishes once a day and a business quoting intraday needs to know that.
 *
 * Absent entirely when the feed is off or unreachable — somebody else's outage is not a
 * reason to withhold the ledger.
 */
function ReferenceRateStrip({ rates }: { rates: Rates | null }) {
    const { t } = useTranslations();

    if (rates === null) {
        return null;
    }

    return (
        <section
            aria-label={t('dashboard.rates.title')}
            className="border-sidebar-border/70 dark:border-sidebar-border flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl border px-4 py-3"
        >
            <span className="text-muted-foreground text-xs font-medium tracking-wide uppercase">{t('dashboard.rates.title')}</span>

            <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
                {rates.quotes.map((quote) => (
                    <span key={quote.code} className="font-mono text-sm tabular-nums" dir="ltr">
                        <span className="text-muted-foreground">1 {rates.base} = </span>
                        {quote.rate} <span className="text-muted-foreground">{quote.code}</span>
                    </span>
                ))}
            </div>

            <span className="text-muted-foreground ms-auto text-xs">
                {t('dashboard.rates.updated', { at: new Date(rates.updated_at).toLocaleString() })}
            </span>
        </section>
    );
}

function Field({ label, htmlFor, children }: { label: string; htmlFor: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={htmlFor} className="text-xs">
                {label}
            </Label>
            {children}
        </div>
    );
}

function Figure({ label, amount, signed = false }: { label: string; amount?: MoneyPayload; signed?: boolean }) {
    return (
        <div className="flex items-baseline justify-between gap-4">
            <dt className="text-muted-foreground">{label}</dt>
            <dd>{amount ? <MoneyDisplay {...amount} signed={signed} /> : <span className="text-muted-foreground">—</span>}</dd>
        </div>
    );
}

function StatusBadge({ status, label }: { status: string; label: string }) {
    const tone: Record<string, string> = {
        owes_us: 'border-amber-600/40 bg-amber-600/10 text-amber-800 dark:text-amber-300',
        has_credit: 'border-sky-600/40 bg-sky-600/10 text-sky-800 dark:text-sky-300',
        mixed: 'border-purple-600/40 bg-purple-600/10 text-purple-800 dark:text-purple-300',
        settled: 'border-muted-foreground/30 text-muted-foreground',
    };

    return <span className={cn('rounded-md border px-2 py-0.5 text-xs whitespace-nowrap', tone[status])}>{label}</span>;
}

/** Drop empty filters so the URL carries only what is actually set. */
function clean(filters: Filters): Record<string, string> {
    const query: Record<string, string> = {};

    for (const [key, value] of Object.entries(filters)) {
        if (value !== null && value !== '') {
            query[key] = String(value);
        }
    }

    return query;
}
