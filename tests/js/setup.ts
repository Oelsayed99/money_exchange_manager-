import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach, vi } from 'vitest';

// jsdom implements no media queries at all. Anything responsive — the sidebar's
// mobile breakpoint, the system theme preference — calls this on mount and would
// otherwise throw before a single assertion ran. Defaults to "does not match", so
// components render their desktop, light-theme branch unless a test says otherwise.
Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: (query: string): MediaQueryList =>
        ({
            matches: false,
            media: query,
            onchange: null,
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
            addListener: vi.fn(),
            removeListener: vi.fn(),
            dispatchEvent: vi.fn(),
        }) as unknown as MediaQueryList,
});

// Components in this project are asserted in both LTR and RTL (docs/ASSESSMENT.md §9),
// which means the document direction is mutated during tests. Reset it alongside the
// DOM so direction never leaks between test cases.
afterEach(() => {
    cleanup();
    document.documentElement.dir = 'ltr';
    document.documentElement.lang = 'en';
});
