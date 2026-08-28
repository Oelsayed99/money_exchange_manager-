import AppLogoIcon from '@/components/app-logo-icon';
import AppWordmark from '@/components/app-wordmark';
import { LanguageSwitcher } from '@/components/language-switcher';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';

/**
 * The front door.
 *
 * Deliberately almost empty. Nothing here is public — there is no product to explain
 * to a stranger and no figure that may be shown to one — so this page exists only to
 * say which application you have arrived at and to send you where you were going.
 */
export default function Welcome() {
    const { auth, name } = usePage<SharedData>().props;
    const { t } = useTranslations();

    return (
        <>
            {/* The title as a child rather than the `title` prop: the prop goes through
                the global callback in app.tsx and would render "MonyMonk - MonyMonk". */}
            <Head>
                <title>{name}</title>
            </Head>

            <div className="bg-background text-foreground flex min-h-svh flex-col">
                <header className="flex justify-end p-4">
                    <LanguageSwitcher />
                </header>

                <main className="flex flex-1 flex-col items-center justify-center gap-8 p-6 pb-24 text-center">
                    <AppLogoIcon className="size-24" />

                    <div className="space-y-3">
                        <AppWordmark className="mx-auto h-8" />

                        <p className="text-muted-foreground mx-auto max-w-md text-balance">{t('common.welcome.tagline')}</p>
                    </div>

                    <Button asChild size="lg">
                        <Link href={auth.user ? route('dashboard') : route('login')}>
                            {auth.user ? t('common.welcome.enter') : t('common.welcome.sign_in')}
                        </Link>
                    </Button>
                </main>
            </div>
        </>
    );
}
