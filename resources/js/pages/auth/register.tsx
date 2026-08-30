import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';
import { SocialButtons, type SocialProviderOption } from '@/components/social-buttons';
import { useTranslations } from '@/lib/i18n';

type RegisterForm = {
    name: string;
    business_name: string;
    email: string;
    password: string;
    password_confirmation: string;
};

export default function Register({ providers = [] }: { providers?: SocialProviderOption[] }) {
    const { t } = useTranslations();

    const { data, setData, post, processing, errors, reset } = useForm<RegisterForm>({
        name: '',
        business_name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('register'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout title={t('auth.register.title')} description={t('auth.register.description')}>
            <Head title={t('auth.register.head')} />
            <SocialButtons providers={providers} disabled={processing} />

            <form className="flex flex-col gap-6" onSubmit={submit}>
                <div className="grid gap-6">
                    <div className="grid gap-2">
                        <Label htmlFor="name">{t('auth.form.name')}</Label>
                        <Input
                            id="name"
                            type="text"
                            required
                            autoFocus
                            tabIndex={1}
                            autoComplete="name"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                            disabled={processing}
                            placeholder={t('auth.form.name_placeholder')}
                        />
                        <InputError message={errors.name} className="mt-2" />
                    </div>

                    {/* Signing up creates a set of books, and books belong to a business.
                        Asked for here rather than guessed at, because it is what every
                        statement this person hands a client will be headed with. */}
                    <div className="grid gap-2">
                        <Label htmlFor="business_name">{t('auth.form.business_name')}</Label>
                        <Input
                            id="business_name"
                            type="text"
                            required
                            tabIndex={2}
                            autoComplete="organization"
                            value={data.business_name}
                            onChange={(e) => setData('business_name', e.target.value)}
                            disabled={processing}
                            placeholder={t('auth.form.business_name_placeholder')}
                        />
                        <InputError message={errors.business_name} className="mt-2" />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="email">{t('auth.form.email')}</Label>
                        <Input
                            id="email"
                            type="email"
                            required
                            tabIndex={2}
                            autoComplete="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            disabled={processing}
                            placeholder={t('auth.form.email_placeholder')}
                        />
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password">{t('auth.form.password')}</Label>
                        <Input
                            id="password"
                            type="password"
                            required
                            tabIndex={3}
                            autoComplete="new-password"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            disabled={processing}
                            placeholder={t('auth.form.password_placeholder')}
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation">{t('auth.form.confirm_password')}</Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            required
                            tabIndex={4}
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            disabled={processing}
                            placeholder={t('auth.form.confirm_password')}
                        />
                        <InputError message={errors.password_confirmation} />
                    </div>

                    <Button type="submit" className="mt-2 w-full" tabIndex={5} disabled={processing}>
                        {processing && <LoaderCircle className="h-4 w-4 animate-spin" aria-hidden="true" />}
                        {t('auth.register.submit')}
                    </Button>
                </div>

                <div className="text-muted-foreground text-center text-sm">
                    {t('auth.register.have_account')}{' '}
                    <TextLink href={route('login')} tabIndex={6}>
                        {t('auth.register.log_in')}
                    </TextLink>
                </div>
            </form>
        </AuthLayout>
    );
}
