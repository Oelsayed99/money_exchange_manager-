import '../css/app.css';

import { createInertiaApp, router } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { initializeTheme } from './hooks/use-appearance';

declare global {
    const route: typeof routeFn;
}

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    // Test files are excluded explicitly. A page test living beside its page is
    // otherwise picked up by this glob, bundled into the production assets, and served
    // to users — and "exchange/create.test" becomes a resolvable page name.
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob(['./pages/**/*.tsx', '!./pages/**/*.test.tsx'])),
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
router.on('success', (event) => {
    const props = event.detail.page.props as { locale?: string; direction?: string };

    if (props.direction) {
        document.documentElement.dir = props.direction;
    }

    if (props.locale) {
        document.documentElement.lang = props.locale;
    }
});
