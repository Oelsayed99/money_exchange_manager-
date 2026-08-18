import { FlashMessage } from '@/components/flash-message';
import InputError from '@/components/input-error';
import { MoneyDisplay } from '@/components/money-display';
import { MoneyInput } from '@/components/money-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslations } from '@/lib/i18n';
import { cn } from '@/lib/utils';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Eye } from 'lucide-react';
import { useState } from 'react';

type MoneyPayload = { amount: string; currency: string };

interface Row {
    id: number;
    as_of: string;
    account: string | null;
    currency: string | null;
    counted: MoneyPayload;
    ledger: MoneyPayload;
    difference: MoneyPayload;
    status: string;
    status_label: string;
    is_surplus: boolean;
    note: string | null;
    resolution: string | null;
    resolved_by: string | null;
    resolved_at: string | null;
    adjustment_transaction_id: number | null;
    /** Non-null means the ledger moved after the count — something was backdated. */
    drift: MoneyPayload | null;
}

interface Props {
    reconciliations: Row[];
    filters: { account: number | null; currency: string | null; status: string | null };
    options: {
        accounts: { id: number; name: string }[];
        currencies: { id: number; code: string }[];
        statuses: { value: string; label: string }[];
    };
    can: { manage: boolean };
}

type RecordForm = {
    account_id: string;
    currency_id: string;
    as_of: string;
    counted_amount: string;
    note: string;
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-3 py-1 text-sm focus-visible:ring-1 focus-visible:outline-none';

export default function ReconciliationsIndex({ reconciliations, filters, options, can }: Props) {
    const { t } = useTranslations();

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('reconciliations.title'), href: '/reconciliations' }];

    const apply = (changes: Partial<typeof filters>) =>
        router.get('/reconciliations', clean({ ...filters, ...changes }), { preserveState: true, preserveScroll: true });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('reconciliations.title')} />

            <div className="flex flex-col gap-6 p-4">
                <FlashMessage />

                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">{t('reconciliations.title')}</h1>
                    <p className="text-muted-foreground max-w-2xl text-sm">{t('reconciliations.description')}</p>
                </div>

                {can.manage && <RecordCount options={options} />}

                <div className="flex flex-wrap items-end gap-3">
                    <Field label={t('reconciliations.account')} htmlFor="filter_account">
                        <select
                            id="filter_account"
                            value={filters.account ?? ''}
                            onChange={(e) => apply({ account: e.target.value === '' ? null : Number(e.target.value) })}
                            className={selectClass}
                        >
                            <option value="">{t('reconciliations.all')}</option>
                            {options.accounts.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label={t('reconciliations.currency')} htmlFor="filter_currency">
                        <select
                            id="filter_currency"
                            value={filters.currency ?? ''}
                            onChange={(e) => apply({ currency: e.target.value === '' ? null : e.target.value })}
                            className={selectClass}
                        >
                            <option value="">{t('reconciliations.all')}</option>
                            {options.currencies.map((c) => (
                                <option key={c.id} value={c.code}>
                                    {c.code}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field label={t('reconciliations.statuses.balanced')} htmlFor="filter_status">
                        <select
                            id="filter_status"
                            value={filters.status ?? ''}
                            onChange={(e) => apply({ status: e.target.value === '' ? null : e.target.value })}
                            className={selectClass}
                        >
                            <option value="">{t('reconciliations.all')}</option>
                            {options.statuses.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                </div>

                {reconciliations.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border p-6 text-sm">{t('reconciliations.none')}</p>
                ) : (
                    <div className="space-y-3">
                        {reconciliations.map((row) => (
                            <ReconciliationCard key={row.id} row={row} canManage={can.manage} />
                        ))}
                    </div>
                )}

                <p className="text-muted-foreground text-xs">{t('reconciliations.read_only')}</p>
            </div>
        </AppLayout>
    );
}

/**
 * Recording a count.
 *
 * The ledger's figure is not prefilled and is hidden behind a button. Showing somebody
 * the expected number in the box they are about to type their count into invites them
 * to agree with it, and a reconciliation that agrees by suggestion has checked nothing.
 */
function RecordCount({ options }: { options: Props['options'] }) {
    const { t } = useTranslations();
    const [expected, setExpected] = useState<MoneyPayload | null>(null);

    const { data, setData, post, processing, errors, reset } = useForm<RecordForm>({
        account_id: String(options.accounts[0]?.id ?? ''),
        currency_id: String(options.currencies[0]?.id ?? ''),
        as_of: new Date().toISOString().slice(0, 10),
        counted_amount: '',
        note: '',
    });

    const currencyCode = options.currencies.find((c) => String(c.id) === data.currency_id)?.code ?? '';

    const reveal = () => {
        fetch('/reconciliations/expected', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? ''),
            },
            body: JSON.stringify({ account_id: data.account_id, currency_id: data.currency_id, as_of: data.as_of }),
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((result: { ledger_amount: MoneyPayload } | null) => setExpected(result?.ledger_amount ?? null))
            .catch(() => undefined);
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post('/reconciliations', {
            preserveScroll: true,
            onSuccess: () => {
                reset('counted_amount', 'note');
                setExpected(null);
            },
        });
    };

    return (
        <form onSubmit={submit} className="border-sidebar-border/70 dark:border-sidebar-border grid gap-4 rounded-xl border p-4">
            <h2 className="text-sm font-medium">{t('reconciliations.record')}</h2>

            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <Field label={t('reconciliations.account')} htmlFor="account_id">
                    <select
                        id="account_id"
                        value={data.account_id}
                        onChange={(e) => {
                            setData('account_id', e.target.value);
                            setExpected(null);
                        }}
                        className={selectClass}
                    >
                        {options.accounts.map((a) => (
                            <option key={a.id} value={a.id}>
                                {a.name}
                            </option>
                        ))}
                    </select>
                </Field>

                <Field label={t('reconciliations.currency')} htmlFor="currency_id">
                    <select
                        id="currency_id"
                        value={data.currency_id}
                        onChange={(e) => {
                            setData('currency_id', e.target.value);
                            setExpected(null);
                        }}
                        className={selectClass}
                    >
                        {options.currencies.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.code}
                            </option>
                        ))}
                    </select>
                </Field>

                <Field label={t('reconciliations.as_of')} htmlFor="as_of">
                    <Input
                        id="as_of"
                        type="date"
                        dir="ltr"
                        value={data.as_of}
                        onChange={(e) => {
                            setData('as_of', e.target.value);
                            setExpected(null);
                        }}
                        required
                    />
                    <InputError message={errors.as_of} />
                </Field>

                <Field label={t('reconciliations.counted')} htmlFor="counted_amount">
                    <MoneyInput
                        id="counted_amount"
                        value={data.counted_amount}
                        onChange={(v) => setData('counted_amount', v)}
                        currency={currencyCode}
                    />
                    <InputError message={errors.counted_amount} />
                </Field>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="note" className="text-xs">
                    {t('reconciliations.note')}
                </Label>
                <Input id="note" value={data.note} onChange={(e) => setData('note', e.target.value)} />
            </div>

            <div className="flex flex-wrap items-center gap-3">
                <Button type="submit" disabled={processing}>
                    {t('reconciliations.record')}
                </Button>

                {expected === null ? (
                    <>
                        <Button type="button" variant="ghost" size="sm" onClick={reveal}>
                            <Eye className="size-4" aria-hidden="true" />
                            {t('reconciliations.show_expected')}
                        </Button>
                        <span className="text-muted-foreground text-xs">{t('reconciliations.expected_hidden')}</span>
                    </>
                ) : (
                    <span className="text-muted-foreground flex items-baseline gap-2 text-sm">
                        {t('reconciliations.ledger')}
                        <MoneyDisplay {...expected} />
                    </span>
                )}
            </div>

            <p className="text-muted-foreground text-xs">{t('reconciliations.counted_hint')}</p>
        </form>
    );
}

