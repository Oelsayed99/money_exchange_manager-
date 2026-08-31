import InputError from '@/components/input-error';
import { MoneyInput } from '@/components/money-input';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslations } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';

// A type alias, not an interface: Inertia's useForm requires an implicit index
// signature, which TypeScript gives to aliases but not to interfaces.
type Position = {
    currency_id: number;
    amount: string;
};

interface CounterpartyResource {
    id: number;
    name: string;
    type: string;
    phone: string | null;
    email: string | null;
    country: string | null;
    preferred_currency_id: number | null;
    is_active: boolean;
    positions: Position[];
}

interface Props {
    counterparty: CounterpartyResource | null;
    counterpartyTypes: { value: string; label: string }[];
    availableCurrencies: { id: number; code: string }[];
}

type CounterpartyForm = {
    name: string;
    type: string;
    phone: string;
    email: string;
    country: string;
    preferred_currency_id: string;
    is_active: boolean;
    positions: Position[];
};

export default function CounterpartyFormPage({ counterparty, counterpartyTypes, availableCurrencies }: Props) {
    const { t } = useTranslations();
    const isEdit = counterparty !== null;

    const { data, setData, post, put, processing, errors } = useForm<CounterpartyForm>({
        name: counterparty?.name ?? '',
        type: counterparty?.type ?? 'customer',
        phone: counterparty?.phone ?? '',
        email: counterparty?.email ?? '',
        country: counterparty?.country ?? '',
        preferred_currency_id: counterparty?.preferred_currency_id ? String(counterparty.preferred_currency_id) : '',
        is_active: counterparty?.is_active ?? true,
        positions: counterparty?.positions ?? [],
    });

    const title = isEdit ? t('counterparties.edit_title', { name: counterparty.name }) : t('counterparties.create_title');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.counterparties'), href: '/counterparties' },
        { title, href: '#' },
    ];

    const amountFor = (currencyId: number) => data.positions.find((p) => p.currency_id === currencyId)?.amount ?? '';

    const setAmount = (currencyId: number, amount: string) => {
        const others = data.positions.filter((p) => p.currency_id !== currencyId);

        // An empty field means "not declared", which is different from a declared zero.
        setData('positions', amount === '' ? others : [...others, { currency_id: currencyId, amount }]);
    };

    const errorFor = (currencyId: number): string | undefined => {
        const index = data.positions.findIndex((p) => p.currency_id === currencyId);

        if (index === -1) {
            return undefined;
        }

        return (errors as Record<string, string | undefined>)[`positions.${index}.amount`];
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (isEdit) {
            put(`/counterparties/${counterparty.id}`);
        } else {
            post('/counterparties');
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="p-4">
                <form onSubmit={submit} className="max-w-3xl space-y-6">
                    <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>

                    <div className="grid gap-2">
                        <Label htmlFor="name">{t('counterparties.fields.name')}</Label>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="type">{t('counterparties.fields.type')}</Label>
                        <select
                            id="type"
                            value={data.type}
                            onChange={(e) => setData('type', e.target.value)}
                            className="border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-3 py-1 text-sm focus-visible:ring-1 focus-visible:outline-none"
                        >
                            {counterpartyTypes.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.type} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="phone">{t('counterparties.fields.phone')}</Label>
                            <Input id="phone" value={data.phone} onChange={(e) => setData('phone', e.target.value)} dir="ltr" />
                            <InputError message={errors.phone} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="email">{t('counterparties.fields.email')}</Label>
                            <Input id="email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} dir="ltr" />
                            <InputError message={errors.email} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="country">{t('counterparties.fields.country')}</Label>
                            <Input
                                id="country"
                                value={data.country}
                                onChange={(e) => setData('country', e.target.value.toUpperCase())}
                                maxLength={2}
                                dir="ltr"
                                className="font-mono uppercase"
                            />
                            <InputError message={errors.country} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="preferred_currency_id">{t('counterparties.fields.preferred_currency')}</Label>
                            <select
                                id="preferred_currency_id"
                                value={data.preferred_currency_id}
                                onChange={(e) => setData('preferred_currency_id', e.target.value)}
                                className="border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-3 py-1 text-sm focus-visible:ring-1 focus-visible:outline-none"
                            >
                                <option value="">—</option>
                                {availableCurrencies.map((currency) => (
                                    <option key={currency.id} value={currency.id}>
                                        {currency.code}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.preferred_currency_id} />
                        </div>
                    </div>

                    {/* One figure per currency, and it may be negative — which is the
                        whole of what the four columns here used to say. */}
                    <fieldset className="border-sidebar-border/70 dark:border-sidebar-border grid gap-4 rounded-lg border p-4">
                        <legend className="px-1 text-sm font-medium">{t('counterparties.opening_positions')}</legend>
                        <p className="text-muted-foreground text-xs">{t('counterparties.opening_hint')}</p>

                        <div className="grid gap-3 sm:grid-cols-2">
                            {availableCurrencies.map((currency) => {
                                const amount = amountFor(currency.id);
                                const value = Number(amount);

                                return (
                                    <div key={currency.id} className="grid gap-1.5">
                                        <Label htmlFor={`position-${currency.id}`} className="font-mono text-sm">
                                            {currency.code}
                                        </Label>
                                        <MoneyInput id={`position-${currency.id}`} value={amount} onChange={(next) => setAmount(currency.id, next)} />
                                        {amount !== '' && value !== 0 && (
                                            <p
                                                className={
                                                    'text-[11px] ' +
                                                    (value > 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400')
                                                }
                                            >
                                                {value > 0 ? t('counterparties.they_owe_us') : t('counterparties.we_owe_them')}
                                            </p>
                                        )}
                                        <InputError message={errorFor(currency.id)} />
                                    </div>
                                );
                            })}
                        </div>
                    </fieldset>

                    <div className="flex items-center gap-2">
                        <Checkbox id="is_active" checked={data.is_active} onClick={() => setData('is_active', !data.is_active)} />
                        <Label htmlFor="is_active">{t('counterparties.fields.is_active')}</Label>
                    </div>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />}
                            {t('common.save')}
                        </Button>
                        <Button type="button" variant="ghost" asChild>
                            <Link href="/counterparties">{t('common.cancel')}</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
