import AppLogoIcon from '@/components/app-logo-icon';
import AppWordmark from '@/components/app-wordmark';
import { LanguageSwitcher } from '@/components/language-switcher';
import { Button } from '@/components/ui/button';
import { useTranslations } from '@/lib/i18n';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeftRight, FileText, Languages, Lock, Scale, Wallet } from 'lucide-react';

/**
 * The front door.
 *
 * It used to say only which application you had arrived at, which was right while this
 * was one office's books behind a password. Now anyone can sign up, so a stranger has
 * to be able to tell from here whether this is for them.
 *
 * What it says is what the application actually does, in the words the application uses
 * — In and Out, one running balance, a statement you can hand over. No screenshots, no
 * invented figures, and no claim about anybody's money: every number on this page is a
 * label, not a balance.
 */

interface Feature {
    icon: typeof Wallet;
    title: string;
    body: string;
}

export default function Welcome() {
    const { auth } = usePage<SharedData>().props;
    const { t } = useTranslations();

    const features: Feature[] = [
        { icon: ArrowLeftRight, title: t('common.landing.features.record.title'), body: t('common.landing.features.record.body') },
        { icon: Scale, title: t('common.landing.features.balance.title'), body: t('common.landing.features.balance.body') },
        { icon: Wallet, title: t('common.landing.features.currencies.title'), body: t('common.landing.features.currencies.body') },
        { icon: FileText, title: t('common.landing.features.statements.title'), body: t('common.landing.features.statements.body') },
        { icon: Lock, title: t('common.landing.features.trail.title'), body: t('common.landing.features.trail.body') },
        { icon: Languages, title: t('common.landing.features.language.title'), body: t('common.landing.features.language.body') },
    ];

    return (
        <>
            {/* A real title rather than the application's own name. A child <title> was
                used here to dodge the " - MonyMonk" the global callback in app.tsx
                appends, but the callback runs again on hydration and the tab read
                "MonyMonk - MonyMonk" anyway. On the one page strangers arrive at, what
                the tab and a search result should say is what the thing does. */}
            <Head title={t('common.landing.headline')}>
                <meta name="description" content={t('common.landing.tagline')} />
            </Head>

            <div className="bg-background text-foreground flex min-h-svh flex-col">
                <header className="flex items-center justify-between gap-4 p-4 sm:px-8">
                    <AppWordmark className="h-7" />

                    <div className="flex items-center gap-2">
                        <LanguageSwitcher />

                        {auth.user ? (
                            <Button asChild size="sm">
                                <Link href={route('dashboard')}>{t('common.landing.enter')}</Link>
                            </Button>
                        ) : (
                            <>
                                <Button asChild variant="ghost" size="sm">
                                    <Link href={route('login')}>{t('common.landing.sign_in')}</Link>
                                </Button>
                                <Button asChild size="sm">
                                    <Link href={route('register')}>{t('common.landing.sign_up')}</Link>
                                </Button>
                            </>
                        )}
                    </div>
                </header>

                <main className="flex flex-1 flex-col">
                    <section className="mx-auto flex w-full max-w-3xl flex-col items-center gap-6 px-6 py-16 text-center sm:py-24">
                        <AppLogoIcon className="size-20" />

                        <h1 className="text-3xl font-semibold tracking-tight text-balance sm:text-5xl">{t('common.landing.headline')}</h1>

                        <p className="text-muted-foreground max-w-xl text-balance sm:text-lg">{t('common.landing.tagline')}</p>

                        <div className="flex flex-wrap items-center justify-center gap-3">
                            <Button asChild size="lg">
                                <Link href={auth.user ? route('dashboard') : route('register')}>
                                    {auth.user ? t('common.landing.enter') : t('common.landing.start')}
                                </Link>
                            </Button>

                            {!auth.user && (
                                <Button asChild variant="outline" size="lg">
                                    <Link href={route('login')}>{t('common.landing.sign_in')}</Link>
                                </Button>
                            )}
                        </div>

                        <p className="text-muted-foreground text-xs">{t('common.landing.free_note')}</p>
                    </section>

                    <section className="border-sidebar-border/70 dark:border-sidebar-border border-t">
                        <div className="mx-auto grid w-full max-w-5xl gap-8 px-6 py-16 sm:grid-cols-2 lg:grid-cols-3">
                            {features.map((feature) => (
                                <div key={feature.title} className="space-y-2">
                                    <feature.icon className="text-muted-foreground size-6" aria-hidden="true" />
                                    <h2 className="font-medium">{feature.title}</h2>
                                    <p className="text-muted-foreground text-sm">{feature.body}</p>
                                </div>
                            ))}
                        </div>
                    </section>

                    {/* The one claim on this page worth making in its own right. An
                        exchange office asked to keep its books on somebody else's server
                        wants this answered before anything else. */}
                    <section className="border-sidebar-border/70 dark:border-sidebar-border border-t">
                        <div className="mx-auto w-full max-w-3xl space-y-3 px-6 py-16 text-center">
                            <h2 className="text-xl font-semibold tracking-tight">{t('common.landing.privacy.title')}</h2>
                            <p className="text-muted-foreground text-balance">{t('common.landing.privacy.body')}</p>
                        </div>
                    </section>
                </main>

                <footer className="border-sidebar-border/70 dark:border-sidebar-border text-muted-foreground border-t px-6 py-6 text-center text-xs">
                    {t('common.landing.footer', { year: String(new Date().getFullYear()) })}
                </footer>
            </div>
        </>
    );
}
