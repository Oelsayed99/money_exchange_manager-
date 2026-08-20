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
import { Head, useForm } from '@inertiajs/react';
import { AlertTriangle, LoaderCircle } from 'lucide-react';
import { useEffect, useState } from 'react';

type MoneyPayload = { amount: string; currency: string };

interface TypeOption {
    value: string;
    label: string;
    needsCounterparty: boolean;
    needsDestinationAccount: boolean;
    needsBucket: boolean;
    bucket: string | null;
    increases: boolean | null;
}

interface Props {
    types: TypeOption[];
    accounts: { id: number; name: string }[];
    currencies: { id: number; code: string }[];
    counterparties: { id: number; name: string }[];
    buckets: { value: string; label: string; position: string }[];
    methods: { value: string; label: string }[];
}

interface Positions {
    positions: Record<string, MoneyPayload>;
    after: { bucket: string; amount: MoneyPayload; increases: boolean } | null;
    /** The bucket that would go below zero, or null. A warning, never a block. */
    negative_warning: string | null;
}

type MovementForm = {
    type: string;
    occurred_at: string;
    currency_id: string;
    amount: string;
    account_id: string;
    destination_account_id: string;
    counterparty_id: string;
    bucket: string;
    method: string;
    reference: string;
    description: string;
};

const selectClass =
    'border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-3 py-1 text-sm focus-visible:ring-1 focus-visible:outline-none';