function ReconciliationCard({ row, canManage }: { row: Row; canManage: boolean }) {
    const { t } = useTranslations();
    const [explaining, setExplaining] = useState(false);

    const { data, setData, post, processing, errors } = useForm({ resolution: '', adjustment_transaction_id: '' });

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post(`/reconciliations/${row.id}/resolve`, { preserveScroll: true, onSuccess: () => setExplaining(false) });
    };

    return (
        <div className="space-y-3 rounded-xl border p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="font-medium">
                        {row.account} · <span dir="ltr">{row.currency}</span>
                    </div>
                    <div className="text-muted-foreground text-xs tabular-nums" dir="ltr">
                        {row.as_of}
                    </div>
                </div>

                <StatusBadge status={row.status} label={row.status_label} />
            </div>

            <dl className="grid gap-2 text-sm sm:grid-cols-3">
                <Figure label={t('reconciliations.counted')} amount={row.counted} />
                <Figure label={t('reconciliations.ledger')} amount={row.ledger} />
                <div className="flex items-baseline justify-between gap-4 sm:block">
                    <dt className="text-muted-foreground">{t('reconciliations.difference')}</dt>
                    <dd>
                        <MoneyDisplay {...row.difference} signed />
                        {row.status !== 'balanced' && (
                            <div className="text-muted-foreground text-xs">
                                {row.is_surplus ? t('reconciliations.surplus') : t('reconciliations.shortfall')}
                            </div>
                        )}
                    </dd>
                </div>
            </dl>

            {row.note !== null && <p className="text-muted-foreground text-xs">{row.note}</p>}

            {/* The ledger moved after the count: something dated on or before this day
                was posted later. Worth surfacing — the sign-off no longer describes it. */}
            {row.drift !== null && (
                <div className="space-y-1 rounded-lg border border-amber-600/40 bg-amber-600/10 p-3">
                    <div className="flex items-center gap-2 text-sm font-medium text-amber-800 dark:text-amber-300">
                        <AlertTriangle className="size-4 shrink-0" aria-hidden="true" />
                        {t('reconciliations.drift')}
                        <MoneyDisplay {...row.drift} signed />
                    </div>
                    <p className="text-xs text-amber-800 dark:text-amber-300">{t('reconciliations.drift_hint')}</p>
                </div>
            )}

            {row.resolution !== null && (
                <div className="border-sidebar-border/70 dark:border-sidebar-border space-y-1 border-t pt-3 text-sm">
                    <p>{row.resolution}</p>
                    <p className="text-muted-foreground text-xs">
                        {row.resolved_by !== null &&
                            row.resolved_at !== null &&
                            t('reconciliations.explained_by', { name: row.resolved_by, date: row.resolved_at })}
                        {row.adjustment_transaction_id !== null &&
                            ` · ${t('reconciliations.adjusted_by', { id: String(row.adjustment_transaction_id) })}`}
                    </p>
                </div>
            )}

            {canManage && row.status === 'open' && !explaining && (
                <Button type="button" variant="outline" size="sm" onClick={() => setExplaining(true)}>
                    {t('reconciliations.explain')}
                </Button>
            )}

            {explaining && (
                <form onSubmit={submit} className="border-sidebar-border/70 dark:border-sidebar-border space-y-3 border-t pt-3">
                    <div className="grid gap-2">
                        <Label htmlFor={`resolution-${row.id}`} className="text-xs">
                            {t('reconciliations.resolution')}
                        </Label>
                        <Input id={`resolution-${row.id}`} value={data.resolution} onChange={(e) => setData('resolution', e.target.value)} required />
                        <p className="text-muted-foreground text-xs">{t('reconciliations.resolution_hint')}</p>
                        <InputError message={errors.resolution} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor={`adjustment-${row.id}`} className="text-xs">
                            {t('reconciliations.adjustment')}
                        </Label>
                        <Input
                            id={`adjustment-${row.id}`}
                            inputMode="numeric"
                            dir="ltr"
                            className="w-40"
                            value={data.adjustment_transaction_id}
                            onChange={(e) => setData('adjustment_transaction_id', e.target.value.replace(/\D/g, ''))}
                        />
                        <InputError message={errors.adjustment_transaction_id} />
                    </div>

                    <Button type="submit" size="sm" disabled={processing}>
                        {t('reconciliations.explain')}
                    </Button>
                </form>
            )}
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

function Figure({ label, amount }: { label: string; amount: MoneyPayload }) {
    return (
        <div className="flex items-baseline justify-between gap-4 sm:block">
            <dt className="text-muted-foreground">{label}</dt>
            <dd>
                <MoneyDisplay {...amount} />
            </dd>
        </div>
    );
}

function StatusBadge({ status, label }: { status: string; label: string }) {
    const tone: Record<string, string> = {
        balanced: 'border-emerald-600/40 bg-emerald-600/10 text-emerald-800 dark:text-emerald-300',
        open: 'border-amber-600/40 bg-amber-600/10 text-amber-800 dark:text-amber-300',
        resolved: 'border-sky-600/40 bg-sky-600/10 text-sky-800 dark:text-sky-300',
    };

    return <span className={cn('rounded-md border px-2 py-0.5 text-xs whitespace-nowrap', tone[status])}>{label}</span>;
}

function clean(filters: Props['filters']): Record<string, string> {
    const query: Record<string, string> = {};

    for (const [key, value] of Object.entries(filters)) {
        if (value !== null && value !== '') {
            query[key] = String(value);
        }
    }

    return query;
}
