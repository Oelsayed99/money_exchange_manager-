import type { Direction, SharedData, TranslationBundle } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * Resolve a dotted key against a nested translation bundle.
 *
 * Returns the key itself when nothing matches. That is deliberate: a visible
 * `currencies.fields.code` in the interface is an obvious bug, whereas an empty
 * string silently swallows the mistake.
 */
export function translate(bundle: TranslationBundle, key: string, replacements: Record<string, string | number> = {}): string {
    let node: string | TranslationBundle | undefined = bundle;

    for (const segment of key.split('.')) {
        if (typeof node !== 'object' || node === null) {
            return key;
        }

        node = node[segment];
    }

    if (typeof node !== 'string') {
        return key;
    }

    return Object.entries(replacements).reduce<string>((carry, [token, value]) => carry.replaceAll(`:${token}`, String(value)), node);
}

/**
 * Translation access for components.
 *
 * Section 12 forbids hardcoded interface strings. Every visible string goes through
 * t(), including ones that happen to look the same in both languages today.
 */
export function useTranslations() {
    const page = usePage<SharedData>();
    const bundle = page.props.translations;

    return {
        t: (key: string, replacements?: Record<string, string | number>): string => translate(bundle, key, replacements),
        locale: page.props.locale,
        direction: page.props.direction as Direction,
        isRtl: page.props.direction === 'rtl',
        locales: page.props.locales,
    };
}
