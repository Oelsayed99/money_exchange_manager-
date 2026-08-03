import react from '@vitejs/plugin-react';
import path from 'node:path';
import { defineConfig } from 'vitest/config';

// Kept separate from vite.config.js on purpose: the Laravel Vite plugin expects a
// Laravel dev-server context (hot file, manifest, SSR entry) that has no meaning
// inside the test runner.
export default defineConfig({
    plugins: [react()],
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
    test: {
        environment: 'jsdom',
        globals: true,
        setupFiles: ['./tests/js/setup.ts'],
        include: ['resources/js/**/*.{test,spec}.{ts,tsx}'],
    },
});
