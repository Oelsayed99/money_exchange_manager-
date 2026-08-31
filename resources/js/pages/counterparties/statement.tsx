import { MoneyDisplay } from '@/components/money-display';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslations } from '@/lib/i18n';
import { groupDigits } from '@/lib/money';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Download, Printer, Sheet } from 'lucide-react';

type MoneyPayload = { amount: string; currency: string };

interface Row {
    transaction_id: number;
    type: string;
    type_label: string;
    occurred_at: string;
    reference: string | null;
    description: string | null;
    in: MoneyPayload | null;
    out: MoneyPayload | null;
    balance_after: MoneyPayload;
    /** What actually changed hands, when it was not this statement's currency. */
    moved: MoneyPayload | null;
    rate: string | null;
    profit: MoneyPayload | null;
}

interface Statement {
    currency: string;
    decimal_places: number;
    mode: string;
    shows_profit: boolean;
    rows: Row[];
    opening: MoneyPayload;
    closing: MoneyPayload;
    total_in: MoneyPayload;
    total_out: MoneyPayload;
    /** Which way the closing balance runs, said rather than left to a minus sign. */
    they_owe_us: boolean;
    /** Null in client mode — the figures were never queried, not merely hidden. */
    profit: Record<string, MoneyPayload> | null;
    declared_opening: MoneyPayload | null;
}

interface Props {
    counterparty: { id: number; name: string };
    currencies: { id: number; code: string }[];
    statement: Statement | null;
    filters: { currency: string | null; mode: string; from: string | null; to: string | null };
    modes: { value: string; label: string }[];
}

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-3 py-1 text-sm focus-visible:ring-1 focus-visible:outline-none';

