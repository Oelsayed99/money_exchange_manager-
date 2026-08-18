import { FlashMessage } from '@/components/flash-message';
import { MoneyDisplay } from '@/components/money-display';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useTranslations } from '@/lib/i18n';
import { usePermissions } from '@/lib/permissions';
import type { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { FileText, Pencil, Plus } from 'lucide-react';

interface Position {
    bucket: string;
    currency_id: number;
    code: string | null;
    amount: string;
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

    const positionsFor = (row: CounterpartyRow, bucket: string) => row.positions.filter((position) => position.bucket === bucket);

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
                    <table className="w-full min-w-[64rem] border-collapse text-sm">
                        <thead className="bg-muted/50 sticky top-0">
                            {/* Two header rows: the buckets are grouped by side, so that
                                "owed to us" and "owed by us" are visibly different things
                                rather than four columns of undifferentiated numbers. */}
                            <tr className="text-muted-foreground text-xs">
                                <th className="px-4 pt-3" />
                                <th className="px-4 pt-3" />
                                <th
                                    className="border-sidebar-border/70 dark:border-sidebar-border border-s px-4 pt-3 text-center font-medium"
                                    colSpan={2}
                                >
                                    {t('counterparties.assets')}
                                </th>
                                <th
                                    className="border-sidebar-border/70 dark:border-sidebar-border border-s px-4 pt-3 text-center font-medium"
                                    colSpan={2}
                                >
                                    {t('counterparties.liabilities')}
                                </th>
                                <th className="px-4 pt-3" />
                                <th className="px-4 pt-3" />
                            </tr>
                            <tr className="text-muted-foreground">
                                <th scope="col" className="px-4 py-3 text-start font-medium">
                                    {t('counterparties.fields.name')}
                                </th>
                                <th scope="col" className="px-4 py-3 text-start font-medium">
                                    {t('counterparties.fields.type')}
                                </th>
                                {buckets.map((bucket, index) => (
                                    <th
                                        key={bucket.value}
                                        scope="col"
                                        title={bucket.hint}
                                        className={
                                            'px-4 py-3 text-end font-medium' +
                                            (index === 0 || index === 2 ? ' border-sidebar-border/70 dark:border-sidebar-border border-s' : '')
                                        }
                                    >
                                        {bucket.label}
                                    </th>
                                ))}
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
                                    <td colSpan={8} className="text-muted-foreground px-4 py-12 text-center">
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
                                    </td>
                                    <td className="text-muted-foreground px-4 py-3">{row.type_label}</td>

                                    {buckets.map((bucket, index) => {
                                        const positions = positionsFor(row, bucket.value);

                                        return (
                                            <td
                                                key={bucket.value}
                                                className={
                                                    'px-4 py-3 text-end' +
                                                    (index === 0 || index === 2
                                                        ? ' border-sidebar-border/70 dark:border-sidebar-border border-s'
                                                        : '')
                                                }
                                            >
                                                {positions.length === 0 ? (
                                                    <span className="text-muted-foreground">{t('counterparties.nothing_declared')}</span>
                                                ) : (
                                                    <div className="flex flex-col items-end gap-0.5">
                                                        {positions.map((position) => (
                                                            <MoneyDisplay
                                                                key={`${position.bucket}-${position.currency_id}`}
                                                                amount={position.amount}
                                                                currency={position.code ?? ''}
                                                            />
                                                        ))}
                                                    </div>
                                                )}
                                            </td>
                                        );
                                    })}

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

                <p className="text-muted-foreground max-w-3xl text-xs">{t('counterparties.opening_hint')}</p>
            </div>
        </AppLayout>
    );
}
