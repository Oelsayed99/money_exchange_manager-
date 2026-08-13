import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

export type Appearance = 'light' | 'dark' | 'system';

const prefersDark = () => window.matchMedia('(prefers-color-scheme: dark)').matches;

const applyTheme = (appearance: Appearance) => {
    const isDark = appearance === 'dark' || (appearance === 'system' && prefersDark());

    document.documentElement.classList.toggle('dark', isDark);
};

const mediaQuery = window.matchMedia('(prefers-color-scheme: dark)');

const handleSystemThemeChange = () => {
    const currentAppearance = localStorage.getItem('appearance') as Appearance;
    applyTheme(currentAppearance || 'system');
};

/**
 * Applies the theme on first load, outside React.
 *
 * The server-rendered blocking script in app.blade.php has already set the class
 * before paint; this only attaches the listener that keeps "system" tracking the
 * operating system while the page is open.
 */
export function initializeTheme() {
    const savedAppearance = (localStorage.getItem('appearance') as Appearance) || 'system';

    applyTheme(savedAppearance);

    mediaQuery.addEventListener('change', handleSystemThemeChange);
}

export function useAppearance() {
    const page = usePage<SharedData>();
    const user = page.props.auth?.user ?? null;

    // The stored preference is the starting point, not localStorage: it is the choice
    // that follows the user between devices (Section 13).
    const saved = (user?.theme as Appearance | null | undefined) ?? null;

    const [appearance, setAppearance] = useState<Appearance>(saved ?? 'system');

    const updateAppearance = useCallback(
        (mode: Appearance) => {
            setAppearance(mode);
            localStorage.setItem('appearance', mode);
            applyTheme(mode);

            // Guests have nowhere to persist to; their choice lives in this browser only.
            if (user) {
                router.put('/settings/appearance', { appearance: mode }, { preserveScroll: true, preserveState: true });
            }
        },
        [user],
    );

    useEffect(() => {
        const initial = saved ?? (localStorage.getItem('appearance') as Appearance | null) ?? 'system';

        setAppearance(initial);
        localStorage.setItem('appearance', initial);
        applyTheme(initial);

        return () => mediaQuery.removeEventListener('change', handleSystemThemeChange);
    }, [saved]);

    return { appearance, updateAppearance };
}