export default function RecordMovement({ types, accounts, currencies, counterparties, buckets, methods }: Props) {
    const { t } = useTranslations();

    const [state, setState] = useState<Positions | null>(null);

    const { data, setData, post, processing, errors, reset } = useForm<MovementForm>({
        type: types[0]?.value ?? '',
        occurred_at: new Date().toISOString().slice(0, 10),
        currency_id: String(currencies[0]?.id ?? ''),
        amount: '',
        account_id: String(accounts[0]?.id ?? ''),
        destination_account_id: '',
        counterparty_id: '',
        bucket: '',
        method: '',
        reference: '',
        description: '',
    });

    const selected = types.find((type) => type.value === data.type);
    const currencyCode = currencies.find((c) => String(c.id) === data.currency_id)?.code ?? '';
    const bucketLabel = (bucket: string) => buckets.find((b) => b.value === bucket)?.position ?? bucket;

    /**
     * The counterparty's four positions, and what this movement would do to one.
     *
     * From the server, debounced, using the same effect the posting rules apply — so
     * what the screen promises and what the ledger does cannot disagree.
     */
    useEffect(() => {
        if (data.counterparty_id === '' || data.currency_id === '') {
            setState(null);

            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch('/movements/positions', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent((document.cookie.match(/XSRF-TOKEN=([^;]+)/) ?? [])[1] ?? ''),
                },
                body: JSON.stringify({
                    counterparty_id: data.counterparty_id,
                    currency_id: data.currency_id,
                    type: data.type,
                    amount: data.amount,
                }),
                signal: controller.signal,
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((result: Positions | null) => setState(result))
                .catch(() => undefined);
        }, 300);

        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [data.counterparty_id, data.currency_id, data.type, data.amount]);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        post('/movements', { onSuccess: () => reset('amount', 'reference', 'description') });
    };

    const breadcrumbs: BreadcrumbItem[] = [{ title: t('movements.title'), href: '/movements' }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={t('movements.title')} />

            <div className="flex flex-col gap-6 p-4">
                <FlashMessage />

                <div className="space-y-1">
                    <h1 className="text-2xl font-semibold tracking-tight">{t('movements.title')}</h1>
                    <p className="text-muted-foreground max-w-2xl text-sm">{t('movements.description')}</p>
                </div>

                <form onSubmit={submit} className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="space-y-4">
                        <Field label={t('movements.type')} htmlFor="type" error={errors.type}>
                            <select id="type" value={data.type} onChange={(e) => setData('type', e.target.value)} className={selectClass}>
                                {types.map((type) => (
                                    <option key={type.value} value={type.value}>
                                        {type.label}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        {/* What this type does, said in words before it is done. */}
                        {selected?.bucket != null && (
                            <p className="text-muted-foreground text-xs">
                                {bucketLabel(selected.bucket)} {selected.increases === true ? t('movements.increases') : t('movements.decreases')}
                            </p>
                        )}

                        <div className="grid gap-4 sm:grid-cols-2">
                            <Field label={t('movements.amount')} htmlFor="amount" error={errors.amount}>
                                <MoneyInput id="amount" value={data.amount} onChange={(v) => setData('amount', v)} currency={currencyCode} />
                            </Field>

                            <Field label={t('movements.currency')} htmlFor="currency_id" error={errors.currency_id}>
                                <select
                                    id="currency_id"
                                    value={data.currency_id}
                                    onChange={(e) => setData('currency_id', e.target.value)}
                                    className={selectClass}
                                >
                                    {currencies.map((currency) => (
                                        <option key={currency.id} value={currency.id}>
                                            {currency.code}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <Field label={t('movements.account')} htmlFor="account_id" error={errors.account_id}>
                                <select
                                    id="account_id"
                                    value={data.account_id}
                                    onChange={(e) => setData('account_id', e.target.value)}
                                    className={selectClass}
                                >
                                    {accounts.map((account) => (
                                        <option key={account.id} value={account.id}>
                                            {account.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            {selected?.needsDestinationAccount === true && (
                                <Field
                                    label={t('movements.destination_account')}
                                    htmlFor="destination_account_id"
                                    error={errors.destination_account_id}
                                >
                                    <select
                                        id="destination_account_id"
                                        value={data.destination_account_id}
                                        onChange={(e) => setData('destination_account_id', e.target.value)}
                                        className={selectClass}
                                    >
                                        <option value="">—</option>
                                        {accounts.map((account) => (
                                            <option key={account.id} value={account.id}>
                                                {account.name}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            )}

                            <Field label={t('movements.counterparty')} htmlFor="counterparty_id" error={errors.counterparty_id}>
                                <select
                                    id="counterparty_id"
                                    value={data.counterparty_id}
                                    onChange={(e) => setData('counterparty_id', e.target.value)}
                                    className={selectClass}
                                >
                                    <option value="">—</option>
                                    {counterparties.map((party) => (
                                        <option key={party.id} value={party.id}>
                                            {party.name}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            {selected?.needsBucket === true && data.counterparty_id !== '' && (
                                <Field label={t('movements.bucket')} htmlFor="bucket" error={errors.bucket}>
                                    <select
                                        id="bucket"
                                        value={data.bucket}
                                        onChange={(e) => setData('bucket', e.target.value)}
                                        className={selectClass}
                                    >
                                        <option value="">—</option>
                                        {buckets.map((bucket) => (
                                            <option key={bucket.value} value={bucket.value}>
                                                {bucket.label}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            )}

                            <Field label={t('movements.occurred_at')} htmlFor="occurred_at" error={errors.occurred_at}>
                                <Input
                                    id="occurred_at"
                                    type="date"
                                    dir="ltr"
                                    value={data.occurred_at}
                                    onChange={(e) => setData('occurred_at', e.target.value)}
                                    required
                                />
                            </Field>

                            <Field label={t('movements.method')} htmlFor="method" error={errors.method}>
                                <select id="method" value={data.method} onChange={(e) => setData('method', e.target.value)} className={selectClass}>
                                    <option value="">—</option>
                                    {methods.map((method) => (
                                        <option key={method.value} value={method.value}>
                                            {method.label}
                                        </option>
                                    ))}
                                </select>
                            </Field>

                            <Field label={t('movements.reference')} htmlFor="reference" error={errors.reference}>
                                <Input id="reference" value={data.reference} onChange={(e) => setData('reference', e.target.value)} />
                            </Field>

                            <Field label={t('movements.note')} htmlFor="description" error={errors.description}>
                                <Input id="description" value={data.description} onChange={(e) => setData('description', e.target.value)} />
                            </Field>
                        </div>
                    </div>

                    <aside className="border-sidebar-border/70 dark:border-sidebar-border h-fit space-y-3 rounded-xl border p-4 lg:sticky lg:top-4">
                        <div>
                            <h2 className="text-sm font-medium">{t('movements.positions')}</h2>
                            <p className="text-muted-foreground text-xs">{t('movements.positions_hint')}</p>
                        </div>

                        {state === null ? (
                            <p className="text-muted-foreground text-sm">{t('movements.pick_counterparty')}</p>
                        ) : (
                            <>
                                {/* All four, zeroes included: a bucket reading 0.00 says
                                    nothing is there, where a missing row leaves the reader
                                    wondering whether it was checked. */}
                                <dl className="space-y-2 text-sm">
                                    {buckets.map((bucket) => {
                                        const amount = state.positions[bucket.value];
                                        const changes = state.after?.bucket === bucket.value;

                                        return (
                                            <div key={bucket.value} className="flex items-baseline justify-between gap-3">
                                                <dt className={cn('text-muted-foreground', changes && 'text-foreground font-medium')}>
                                                    {bucket.position}
                                                </dt>
                                                <dd>{amount !== undefined && <MoneyDisplay {...amount} signed />}</dd>
                                            </div>
                                        );
                                    })}
                                </dl>

                                {state.after !== null && (
                                    <div className="border-sidebar-border/70 dark:border-sidebar-border space-y-1 border-t pt-3">
                                        <div className="text-muted-foreground text-xs">{t('movements.after')}</div>
                                        <div className="flex items-baseline justify-between gap-3 text-sm">
                                            <span>{bucketLabel(state.after.bucket)}</span>
                                            <MoneyDisplay {...state.after.amount} signed className="font-medium" />
                                        </div>
                                    </div>
                                )}

                                {/* The owner's decision: a credit may go negative, always
                                    allowed. Said out loud, never blocked. */}
                                {state.negative_warning !== null && (
                                    <div className="space-y-1 rounded-lg border border-amber-600/40 bg-amber-600/10 p-3">
                                        <div className="flex items-center gap-2 text-sm font-medium text-amber-800 dark:text-amber-300">
                                            <AlertTriangle className="size-4 shrink-0" aria-hidden="true" />
                                            {t('movements.negative')}
                                        </div>
                                        <p className="text-xs text-amber-800 dark:text-amber-300">{t('movements.negative_body')}</p>
                                    </div>
                                )}
                            </>
                        )}

                        <Button type="submit" className="w-full" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />}
                            {t('movements.record')}
                        </Button>
                    </aside>
                </form>
            </div>
        </AppLayout>
    );
}

function Field({ label, htmlFor, error, children }: { label: string; htmlFor: string; error?: string; children: React.ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={htmlFor} className="text-xs">
                {label}
            </Label>
            {children}
            <InputError message={error} />
        </div>
    );
}
