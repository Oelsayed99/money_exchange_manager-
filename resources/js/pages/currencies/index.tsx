import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { useTranslations } from '@/lib/i18n';
import { usePermissions } from '@/lib/permissions';
import type { BreadcrumbItem, SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Check, CircleSlash, Pencil, Plus } from 'lucide-react';

interface CurrencyRow {
    id: number;
    code: string;
    name: string;
    name_ar: string | null;
    symbol: string | null;
    decimal_places: number;
    is_active: boolean;
    sort_order: number;
    /** Money always crosses the wire as a string, never a JSON number. */
    sample: { amount: string; currency: string };
}

export default function CurrenciesIndex({ currencies }: { currencies: CurrencyRow[] }) {
    const { t } = useTranslations();
    const { can } = usePermissions();
    const canManage = can('currencies.manage');
    const flash = usePage<SharedData>().props.flash;

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('nav.currencies'), href: '/currencies' }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('currencies.title')} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4">
                {flash.success && (
                    <div
                        role="status"
                        className="flex items-center gap-2 rounded-lg border border-emerald-600/30 bg-emerald-600/10 px-4 py-3 text-sm text-emerald-700 dark:text-emerald-400"
                    >
                        <Check className="size-4 shrink-0" aria-hidden="true" />
                        <span>{flash.success}</span>
                    </div>
                )}

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <h1 className="text-2xl font-semibold tracking-tight">{t('currencies.title')}</h1>
                        <p className="text-muted-foreground max-w-2xl text-sm">{t('currencies.description')}</p>
                    </div>

                    {canManage && (
                        <Button asChild>
                            <Link href="/currencies/create">
                                <Plus className="size-4" aria-hidden="true" />
                                {t('common.create')}
                            </Link>
                        </Button>
                    )}
                </div>

                {/* Wide content scrolls inside its own container so the page body never
                    scrolls horizontally on a narrow screen (Section 13). */}
                <div className="border-sidebar-border/70 dark:border-sidebar-border overflow-x-auto rounded-xl border">
                    <table className="w-full min-w-[56rem] border-collapse text-sm">
                        <thead className="bg-muted/50 sticky top-0">
                            <tr className="text-muted-foreground text-start">
                                <th scope="col" className="px-4 py-3 text-start font-medium">
                                    {t('currencies.fields.code')}
                                </th>
                                <th scope="col" className="px-4 py-3 text-start font-medium">
                                    {t('currencies.fields.name')}
                                </th>
                                <th scope="col" className="px-4 py-3 text-start font-medium">
                                    {t('currencies.fields.symbol')}
                                </th>
                                <th scope="col" className="px-4 py-3 text-start font-medium">
                                    {t('currencies.fields.decimal_places')}
                                </th>
                                <th scope="col" className="px-4 py-3 text-end font-medium">
                                    {t('currencies.sample')}
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
                            {currencies.length === 0 && (
                                <tr>
                                    <td colSpan={7} className="text-muted-foreground px-4 py-12 text-center">
                                        {t('currencies.empty')}
                                    </td>
                                </tr>
                            )}

                            {currencies.map((currency) => (
                                <tr key={currency.id} className="border-sidebar-border/70 dark:border-sidebar-border border-t">
                                    <td className="px-4 py-3 font-mono font-medium">{currency.code}</td>
                                    <td className="px-4 py-3">
                                        <div>{currency.name}</div>
                                        {currency.name_ar && (
                                            <div className="text-muted-foreground text-xs" dir="rtl">
                                                {currency.name_ar}
                                            </div>
                                        )}
                                    </td>
                                    <td className="px-4 py-3">{currency.symbol}</td>
                                    <td className="px-4 py-3 tabular-nums">{currency.decimal_places}</td>
                                    {/* Shown at this currency's declared precision. Padded to it, never cut down to it. */}
                                    <td className="px-4 py-3 text-end font-mono tabular-nums" dir="ltr">
                                        {currency.sample.amount}
                                    </td>
                                    <td className="px-4 py-3">
                                        {/* Status carries an icon and a label, never colour alone (Section 13). */}
                                        {currency.is_active ? (
                                            <span className="inline-flex items-center gap-1.5 text-emerald-700 dark:text-emerald-400">
                                                <Check className="size-4" aria-hidden="true" />
                                                {t('common.active')}
                                            </span>
                                        ) : (
                                            <span className="text-muted-foreground inline-flex items-center gap-1.5">
                                                <CircleSlash className="size-4" aria-hidden="true" />
                                                {t('common.inactive')}
                                            </span>
                                        )}
                                    </td>
                                    <td className="px-4 py-3 text-end">
                                        {canManage && (
                                            <Button variant="ghost" size="sm" asChild>
                                                <Link href={`/currencies/${currency.id}/edit`}>
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
