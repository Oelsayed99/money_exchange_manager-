import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { useTranslations } from '@/lib/i18n';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';

interface CurrencyResource {
    id: number;
    code: string;
    name: string;
    name_ar: string | null;
    symbol: string | null;
    decimal_places: number;
    is_active: boolean;
    sort_order: number;
}

type CurrencyForm = {
    code: string;
    name: string;
    name_ar: string;
    symbol: string;
    decimal_places: number;
    is_active: boolean;
    sort_order: number;
};

interface Props {
    currency: CurrencyResource | null;
}

export default function CurrencyFormPage({ currency }: Props) {
    const { t } = useTranslations();
    const isEdit = currency !== null;

    const { data, setData, post, put, processing, errors } = useForm<CurrencyForm>({
        code: currency?.code ?? '',
        name: currency?.name ?? '',
        name_ar: currency?.name_ar ?? '',
        symbol: currency?.symbol ?? '',
        decimal_places: currency?.decimal_places ?? 2,
        is_active: currency?.is_active ?? true,
        sort_order: currency?.sort_order ?? 0,
    });

    const title = isEdit ? t('currencies.edit_title', { code: currency.code }) : t('currencies.create_title');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: t('nav.currencies'), href: '/currencies' },
        { title, href: '#' },
    ];

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        if (isEdit) {
            put(`/currencies/${currency.id}`);
        } else {
            post('/currencies');
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={title} />

            <div className="p-4">
                <form onSubmit={submit} className="max-w-2xl space-y-6">
                    <h1 className="text-2xl font-semibold tracking-tight">{title}</h1>

                    <div className="grid gap-2">
                        <Label htmlFor="code">{t('currencies.fields.code')}</Label>
                        <Input
                            id="code"
                            value={data.code}
                            onChange={(event) => setData('code', event.target.value.toUpperCase())}
                            className="font-mono"
                            dir="ltr"
                            required
                        />
                        <p className="text-muted-foreground text-xs">{t('currencies.hints.code')}</p>
                        <InputError message={errors.code} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="name">{t('currencies.fields.name')}</Label>
                        <Input id="name" value={data.name} onChange={(event) => setData('name', event.target.value)} required />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="name_ar">{t('currencies.fields.name_ar')}</Label>
                        {/* Always RTL regardless of interface language: the field holds Arabic. */}
                        <Input id="name_ar" value={data.name_ar} onChange={(event) => setData('name_ar', event.target.value)} dir="rtl" />
                        <InputError message={errors.name_ar} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="symbol">{t('currencies.fields.symbol')}</Label>
                        <Input id="symbol" value={data.symbol} onChange={(event) => setData('symbol', event.target.value)} />
                        <InputError message={errors.symbol} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="decimal_places">{t('currencies.fields.decimal_places')}</Label>
                        <Input
                            id="decimal_places"
                            type="number"
                            min={0}
                            max={10}
                            value={data.decimal_places}
                            onChange={(event) => setData('decimal_places', Number(event.target.value))}
                            dir="ltr"
                            required
                        />
                        <p className="text-muted-foreground text-xs">{t('currencies.hints.decimal_places')}</p>
                        <InputError message={errors.decimal_places} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="sort_order">{t('currencies.fields.sort_order')}</Label>
                        <Input
                            id="sort_order"
                            type="number"
                            min={0}
                            value={data.sort_order}
                            onChange={(event) => setData('sort_order', Number(event.target.value))}
                            dir="ltr"
                            required
                        />
                        <InputError message={errors.sort_order} />
                    </div>

                    <div className="flex items-center gap-2">
                        <Checkbox id="is_active" checked={data.is_active} onClick={() => setData('is_active', !data.is_active)} />
                        <Label htmlFor="is_active">{t('currencies.fields.is_active')}</Label>
                    </div>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" aria-hidden="true" />}
                            {t('common.save')}
                        </Button>
                        <Button type="button" variant="ghost" asChild>
                            <Link href="/currencies">{t('common.cancel')}</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