export default function CounterpartyStatement({ counterparty, currencies, statement, filters, modes }: Props) {
    const { t } = useTranslations();

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.counterparties'), href: '/counterparties' },
        { title: counterparty.name, href: `/counterparties/${counterparty.id}/statement` },
    ];

    /**
     * Filters live in the URL.
     *
     * A statement is something people send each other links to and print; the mode and
     * the period it was run for have to survive that. `only` keeps the round trip to
     * the props that actually change.
     */
    const apply = (changes: Partial<typeof filters>) =>
        router.get(`/counterparties/${counterparty.id}/statement`, { ...filters, ...changes }, { preserveState: true, preserveScroll: true });

    const internal = statement?.shows_profit ?? false;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${counterparty.name} — ${t('statements.title')}`} />

            <div className="flex flex-col gap-6 p-4 print:p-0">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">{counterparty.name}</h1>
                        <p className="text-muted-foreground text-sm">
                            {t('statements.title')}
                            {statement && ` · ${statement.currency}`}
                            {statement && ` · ${modes.find((m) => m.value === statement.mode)?.label ?? ''}`}
                        </p>
                    </div>

                    {statement && (
                        <div className="flex gap-2 print:hidden">
                            <Button type="button" variant="outline" onClick={() => window.print()}>
                                <Printer className="size-4" aria-hidden="true" />
                                {t('statements.print')}
                            </Button>

                            {/* A normal link, not a fetch: the browser handles the
                                download, and the query string carries the same mode and
                                period as the page being looked at. */}
                            <Button variant="outline" asChild>
                                <a href={`/counterparties/${counterparty.id}/statement/csv?${pdfQuery(filters)}`}>
                                    <Sheet className="size-4" aria-hidden="true" />
                                    {t('common.export_csv')}
                                </a>
                            </Button>

                            <Button asChild>
                                <a href={`/counterparties/${counterparty.id}/statement/pdf?${pdfQuery(filters)}`}>
                                    <Download className="size-4" aria-hidden="true" />
                                    {t('statements.download_pdf')}
                                </a>
                            </Button>
                        </div>
                    )}
                </div>

                {statement === null ? (
                    <p className="text-muted-foreground rounded-lg border p-6 text-sm">{t('statements.no_currencies')}</p>
                ) : (
                    <>
                        {/* One currency at a time. In, out and a position only mean
                            something inside a single currency. */}
                        <div className="flex flex-wrap items-end gap-3 print:hidden">
                            <div className="grid gap-1.5">
                                <Label htmlFor="currency" className="text-xs">
                                    {t('statements.currency')}
                                </Label>
                                <select
                                    id="currency"
                                    value={filters.currency ?? ''}
                                    onChange={(e) => apply({ currency: e.target.value })}
                                    className={selectClass}
                                >
                                    {currencies.map((c) => (
                                        <option key={c.id} value={c.code}>
                                            {c.code}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="mode" className="text-xs">
                                    {t('statements.mode')}
                                </Label>
                                <select id="mode" value={filters.mode} onChange={(e) => apply({ mode: e.target.value })} className={selectClass}>
                                    {modes.map((m) => (
                                        <option key={m.value} value={m.value}>
                                            {m.label}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="from" className="text-xs">
                                    {t('statements.from')}
                                </Label>
                                <Input
                                    id="from"
                                    type="date"
                                    dir="ltr"
                                    value={filters.from ?? ''}
                                    onChange={(e) => apply({ from: e.target.value })}
                                    className="w-40"
                                />
                            </div>

                            <div className="grid gap-1.5">
                                <Label htmlFor="to" className="text-xs">
                                    {t('statements.to')}
                                </Label>
                                <Input
                                    id="to"
                                    type="date"
                                    dir="ltr"
                                    value={filters.to ?? ''}
                                    onChange={(e) => apply({ to: e.target.value })}
                                    className="w-40"
                                />
                            </div>

                            {(filters.from || filters.to) && (
                                <Button type="button" variant="ghost" size="sm" onClick={() => apply({ from: null, to: null })}>
                                    {t('statements.clear_dates')}
                                </Button>
                            )}
                        </div>

                        <p className="text-muted-foreground text-xs">
                            {internal ? t('statements.mode_hint_internal') : t('statements.mode_hint_client')}
                        </p>

                        {statement.declared_opening !== null && (
                            <div className="space-y-1 rounded-lg border border-amber-600/40 bg-amber-600/10 p-3">
                                <div className="flex items-center gap-2 text-sm font-medium text-amber-800 dark:text-amber-300">
                                    <AlertTriangle className="size-4 shrink-0" aria-hidden="true" />
                                    {t('statements.declared_opening')}
                                </div>
                                <p className="text-xs text-amber-800 dark:text-amber-300">{t('statements.declared_opening_body')}</p>
                                <div className="text-xs text-amber-800 dark:text-amber-300">
                                    {statement.declared_opening && <MoneyDisplay {...statement.declared_opening} signed />}
                                </div>
                            </div>
                        )}

                        <ClosingBalance statement={statement} />

                        <div className="overflow-x-auto rounded-lg border">
                            <table className="w-full min-w-3xl text-sm">
                                <thead className="bg-muted/50 text-muted-foreground">
                                    <tr>
                                        <th scope="col" className="p-2 text-start font-medium">
                                            {t('statements.columns.date')}
                                        </th>
                                        <th scope="col" className="p-2 text-start font-medium">
                                            {t('statements.columns.details')}
                                        </th>
                                        <th scope="col" className="p-2 text-end font-medium" title={t('statements.in_hint')}>
                                            {t('statements.columns.in')}
                                        </th>
                                        <th scope="col" className="p-2 text-end font-medium" title={t('statements.out_hint')}>
                                            {t('statements.columns.out')}
                                        </th>
                                        <th scope="col" className="p-2 text-end font-medium">
                                            {t('statements.columns.position')}
                                        </th>
                                        {internal && (
                                            <th scope="col" className="p-2 text-end font-medium">
                                                {t('statements.columns.profit')}
                                            </th>
                                        )}
                                    </tr>
                                </thead>

                                <tbody>
                                    {statement.rows.length === 0 && (
                                        <tr>
                                            <td colSpan={internal ? 6 : 5} className="text-muted-foreground p-6 text-center">
                                                {t('statements.no_activity')}
                                            </td>
                                        </tr>
                                    )}

                                    {statement.rows.map((row, index) => (
                                        <tr key={`${row.transaction_id}-${index}`} className="border-t">
                                            <td className="p-2 whitespace-nowrap tabular-nums" dir="ltr">
                                                {row.occurred_at}
                                            </td>
                                            <td className="p-2">
                                                <div>{row.type_label}</div>
                                                {(row.description || row.reference) && (
                                                    <div className="text-muted-foreground text-xs">
                                                        {[row.description, row.reference].filter(Boolean).join(' · ')}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="p-2 text-end">{row.in && <MoneyDisplay {...row.in} />}</td>
                                            <td className="p-2 text-end">{row.out && <MoneyDisplay {...row.out} />}</td>
                                            <td className="p-2 text-end">
                                                <MoneyDisplay {...row.balance_after} signed />
                                                {/* What actually changed hands, when the line
                                                    was booked in another currency. */}
                                                {row.moved && (
                                                    <div className="text-muted-foreground text-xs" dir="ltr">
                                                        {t('statements.moved_as', {
                                                            amount: `${groupDigits(row.moved.amount)} ${row.moved.currency}`,
                                                            rate: row.rate ?? '—',
                                                        })}
                                                    </div>
                                                )}
                                            </td>
                                            {internal && <td className="p-2 text-end">{row.profit && <MoneyDisplay {...row.profit} signed />}</td>}
                                        </tr>
                                    ))}
                                </tbody>

                                {statement.rows.length > 0 && (
                                    <tfoot className="bg-muted/30 border-t font-medium">
                                        <tr>
                                            <td className="p-2" colSpan={2}>
                                                {t('statements.totals')}
                                            </td>
                                            <td className="p-2 text-end">
                                                <MoneyDisplay {...statement.total_in} />
                                            </td>
                                            <td className="p-2 text-end">
                                                <MoneyDisplay {...statement.total_out} />
                                            </td>
                                            <td className="p-2 text-end">
                                                <MoneyDisplay {...statement.closing} signed />
                                            </td>
                                            {internal && <td />}
                                        </tr>
                                    </tfoot>
                                )}
                            </table>
                        </div>

                        {internal && statement.profit && Object.keys(statement.profit).length > 0 && (
                            <div className="flex flex-wrap items-center gap-3 rounded-lg border p-3 text-sm">
                                <span className="font-medium">{t('statements.profit_total')}</span>
                                {Object.entries(statement.profit).map(([code, amount]) => (
                                    <MoneyDisplay key={code} {...amount} signed />
                                ))}
                            </div>
                        )}

                        <p className="text-muted-foreground text-xs">{t('statements.from_ledger')}</p>
                    </>
                )}
            </div>
        </AppLayout>
    );
}

/**
 * Where the two of you stand, said in words as well as figures.
 *
 * One signed number now, where there were four labelled ones. A minus sign is the
 * easiest thing on a screen to misread, so the sentence carries the meaning and the
 * figure is printed without its sign. See ADR 0032.
 */
function ClosingBalance({ statement }: { statement: Statement }) {
    const { t } = useTranslations();

    if (isZero(statement.closing.amount)) {
        return <p className="rounded-lg border p-4 text-sm">{t('statements.settled_now')}</p>;
    }

    const theyOweUs = statement.they_owe_us;
    const magnitude = { ...statement.closing, amount: statement.closing.amount.replace('-', '') };

    return (
        <div className={'rounded-lg border-s-4 p-4 ' + (theyOweUs ? 'border-s-green-600 bg-green-600/5' : 'border-s-red-600 bg-red-600/5')}>
            <div className="text-muted-foreground text-xs">{t('statements.closing')}</div>
            <div className="mt-1 text-lg">
                <MoneyDisplay {...magnitude} />
            </div>
            <div className={'text-sm ' + (theyOweUs ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400')}>
                {theyOweUs ? t('counterparties.they_owe_us') : t('counterparties.we_owe_them')}
            </div>
        </div>
    );
}

/**
 * The current filters as a query string, dropping the ones that are not set.
 *
 * The document has to be the page: same currency, same mode, same period. Sending an
 * empty `from` would be read as a filter rather than the absence of one.
 */
function pdfQuery(filters: { currency: string | null; mode: string; from: string | null; to: string | null }): string {
    const query = new URLSearchParams();

    for (const [key, value] of Object.entries(filters)) {
        if (value) {
            query.set(key, value);
        }
    }

    return query.toString();
}

/**
 * Whether a decimal string is zero, without parsing it.
 *
 * `Number(amount) === 0` would be the obvious way and is the wrong one: it puts a
 * float64 in the path of a figure the whole application exists to keep exact. A
 * decimal string is zero when nothing but zeros, a point and a sign remain.
 */
function isZero(amount: string): boolean {
    return amount.replace(/[-0.]/g, '') === '';
}
