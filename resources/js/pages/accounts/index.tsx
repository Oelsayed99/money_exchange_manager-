import { FlashMessage } from '@/components/flash-message';
import { MoneyDisplay } from '@/components/money-display';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useTranslations } from '@/lib/i18n';
import { usePermissions } from '@/lib/permissions';
import type { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';

interface AccountRow {
    id: number;
    name: string;
    type: string;
    type_label: string;
    is_liability: boolean;
    counterparty_name: string | null;
    owner: string | null;
    provider: string | null;
    masked_identifier: string | null;
    is_active: boolean;
    currencies: { currency_id: number; code: string; opening_balance: string }[];
}

export default function AccountsIndex({ accounts }: { accounts: AccountRow[] }) {
    const { t } = useTranslations();
    const { can } = usePermissions();
    const canManage = can('accounts.manage');

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.accounts'), href: '/accounts' }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('accounts.title')} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                <FlashMessage />

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">{t('accounts.title')}</h1>
                        <p className="text-muted-foreground max-w-2xl text-sm">{t('accounts.description')}</p>
                    </div>

                    {canManage && (
                        <Button asChild>
                            <Link href="/accounts/create">
                                <Plus className="size-4" aria-hidden="true" />
                                {t('common.create')}
                            </Link>
                        </Button>
                    )}
                </div>

                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[60rem] border-collapse text-sm">
                        <thead className="bg-muted/50 sticky top-0">
                            <tr className="text-muted-foreground">
                                <th scope="col" className="px-4 py-3 text-start font-medium">
                                    {t('accounts.fields.name')}
                                </th>
                                <th scope="col" className="px-4 py-3 text-start font-medium">
                                    {t('accounts.fields.type')}
                                </th>
                                <th scope="col" className="px-4 py-3 text-start font-medium">
                                    {t('accounts.fields.counterparty')}
                                </th>
                                <th scope="col" className="px-4 py-3 text-start font-medium">
                                    {t('accounts.fields.provider')}
                                </th>
                                <th scope="col" className="px-4 py-3 text-start font-medium">
                                    {t('accounts.fields.identifier')}
                                </th>
                                <th scope="col" className="px-4 py-3 text-end font-medium">
                                    {t('accounts.fields.opening_balance')}
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
                            {accounts.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="text-muted-foreground px-4 py-12 text-center">
                                        {t('accounts.empty')}
                                    </td>
                                </tr>
                            )}

                            {accounts.map((account) => (
                                <tr key={account.id} className="border-sidebar-border/70 dark:border-sidebar-border border-t align-top">
                                    <td className="px-4 py-3 font-medium">{account.name}</td>
                                    <td className="px-4 py-3">
                                        <div>{account.type_label}</div>
                                        {/* A liability holds someone else's money; saying so beats a colour. */}
                                        {account.is_liability && (
                                            <div className="text-xs text-amber-700 dark:text-amber-400">{t('accounts.liability_note')}</div>
                                        )}
                                    </td>
                                    <td className="text-muted-foreground px-4 py-3">{account.counterparty_name ?? t('accounts.none')}</td>
                                    <td className="text-muted-foreground px-4 py-3">{account.provider}</td>
                                    <td className="px-4 py-3 font-mono text-xs" dir="ltr">
                                        {account.masked_identifier}
                                    </td>
                                    <td className="px-4 py-3 text-end">
                                        {account.currencies.length === 0 ? (
                                            <span className="text-muted-foreground">—</span>
                                        ) : (
                                            <div className="flex flex-col items-end gap-0.5">
                                                {account.currencies.map((held) => (
                                                    <MoneyDisplay key={held.currency_id} amount={held.opening_balance} currency={held.code} />
                                                ))}
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">
                                        <StatusBadge active={account.is_active} />
                                    </td>
                                    <td className="px-4 py-3 text-end">
                                        {canManage && (
                                            <Button variant="ghost" size="sm" asChild>
                                                <Link href={`/accounts/${account.id}/edit`}>
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
            </div>
        </AppLayout>
    );
}
