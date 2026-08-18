import { MoneyDisplay } from '@/components/money-display';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslations } from '@/lib/i18n';
import { groupDigits } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { FileText } from 'lucide-react';
import { Bar, BarChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';

type MoneyPayload = { amount: string; currency: string };
type ByCurrency = Record<string, MoneyPayload>;

interface PartyRow {
    id: number;
    name: string;
    status: string;
    status_by_currency: Record<string, string>;
    positions: Record<string, ByCurrency>;
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
    counterparties: PartyRow[];
}

interface Filters {
    counterparty: number | null;
    currency: string | null;
    status: string | null;
    from: string | null;
    to: string | null;
}

interface Props {
    dashboard: DashboardData;
    filters: Filters;
    options: {
        counterparties: { id: number; name: string }[];
        currencies: { code: string }[];
        statuses: { value: string; label: string }[];
        buckets: { value: string; label: string }[];
    };
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-3 py-1 text-sm focus-visible:ring-1 focus-visible:outline-none';

export default function Dashboard({ dashboard, filters, options }: Props) {
    const { t } = useTranslations();

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('dashboard.title'), href: '/dashboard' }];

    const apply = (changes: Partial<Filters>) =>
        router.get('/dashboard', clean({ ...filters, ...changes }), { preserveState: true, preserveScroll: true });

    const filtered = Object.values(filters).some((value) => value !== null && value !== '');
    const bucketLabel = (bucket: string) => options.buckets.find((b) => b.value === bucket)?.label ?? bucket;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('dashboard.title')} />

            <div className="flex flex-col gap-6 p-4">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">{t('dashboard.title')}</h1>
                    <p className="text-muted-foreground text-sm">{t('dashboard.description')}</p>
                </div>

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

                        <MonthlyProfit monthly={dashboard.monthly_profit} currency={filters.currency} />

                        <div className="space-y-2">
                            <h2 className="text-sm font-medium">{t('dashboard.parties.title')}</h2>

                            <div className="overflow-x-auto rounded-lg border">
                                <table className="w-full min-w-2xl text-sm">
                                    <thead className="bg-muted/50 text-muted-foreground">
                                        <tr>
                                            <th className="p-2 text-start font-medium">{t('dashboard.parties.name')}</th>
                                            <th className="p-2 text-start font-medium">{t('dashboard.parties.status')}</th>
                                            <th className="p-2 text-start font-medium">{t('dashboard.parties.positions')}</th>
                                            <th className="p-2" />
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
                                                    {/* Per currency, per bucket, each labelled. Never one
                                                        combined figure — see ADR 0007. */}
                                                    <div className="space-y-1">
                                                        {Object.entries(party.positions).map(([code, buckets]) =>
                                                            Object.entries(buckets).map(([bucket, amount]) => (
                                                                <div key={`${code}-${bucket}`} className="flex flex-wrap items-baseline gap-2">
                                                                    <MoneyDisplay {...amount} />
                                                                    <span className="text-muted-foreground text-xs">{bucketLabel(bucket)}</span>
                                                                </div>
                                                            )),
                                                        )}
                                                    </div>
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
 * Margin by month, for one currency.
 *
 * Only rendered with a currency chosen. Several currencies on one axis would compare
 * figures that share no scale — the base-currency mistake, drawn rather than written.
 *
 * The bar heights go through `Number`, because SVG coordinates are floats and no chart
 * can avoid that. Every figure a reader actually sees — axis ticks, tooltip — is
 * rendered from the exact decimal string.
 */
function MonthlyProfit({ monthly, currency }: { monthly: Record<string, string>; currency: string | null }) {
    const { t } = useTranslations();

    if (currency === null) {
        return (
            <div className="rounded-xl border p-4">
                <h2 className="text-sm font-medium">{t('dashboard.chart.title')}</h2>
                <p className="text-muted-foreground mt-1 text-sm">{t('dashboard.chart.pick_currency')}</p>
            </div>
        );
    }

    const data = Object.entries(monthly).map(([month, amount]) => ({
        month,
        exact: amount,
        height: Number(amount),
    }));

    if (data.length === 0) {
        return (
            <div className="rounded-xl border p-4">
                <h2 className="text-sm font-medium">{t('dashboard.chart.title')}</h2>
                <p className="text-muted-foreground mt-1 text-sm">{t('dashboard.no_data')}</p>
            </div>
        );
    }

    return (
        <div className="space-y-2 rounded-xl border p-4">
            <div>
                <h2 className="text-sm font-medium">
                    {t('dashboard.chart.title')} · <span dir="ltr">{currency}</span>
                </h2>
                <p className="text-muted-foreground text-xs">{t('dashboard.chart.hint')}</p>
            </div>

            <div className="h-64" dir="ltr">
                <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={data} margin={{ top: 8, right: 8, bottom: 8, left: 8 }}>
                        <CartesianGrid strokeDasharray="3 3" className="stroke-muted" vertical={false} />
                        <XAxis dataKey="month" tick={{ fontSize: 11 }} />
                        <YAxis tick={{ fontSize: 11 }} width={90} tickFormatter={(value: number) => groupDigits(String(value))} />
                        <Tooltip
                            content={({ active, payload, label }) => {
                                const point = payload?.[0]?.payload as { exact: string } | undefined;

                                if (active !== true || point === undefined) {
                                    return null;
                                }

                                return (
                                    <div className="bg-background rounded-md border px-2 py-1 text-xs shadow-sm">
                                        <div className="text-muted-foreground">{String(label)}</div>
                                        {/* The exact figure, not the plotted one. */}
                                        <MoneyDisplay amount={point.exact} currency={currency} signed />
                                    </div>
                                );
                            }}
                        />
                        <Bar dataKey="height" fill="currentColor" className="fill-primary" radius={[2, 2, 0, 0]} />
                    </BarChart>
                </ResponsiveContainer>
            </div>
        </div>
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
