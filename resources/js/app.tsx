import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { initializeTheme } from './hooks/use-appearance';

declare global {
    const route: typeof routeFn;
}

/**
 * Resolve a page, and recover from a stale tab.
 *
 * A failed page resolution otherwise surfaces as an unhandled promise rejection and
 * nothing else: the click does nothing, the interface stays where it was, and only the
 * console says why. That is worth handling because the common cause is not a bug —
 * it is a tab that was open across a deploy, asking for a hashed chunk that no longer
 * exists. Reloading fetches the current manifest and the click works.
 *
 * The attempt is recorded so a page that is genuinely missing fails loudly the second
 * time rather than reloading in a loop, and cleared on success so a later stale chunk
 * gets its own retry.
 */
async function resolvePage(name: string) {
    const key = `inertia:reload:${name}`;

    try {
        const page = await resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx'));

        sessionStorage.removeItem(key);

        return page;
    } catch (error) {
        if (sessionStorage.getItem(key) === null) {
            sessionStorage.setItem(key, '1');
            window.location.reload();
        }

        throw error;
    }
}

const appName = import.meta.env.VITE_APP_NAME || 'MonyMonk';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    // A single glob pattern, deliberately. Excluding test files with the array form
    // (['./pages/**/*.tsx', '!./pages/**/*.test.tsx']) works for the bundle but stops
    // Vite tracking the glob for *new* files, so a page added while the dev server is
    // running 404s until it is restarted. Page tests live in resources/js/tests
    // instead, which keeps them out of the bundle without touching this pattern.
    resolve: (name) => resolvePage(name),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();

/**
 * Keep the document direction and language in step with the active locale.
 *
 * The initial value is server-rendered into app.blade.php. Inertia visits replace the
 * page component without touching the html element, so after a language switch these
 * attributes would otherwise remain stale — and the entire RTL layout keys off dir.
 *
 * Done as a router subscription rather than a hook so it applies to every page,
 * including any that do not use a shared layout.
 */
function applyLocale(page: { props: Record<string, unknown> }): void {
    const { locale, direction } = page.props as { locale?: string; direction?: string };

    if (direction) {
        document.documentElement.dir = direction;
    }

    if (locale) {
        document.documentElement.lang = locale;
    }
}

/*
    Both events, because they cover different journeys.

    `success` fires when a request comes back. `navigate` fires whenever the page
    changes, including the browser's back and forward buttons — which restore a cached
    page without making a request, so `success` never fires for them.

    Switching to Arabic and pressing back used to leave a page half turned over: React
    re-rendered from the restored props, so the sidebar moved to the other side, while
    `dir` stayed on the html element at whatever the last request had set it to and every
    logical property in the layout kept resolving the old way.
*/
router.on('success', (event) => applyLocale(event.detail.page));
router.on('navigate', (event) => applyLocale(event.detail.page));
