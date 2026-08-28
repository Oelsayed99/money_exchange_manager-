import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useTranslations } from '@/lib/i18n';
import { Link } from '@inertiajs/react';
import { Check, ChevronDown } from 'lucide-react';

export type RecordMode = 'exchange' | 'movement';

/**
 * The heading of the recording screen, and the switch between its two forms.
 *
 * Exchange and the other movements are one screen with two shapes rather than two
 * places in the navigation. They are the same act — recording something that happened
 * at the counter — and an operator arriving to record a deposit should not have to
 * have decided which menu item it lives under before they get there.
 *
 * The two forms remain two routes. The switch is an ordinary Inertia visit, so the
 * back button works, a link to either shape can be sent to somebody, and neither
 * form's data is fetched for an operator who only wanted the other one.
 */
export function RecordHeading({ current }: { current: RecordMode }) {
    const { t } = useTranslations();

    const modes: { mode: RecordMode; href: string; title: string; description: string }[] = [
        {
            mode: 'exchange',
            href: '/exchange',
            title: t('transactions.exchange.title'),
            description: t('transactions.exchange.description'),
        },
        {
            mode: 'movement',
            href: '/movements',
            title: t('movements.title'),
            description: t('movements.description'),
        },
    ];

    // Not `find(...)!`: a mode with no entry is a programming mistake, and falling back
    // to the first one shows a heading rather than crashing the page mid-form.
    const active = modes.find((option) => option.mode === current) ?? modes[0];

    if (active === undefined) {
        return null;
    }

    return (
        <div className="space-y-1">
            <DropdownMenu>
                {/*
                    The trigger sits inside the heading rather than the other way round.
                    A <button> may not contain an <h1>, and this way the page keeps a
                    real level-one heading whose text is also the button's name.
                */}
                <h1 className="text-2xl font-semibold tracking-tight">
                    <DropdownMenuTrigger className="focus-visible:ring-ring group inline-flex items-center gap-2 rounded-md focus-visible:ring-2 focus-visible:outline-none">
                        {active.title}
                        <ChevronDown
                            className="text-muted-foreground size-5 transition-transform group-data-[state=open]:rotate-180"
                            aria-hidden="true"
                        />
                    </DropdownMenuTrigger>
                </h1>

                <DropdownMenuContent align="start" className="w-[22rem] max-w-[calc(100vw-2rem)]">
                    {modes.map((option) => (
                        <DropdownMenuItem key={option.mode} asChild>
                            <Link href={option.href} className="flex items-start gap-3">
                                {/* The current form is marked with an icon, not colour
                                    alone (Section 13). The spacer keeps both titles on
                                    the same line as each other. */}
                                {option.mode === active.mode ? (
                                    <Check className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                ) : (
                                    <span className="size-4 shrink-0" aria-hidden="true" />
                                )}
                                <span className="space-y-0.5">
                                    <span className="block font-medium">{option.title}</span>
                                    <span className="text-muted-foreground block text-xs leading-snug text-wrap">{option.description}</span>
                                </span>
                            </Link>
                        </DropdownMenuItem>
                    ))}
                </DropdownMenuContent>
            </DropdownMenu>

            <p className="text-muted-foreground max-w-2xl text-sm">{active.description}</p>
        </div>
    );
}
