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

interface HeldCurrency {
    currency_id: number;
    code: string;
    opening_balance: string;
}

interface AccountResource {
    id: number;
    name: string;
    type: string;
    counterparty_id: number | null;
    owner: string | null;
    provider: string | null;
    is_active: boolean;
    sort_order: number;
    currencies: HeldCurrency[];
}

interface Props {
    account: AccountResource | null;
    accountTypes: { value: string; label: string; isLiability: boolean }[];
    availableCurrencies: { id: number; code: string; decimal_places: number }[];
    counterparties: { id: number; name: string }[];
}

type AccountForm = {
    name: string;
    type: string;
    counterparty_id: string;
    owner: string;
    provider: string;
    identifier: string;
    is_active: boolean;
    sort_order: number;
    currencies: { currency_id: number; opening_balance: string }[];
};

export default function AccountFormPage({ account, accountTypes, availableCurrencies, counterparties }: Props) {
    const { t } = useTranslations();
    const isEdit = account !== null;

    const { data, setData, post, put, processing, errors } = useForm<AccountForm>({
        name: account?.name ?? '',
        type: account?.type ?? 'bank',
        counterparty_id: account?.counterparty_id ? String(account.counterparty_id) : '',
        owner: account?.owner ?? '',
        provider: account?.provider ?? '',
        // Never prefilled. The stored value is only ever shown masked, so putting it in
        // an editable field would defeat that; leaving it blank keeps the existing one.
        identifier: '',
        is_active: account?.is_active ?? true,
        sort_order: account?.sort_order ?? 0,
        currencies: account?.currencies.map((held) => ({ currency_id: held.currency_id, opening_balance: held.opening_balance })) ?? [],
    });

    const title = isEdit ? t('accounts.edit_title', { name: account.name }) : t('accounts.create_title');
    const selectedType = accountTypes.find((type) => type.value === data.type);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.accounts'), href: '/accounts' },
        { title, href: '#' },
    ];

    const isHeld = (currencyId: number) => data.currencies.some((held) => held.currency_id === currencyId);

    const toggleCurrency = (currencyId: number) => {
        setData(
            'currencies',
            isHeld(currencyId)
                ? data.currencies.filter((held) => held.currency_id !== currencyId)
                : [...data.currencies, { currency_id: currencyId, opening_balance: '0' }],
        );
    };

    const setBalance = (currencyId: number, amount: string) => {
        setData(
            'currencies',
            data.currencies.map((held) => (held.currency_id === currencyId ? { ...held, opening_balance: amount } : held)),
        );
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (isEdit) {
            put(`/accounts/${account.id}`);
        } else {
            post('/accounts');
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="p-4">
                <form onSubmit={submit} className="max-w-2xl space-y-6">
                    <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>

                    <div className="grid gap-2">
                        <Label htmlFor="name">{t('accounts.fields.name')}</Label>
                        <Input id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} required />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="type">{t('accounts.fields.type')}</Label>
                        <select
                            id="type"
                            value={data.type}
                            onChange={(e) => setData('type', e.target.value)}
                            className="border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-3 py-1 text-sm focus-visible:ring-1 focus-visible:outline-none"
                        >
                            {accountTypes.map((type) => (
                                <option key={type.value} value={type.value}>
                                    {type.label}
                                </option>
                            ))}
                        </select>
                        {selectedType?.isLiability && <p className="text-xs text-amber-700 dark:text-amber-400">{t('accounts.liability_note')}</p>}
                        <InputError message={errors.type} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="counterparty_id">{t('accounts.fields.counterparty')}</Label>
                        <select
                            id="counterparty_id"
                            value={data.counterparty_id}
                            onChange={(e) => setData('counterparty_id', e.target.value)}
                            className="border-input bg-background focus-visible:ring-ring h-9 rounded-md border px-3 py-1 text-sm focus-visible:ring-1 focus-visible:outline-none"
                        >
                            <option value="">{t('accounts.none')}</option>
                            {counterparties.map((party) => (
                                <option key={party.id} value={party.id}>
                                    {party.name}
                                </option>
                            ))}
                        </select>
                        <p className="text-muted-foreground text-xs">{t('accounts.hints.counterparty')}</p>
                        <InputError message={errors.counterparty_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="owner">{t('accounts.fields.owner')}</Label>
                        <Input id="owner" value={data.owner} onChange={(e) => setData('owner', e.target.value)} />
                        <InputError message={errors.owner} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="provider">{t('accounts.fields.provider')}</Label>
                        <Input id="provider" value={data.provider} onChange={(e) => setData('provider', e.target.value)} />
                        <InputError message={errors.provider} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="identifier">{t('accounts.fields.identifier')}</Label>
                        <Input
                            id="identifier"
                            value={data.identifier}
                            onChange={(e) => setData('identifier', e.target.value)}
                            dir="ltr"
                            className="font-mono"
                            autoComplete="off"
                        />
                        <p className="text-muted-foreground text-xs">{t('accounts.hints.identifier')}</p>
                        <InputError message={errors.identifier} />
                    </div>

                    <fieldset className="border-sidebar-border/70 dark:border-sidebar-border grid gap-3 rounded-lg border p-4">
                        <legend className="px-1 text-sm font-medium">{t('accounts.fields.currencies')}</legend>
                        <p className="text-muted-foreground text-xs">{t('accounts.hints.currencies')}</p>

                        {availableCurrencies.map((currency) => {
                            const held = data.currencies.find((row) => row.currency_id === currency.id);

                            return (
                                <div key={currency.id} className="flex flex-wrap items-center gap-3">
                                    <label className="flex w-28 items-center gap-2 text-sm">
                                        <Checkbox checked={held !== undefined} onClick={() => toggleCurrency(currency.id)} />
                                        <span className="font-mono">{currency.code}</span>
                                    </label>

                                    {held !== undefined && (
                                        <div className="flex-1">
                                            <MoneyInput
                                                value={held.opening_balance}
                                                onChange={(amount) => setBalance(currency.id, amount)}
                                                currency={currency.code}
                                            />
                                        </div>
                                    )}
                                </div>
                            );
                        })}

                        <InputError message={errors.currencies} />
                    </fieldset>

                    <div className="grid gap-2">
                        <Label htmlFor="sort_order">{t('accounts.fields.sort_order')}</Label>
                        <Input
                            id="sort_order"
                            type="number"
                            min={0}
                            value={data.sort_order}
                            onChange={(e) => setData('sort_order', Number(e.target.value))}
                            dir="ltr"
                            required
                        />
                        <InputError message={errors.sort_order} />
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox id="is_active" checked={data.is_active} onClick={() => setData('is_active', !data.is_active)} />
                        <Label htmlFor="is_active">{t('accounts.fields.is_active')}</Label>
                    </div>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />}
                            {t('common.save')}
                        </Button>
                        <Button type="button" variant="ghost" asChild>
                            <Link href="/accounts">{t('common.cancel')}</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
