import { useTranslations } from '@/lib/i18n';
import { Check, CircleSlash } from 'lucide-react';

/**
 * Active / inactive status.
 *
 * Carries an icon and a word, never colour alone — Section 13 requires it, and a
 * colour-only signal is invisible to a substantial number of readers.
 */
export function StatusBadge({ active }: { active: boolean }) {
    const { t } = useTranslations();

    if (active) {
        return (
            <span className="inline-flex items-center gap-1.5 text-emerald-700 dark:text-emerald-400">
                <Check className="size-4" aria-hidden="true" />
                {t('common.active')}
            </span>
        );
    }

    return (
        <span className="text-muted-foreground inline-flex items-center gap-1.5">
            <CircleSlash className="size-4" aria-hidden="true" />
            {t('common.inactive')}
        </span>
    );
}
