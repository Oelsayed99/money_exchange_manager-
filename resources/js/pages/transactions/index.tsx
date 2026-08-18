import { MoneyDisplay } from '@/components/money-display';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslations } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowDownLeft, ArrowUpRight } from 'lucide-react';
import { useEffect, useState } from 'react';

type MoneyPayload = { amount: string; currency: string };

interface Leg {
    role: string;
    role_label: string;
    is_inflow: boolean;
    amount: MoneyPayload;
    account: string | null;
}

interface Row {
    id: number;
    type: string;
    type_label: string;
    status: string;
    status_label: string;
    occurred_at: string;
    counterparty: { id: number; name: string } | null;
    reference: string | null;
    description: string | null;
    is_reversal: boolean;
    reverses_id: number | null;
    legs: Leg[];
}

interface Filters {
    type: string | null;
    status: string | null;
    counterparty: number | null;
    currency: string | null;
    from: string | null;
    to: string | null;
    search: string | null;
}

interface Props {
    transactions: {
        data: Row[];
        links: { prev: string | null; next: string | null };
        meta: { current_page: number; last_page: number; total: number; from: number | null; to: number | null };
    };
    filters: Filters;
    options: {
        types: { value: string; label: string }[];
        statuses: { value: string; label: string }[];
        counterparties: { id: number; name: string }[];
        currencies: { code: string }[];
    };
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-3 py-1 text-sm focus-visible:ring-1 focus-visible:outline-none';

export default function TransactionsIndex({ transactions, filters, options }: Props) {
    const { t } = useTranslations();

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('transactions.list.title'), href: '/transactions' }];

    const apply = (changes: Partial<Filters>) =>
        router.get('/transactions', clean({ ...filters, ...changes }), { preserveState: true, preserveScroll: true });

    // Typing shouldn't fire a request per keystroke, and shouldn't lose keystrokes to
    // a round trip either, so the field is local and the query is debounced.
    const [search, setSearch] = useState(filters.search ?? '');

    useEffect(() => {
        if (search === (filters.search ?? '')) {
            return;
        }

        const timer = setTimeout(() => apply({ search: search === '' ? null : search }), 350);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const filtered = Object.values(filters).some((value) => value !== null && value !== '');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('transactions.list.title')} />

            <div className="flex flex-col gap-6 p-4">
                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">{t('transactions.list.title')}</h1>
                    <p className="text-muted-foreground text-sm">{t('transactions.list.description')}</p>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <Field label={t('transactions.list.filters.type')} htmlFor="type">
                        <select
                            id="type"
                            value={filters.type ?? ''}
                            onChange={(e) => apply({ type: e.target.value === '' ? null : e.target.value })}
                            className={selectClass}
                        >
                            <option value="">{t('transactions.list.filters.all')}</option>
                            {options.types.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label={t('transactions.list.filters.status')} htmlFor="status">
                        <select
                            id="status"
                            value={filters.status ?? ''}
                            onChange={(e) => apply({ status: e.target.value === '' ? null : e.target.value })}
                            className={selectClass}
                        >
                            <option value="">{t('transactions.list.filters.all')}</option>
                            {options.statuses.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label={t('transactions.list.filters.counterparty')} htmlFor="counterparty">
                        <select
                            id="counterparty"
                            value={filters.counterparty ?? ''}
                            onChange={(e) => apply({ counterparty: e.target.value === '' ? null : Number(e.target.value) })}
                            className={selectClass}
                        >
                            <option value="">{t('transactions.list.filters.all')}</option>
                            {options.counterparties.map((party) => (
                                <option key={party.id} value={party.id}>
                                    {party.name}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label={t('transactions.list.filters.currency')} htmlFor="currency">
                        <select
                            id="currency"
                            value={filters.currency ?? ''}
                            onChange={(e) => apply({ currency: e.target.value === '' ? null : e.target.value })}
                            className={selectClass}
                        >
                            <option value="">{t('transactions.list.filters.all')}</option>
                            {options.currencies.map((currency) => (
                                <option key={currency.code} value={currency.code}>
                                    {currency.code}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label={t('transactions.list.filters.from')} htmlFor="from">
                        <Input
                            id="from"
                            type="date"
                            dir="ltr"
                            className="w-40"
                            value={filters.from ?? ''}
                            onChange={(e) => apply({ from: e.target.value === '' ? null : e.target.value })}
                        />
                    </Field>

                    <Field label={t('transactions.list.filters.to')} htmlFor="to">
                        <Input
                            id="to"
                            type="date"
                            dir="ltr"
                            className="w-40"
                            value={filters.to ?? ''}
                            onChange={(e) => apply({ to: e.target.value === '' ? null : e.target.value })}
                        />
                    </Field>

                    <Field label={t('transactions.list.filters.search')} htmlFor="search">
                        <Input
                            id="search"
                            type="search"
                            className="w-56"
                            placeholder={t('transactions.list.filters.search_placeholder')}
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </Field>

                    {filtered && (
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            onClick={() => {
                                setSearch('');
                                router.get('/transactions', {}, { preserveScroll: true });
                            }}
                        >
                            {t('transactions.list.filters.clear')}
                        </Button>
                    )}
                </div>

                <div className="overflow-x-auto rounded-lg border">
                    <table className="w-full min-w-3xl text-sm">
                        <thead className="bg-muted/50 text-muted-foreground">
                            <tr>
                                <th className="p-2 text-start font-medium">{t('transactions.list.columns.date')}</th>
                                <th className="p-2 text-start font-medium">{t('transactions.list.columns.type')}</th>
                                <th className="p-2 text-start font-medium">{t('transactions.list.columns.counterparty')}</th>
                                <th className="p-2 text-start font-medium">{t('transactions.list.columns.movement')}</th>
                                <th className="p-2 text-start font-medium">{t('transactions.list.columns.reference')}</th>
                                <th className="p-2 text-start font-medium">{t('transactions.list.columns.status')}</th>
                            </tr>
                        </thead>

                        <tbody>
                            {transactions.data.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="text-muted-foreground p-6 text-center">
                                        {t('transactions.list.none')}
                                    </td>
                                </tr>
                            )}

                            {transactions.data.map((row) => (
                                <tr key={row.id} className="border-t align-top">
                                    <td className="p-2 whitespace-nowrap tabular-nums" dir="ltr">
                                        {row.occurred_at}
                                    </td>
                                    <td className="p-2">
                                        <div>{row.type_label}</div>
                                        {row.is_reversal && row.reverses_id !== null && (
                                            <div className="text-muted-foreground text-xs">
                                                {t('transactions.list.reversal_of', { id: String(row.reverses_id) })}
                                            </div>
                                        )}
                                    </td>
                                    <td className="p-2">
                                        {row.counterparty === null ? (
                                            <span className="text-muted-foreground">—</span>
                                        ) : (
                                            <Link
                                                href={`/counterparties/${row.counterparty.id}/statement`}
                                                className="underline-offset-2 hover:underline"
                                            >
                                                {row.counterparty.name}
                                            </Link>
                                        )}
                                    </td>
                                    <td className="p-2">
                                        {/* Every leg, each with its own currency. An exchange
                                            has two and they are different currencies, which is
                                            why there is no single amount column. */}
                                        <div className="space-y-1">
                                            {row.legs.map((leg, index) => (
                                                <div key={`${leg.role}-${index}`} className="flex flex-wrap items-baseline gap-2">
                                                    {leg.is_inflow ? (
                                                        <ArrowDownLeft
                                                            className="size-3.5 shrink-0 text-emerald-600 dark:text-emerald-400"
                                                            aria-hidden="true"
                                                        />
                                                    ) : (
                                                        <ArrowUpRight
                                                            className="size-3.5 shrink-0 text-amber-600 dark:text-amber-400"
                                                            aria-hidden="true"
                                                        />
                                                    )}
                                                    <MoneyDisplay {...leg.amount} />
                                                    <span className="text-muted-foreground text-xs">
                                                        {leg.role_label}
                                                        {leg.account !== null && ` · ${leg.account}`}
                                                    </span>
                                                </div>
                                            ))}
                                        </div>
                                    </td>
                                    <td className="p-2">
                                        <div>{row.reference ?? <span className="text-muted-foreground">—</span>}</div>
                                        {row.description !== null && <div className="text-muted-foreground text-xs">{row.description}</div>}
                                    </td>
                                    <td className="p-2">
                                        <StatusBadge status={row.status} label={row.status_label} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-muted-foreground text-xs">
                        {transactions.meta.total > 0 &&
                            t('transactions.list.showing', {
                                from: String(transactions.meta.from ?? 0),
                                to: String(transactions.meta.to ?? 0),
                                total: String(transactions.meta.total),
                            })}
                    </p>

                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" disabled={transactions.links.prev === null} asChild={transactions.links.prev !== null}>
                            {transactions.links.prev === null ? (
                                <span>{t('transactions.list.previous')}</span>
                            ) : (
                                <Link href={transactions.links.prev} preserveScroll>
                                    {t('transactions.list.previous')}
                                </Link>
                            )}
                        </Button>

                        <Button variant="outline" size="sm" disabled={transactions.links.next === null} asChild={transactions.links.next !== null}>
                            {transactions.links.next === null ? (
                                <span>{t('transactions.list.next')}</span>
                            ) : (
                                <Link href={transactions.links.next} preserveScroll>
                                    {t('transactions.list.next')}
                                </Link>
                            )}
                        </Button>
                    </div>
                </div>

                <p className="text-muted-foreground text-xs">{t('transactions.list.read_only')}</p>
            </div>
        </AppLayout>
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

function StatusBadge({ status, label }: { status: string; label: string }) {
    const tone: Record<string, string> = {
        draft: 'border-muted-foreground/30 text-muted-foreground',
        pending: 'border-amber-600/40 bg-amber-600/10 text-amber-800 dark:text-amber-300',
        posted: 'border-emerald-600/40 bg-emerald-600/10 text-emerald-800 dark:text-emerald-300',
        reversed: 'border-red-600/40 bg-red-600/10 text-red-800 dark:text-red-300',
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
