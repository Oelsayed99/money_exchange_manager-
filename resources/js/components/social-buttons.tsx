import { useTranslations } from '@/lib/i18n';
import { cn } from '@/lib/utils';

export interface SocialProviderOption {
    name: string;
    label: string;
}

/**
 * Google's own mark, inline.
 *
 * Their brand guidelines ask for the four-colour G, and a strict content policy means
 * nothing on these pages may load from another host — so it is drawn here rather than
 * fetched. Fixed colours on purpose: this is somebody's logo, and it does not have a
 * dark-mode variant.
 */
function GoogleMark() {
    return (
        <svg viewBox="0 0 48 48" className="size-5 shrink-0" aria-hidden="true">
            <path
                fill="#4285F4"
                d="M45.12 24.5c0-1.56-.14-3.06-.4-4.5H24v8.51h11.84c-.51 2.75-2.06 5.08-4.39 6.64v5.52h7.11c4.16-3.83 6.56-9.47 6.56-16.17z"
            />
            <path
                fill="#34A853"
                d="M24 46c5.94 0 10.92-1.97 14.56-5.33l-7.11-5.52c-1.97 1.32-4.49 2.1-7.45 2.1-5.73 0-10.58-3.87-12.31-9.07H4.34v5.7C7.96 41.07 15.4 46 24 46z"
            />
            <path
                fill="#FBBC05"
                d="M11.69 28.18C11.25 26.86 11 25.45 11 24s.25-2.86.69-4.18v-5.7H4.34C2.85 17.09 2 20.45 2 24s.85 6.91 2.34 9.88l7.35-5.7z"
            />
            <path
                fill="#EA4335"
                d="M24 10.75c3.23 0 6.13 1.11 8.41 3.29l6.31-6.31C34.91 4.18 29.93 2 24 2 15.4 2 7.96 6.93 4.34 14.12l7.35 5.7c1.73-5.2 6.58-9.07 12.31-9.07z"
            />
        </svg>
    );
}

function AppleMark() {
    return (
        <svg viewBox="0 0 24 24" className="size-5 shrink-0 fill-current" aria-hidden="true">
            <path d="M17.05 12.94c-.03-2.72 2.22-4.03 2.32-4.09-1.27-1.85-3.24-2.11-3.94-2.14-1.68-.17-3.28.99-4.13.99-.85 0-2.16-.97-3.55-.94-1.83.03-3.51 1.06-4.45 2.7-1.9 3.3-.48 8.18 1.36 10.85.9 1.31 1.97 2.77 3.38 2.72 1.36-.06 1.87-.88 3.51-.88 1.64 0 2.1.88 3.54.85 1.46-.03 2.39-1.33 3.28-2.65 1.03-1.52 1.46-2.99 1.49-3.06-.03-.01-2.86-1.1-2.89-4.35zM14.4 4.78c.75-.91 1.25-2.17 1.11-3.43-1.08.04-2.38.72-3.15 1.62-.69.8-1.29 2.08-1.13 3.31 1.2.09 2.42-.61 3.17-1.5z" />
        </svg>
    );
}

/**
 * The other ways in, when there are any.
 *
 * The server sends only the providers holding credentials, so this renders nothing at
 * all until Google is configured and nothing changes here when Apple is.
 */
export function SocialButtons({ providers, disabled = false }: { providers: SocialProviderOption[]; disabled?: boolean }) {
    const { t } = useTranslations();

    if (providers.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-4">
            <div className="grid gap-2">
                {providers.map((provider) => (
                    <a
                        key={provider.name}
                        href={`/auth/${provider.name}/redirect`}
                        aria-disabled={disabled}
                        className={cn(
                            'border-input bg-background hover:bg-accent focus-visible:ring-ring flex h-10 items-center justify-center gap-3 rounded-md border text-sm font-medium transition-colors focus-visible:ring-2 focus-visible:outline-none',
                            disabled && 'pointer-events-none opacity-50',
                        )}
                    >
                        {provider.name === 'apple' ? <AppleMark /> : <GoogleMark />}
                        {provider.label}
                    </a>
                ))}
            </div>

            {/* A rule with a word on it, rather than a bare line: the line alone reads as
                decoration and people miss that the form below is a second option. */}
            <div className="relative text-center">
                <span aria-hidden="true" className="border-border absolute inset-x-0 top-1/2 border-t" />
                <span className="bg-background text-muted-foreground relative px-3 text-xs">{t('auth.or')}</span>
            </div>
        </div>
    );
}
