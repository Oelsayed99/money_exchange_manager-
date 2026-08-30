import { FlashMessage } from '@/components/flash-message';
import { MoneyDisplay } from '@/components/money-display';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useTranslations } from '@/lib/i18n';
import { usePermissions } from '@/lib/permissions';
import type { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, FileText, Pencil, Plus } from 'lucide-react';

interface Position {
    currency_id: number;
    code: string | null;
    amount: string;
    unposted: string;
}

/** One currency, one signed balance. Positive means they owe us. */
interface Standing {
    code: string;
    balance: string;
}

interface CounterpartyRow {
    id: number;
    name: string;
    type_label: string;
    phone: string | null;
    country: string | null;
    preferred_currency_code: string | null;
    is_active: boolean;
    positions: Position[];
    standings: Standing[];
}

export default function CounterpartiesIndex({ counterparties }: { counterparties: CounterpartyRow[] }) {
    const { t } = useTranslations();
    const { can } = usePermissions();
    const canManage = can('counterparties.manage');

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.counterparties'), href: '/counterparties' }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('counterparties.title')} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <FlashMessage />

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">{t('counterparties.title')}</h1>
                        <p className="text-muted-foreground max-w-2xl text-sm">{t('counterparties.description')}</p>
                    </div>

                    {canManage && (
                        <Button asChild>
                            <Link href="/counterparties/create">
                                <Plus className="size-4" aria-hidden="true" />
                                {t('common.create')}
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[56rem] border-collapse text-sm">
                        <thead className="bg-muted/50 sticky top-0">
                            {/* Two columns, not four. Which side the money is on is the
                                question a list answers; which bucket it sits in is the
                                question the statement answers. */}
                            <tr className="text-muted-foreground">
                                <th scope="col" className="px-4 py-3 text-start font-medium">
                                    {t('counterparties.fields.name')}
                                </th>
                                <th scope="col" className="px-4 py-3 text-start font-medium">
                                    {t('counterparties.fields.type')}
                                </th>
                                <th
                                    scope="col"
                                    title={t('counterparties.balance_hint')}
                                    className="border-sidebar-border/70 dark:border-sidebar-border border-s px-4 py-3 text-end font-medium"
                                >
                                    {t('counterparties.balance')}
                                </th>
                                <th scope="col" className="px-4 py-3 text-start font-medium">
                                    {t('common.status')}
                                </th>
                                <th scope="col" className="px-4 py-3 text-end font-medium">
                                    {t('common.actions')}
                                </th>
                            </tr>
                        </thead>

                        <tbody>
                            {counterparties.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-muted-foreground px-4 py-12 text-center">
                                        {t('counterparties.empty')}
                                    </td>
                                </tr>
                            )}

                            {counterparties.map((row) => (
                                <tr key={row.id} className="border-sidebar-border/70 dark:border-sidebar-border border-t align-top">
                                    <td className="px-4 py-3 font-medium">
                                        <div>{row.name}</div>
                                        {row.phone && (
                                            <div className="text-muted-foreground font-mono text-xs" dir="ltr">
                                                {row.phone}
                                            </div>
                                        )}
                                        {/* An opening somebody typed but never posted is not
                                            in the ledger, so it is not in the figures beside
                                            it. Saying nothing would make the row look wrong
                                            to the person who typed it. */}
                                        {row.positions.some((position) => Number(position.unposted) !== 0) && (
                                            <div
                                                className="mt-1 flex items-center gap-1 text-xs text-amber-700 dark:text-amber-400"
                                                title={t('counterparties.unposted_opening_hint')}
                                            >
                                                <AlertTriangle className="size-3.5 shrink-0" aria-hidden="true" />
                                                {t('counterparties.unposted_opening')}
                                            </div>
                                        )}
                                    </td>
                                    <td className="text-muted-foreground px-4 py-3">{row.type_label}</td>

                                    <Balance row={row} />

                                    <td className="px-4 py-3">
                                        <StatusBadge active={row.is_active} />
                                    </td>
                                    <td className="px-4 py-3 text-end">
                                        <Button variant="ghost" size="sm" asChild>
                                            <Link href={`/counterparties/${row.id}/statement`}>
                                                <FileText className="size-4" aria-hidden="true" />
                                                {t('statements.title')}
                                            </Link>
                                        </Button>
                                        {canManage && (
                                            <Button variant="ghost" size="sm" asChild>
                                                <Link href={`/counterparties/${row.id}/edit`}>
                                                    <Pencil className="size-4" aria-hidden="true" />
                                                    {t('common.edit')}
                                                </Link>
                                            </Button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <p className="text-muted-foreground max-w-3xl text-xs">{t('counterparties.list_hint')}</p>
            </div>
        </AppLayout>
    );
}

/**
 * Where one relationship stands, per currency — one signed figure.
 *
 * There were two columns here, "our money with them" and "their money with us", and the
 * owner's objection was exact: those cannot both be true, they are one thing and its
 * difference. Positive means they owe us; negative means we owe them. The colour and
 * the words beneath say which, so nobody reads a minus sign wrong on a screen.
 *
 * The number is a link: following it opens the statement for that currency, where the
 * movements behind the figure are.
 */
function Balance({ row }: { row: CounterpartyRow }) {
    const { t } = useTranslations();

    const carrying = row.standings.filter((standing) => Number(standing.balance) !== 0);

    if (carrying.length === 0) {
        return (
            <td className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-s px-4 py-3 text-end">
                {t('counterparties.settled')}
            </td>
        );
    }

    return (
        <td className="border-sidebar-border/70 dark:border-sidebar-border border-s px-4 py-3 text-end">
            <div className="flex flex-col items-end gap-1">
                {carrying.map((standing) => {
                    const theyOweUs = Number(standing.balance) > 0;

                    return (
                        <Link
                            key={standing.code}
                            href={`/counterparties/${row.id}/statement?currency=${standing.code}`}
                            className="focus-visible:ring-ring flex flex-col items-end rounded-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <MoneyDisplay amount={standing.balance} currency={standing.code} signed />
                            <span className={'text-[11px] ' + (theyOweUs ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400')}>
                                {theyOweUs ? t('counterparties.they_owe_us') : t('counterparties.we_owe_them')}
                            </span>
                        </Link>
                    );
                })}
            </div>
        </td>
    );
}
