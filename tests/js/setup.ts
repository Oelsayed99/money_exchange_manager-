import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

// Components in this project are asserted in both LTR and RTL (docs/ASSESSMENT.md §9),
// which means the document direction is mutated during tests. Reset it alongside the
// DOM so direction never leaks between test cases.
afterEach(() => {
    cleanup();
    document.documentElement.dir = 'ltr';
    document.documentElement.lang = 'en';
});
