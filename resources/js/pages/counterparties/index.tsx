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
    bucket: string;
    currency_id: number;
    code: string | null;
    amount: string;
}

/** One currency, two sides. The four buckets travel with it for the drill-down. */
interface Standing {
    code: string;
    ours: string;
    theirs: string;
    buckets: Record<string, string>;
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

interface Bucket {
    value: string;
    label: string;
    hint: string;
    isAsset: boolean;
}

export default function CounterpartiesIndex({ counterparties, buckets }: { counterparties: CounterpartyRow[]; buckets: Bucket[] }) {
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
                                    title={t('counterparties.ours_hint')}
                                    className="border-sidebar-border/70 dark:border-sidebar-border border-s px-4 py-3 text-end font-medium"
                                >
                                    {t('counterparties.ours_with_them')}
                                </th>
                                <th
                                    scope="col"
                                    title={t('counterparties.theirs_hint')}
                                    className="border-sidebar-border/70 dark:border-sidebar-border border-s px-4 py-3 text-end font-medium"
                                >
                                    {t('counterparties.theirs_with_us')}
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
                                    <td colSpan={6} className="text-muted-foreground px-4 py-12 text-center">
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
                                        {row.positions.length > 0 && (
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

                                    <Side row={row} field="ours" />
                                    <Side row={row} field="theirs" />

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

                <p className="text-muted-foreground max-w-3xl text-xs">
                    {t('counterparties.list_hint', { buckets: buckets.map((bucket) => bucket.label).join(' · ') })}
                </p>
            </div>
        </AppLayout>
    );
}

/**
 * One side of one relationship, per currency, each figure a way in.
 *
 * The number is a link because it is a summary of something: following it opens the
 * statement for that currency, which is where the four buckets and the movements behind
 * them are.
 */
function Side({ row, field }: { row: CounterpartyRow; field: 'ours' | 'theirs' }) {
    const { t } = useTranslations();

    const carrying = row.standings.filter((standing) => standing[field] !== '0' && Number(standing[field]) !== 0);

    return (
        <td className="border-sidebar-border/70 dark:border-sidebar-border border-s px-4 py-3 text-end">
            {carrying.length === 0 ? (
                <span className="text-muted-foreground">{t('counterparties.nothing_declared')}</span>
            ) : (
                <div className="flex flex-col items-end gap-0.5">
                    {carrying.map((standing) => (
                        <Link
                            key={standing.code}
                            href={`/counterparties/${row.id}/statement?currency=${standing.code}`}
                            title={t('counterparties.open_statement', { currency: standing.code })}
                            className="hover:text-foreground focus-visible:ring-ring rounded-sm underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:outline-none"
                        >
                            <MoneyDisplay amount={standing[field]} currency={standing.code} />
                        </Link>
                    ))}
                </div>
            )}
        </td>
    );
}
