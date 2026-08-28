import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useTranslations } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { Check, Languages } from 'lucide-react';

/**
 * Language switcher.
 *
 * The server redirects back to the same URL, so the page, its filters and its
 * pagination survive the switch — a requirement of Section 12 rather than a nicety.
 *
 * `preserveState` is what keeps a half-typed form. Without it Inertia tears the page
 * component down and builds a new one, so every useState goes back to its initial
 * value: an operator mid-deal who switches to Arabic to read a label loses the amounts
 * they had entered. The translations still change, because they arrive as props rather
 * than as state.
 */
export function LanguageSwitcher({ className }: { className?: string }) {
    const { t, locale, locales } = useTranslations();

    const switchTo = (code: string) => {
        if (code === locale) {
            return;
        }

        router.put('/locale', { locale: code }, { preserveScroll: true, preserveState: true });
    };

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                className="text-muted-foreground hover:text-foreground focus-visible:ring-ring inline-flex items-center gap-2 rounded-md px-2 py-1.5 text-sm focus-visible:ring-2 focus-visible:outline-none"
                aria-label={t('common.language')}
            >
                <Languages className="size-4" aria-hidden="true" />
                <span>{locales.find((option) => option.code === locale)?.native ?? locale}</span>
            </DropdownMenuTrigger>

            <DropdownMenuContent align="end" className={className}>
                {locales.map((option) => (
                    <DropdownMenuItem key={option.code} onClick={() => switchTo(option.code)} className="flex items-center justify-between gap-4">
                        <span dir={option.direction}>{option.native}</span>
                        {/* Selection is marked with an icon, not colour alone (Section 13). */}
                        {option.code === locale && <Check className="size-4" aria-hidden="true" />}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
