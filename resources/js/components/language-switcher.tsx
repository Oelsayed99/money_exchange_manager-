import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { useTranslations } from '@/lib/i18n';
import { router } from '@inertiajs/react';
import { Check, Languages } from 'lucide-react';

/**
 * Language switcher.
 *
 * Uses a partial visit that preserves scroll and state, so switching language does not
 * discard active filters or reset pagination — a requirement of Section 12 rather than
 * a nicety. The server redirects back to the same URL.
 */
export function LanguageSwitcher({ className }: { className?: string }) {
    const { t, locale, locales } = useTranslations();

    const switchTo = (code: string) => {
        if (code === locale) {
            return;
        }

        router.put('/locale', { locale: code }, { preserveScroll: true, preserveState: false });
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
