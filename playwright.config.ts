import { defineConfig, devices } from '@playwright/test';

/**
 * End-to-end tests, against a real browser and a real database.
 *
 * The database is `finance_e2e` and nothing else. `globalSetup` rebuilds it from
 * scratch before every run, so it must never be pointed at anything anybody cares
 * about — `E2eSeeder` refuses to run against any other name, which is the guard that
 * matters, since a config file is easy to edit and easy to edit wrongly.
 *
 * Port 8099 because 8000, 8001, 8080 and 8085 belong to the owner's other projects and
 * 8090 is the development server they use by hand.
 */
const PORT = 8099;
const DATABASE = 'finance_e2e';

export default defineConfig({
    testDir: './tests/e2e',
    // Not parallel. These share one database and one set of balances; two of them
    // recording a movement at once would be testing each other's data.
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    reporter: process.env.CI ? 'html' : 'list',
    timeout: 30_000,

    globalSetup: './tests/e2e/global-setup.ts',

    use: {
        baseURL: `http://127.0.0.1:${PORT}`,
        trace: 'on-first-retry',
        screenshot: 'only-on-failure',
    },

    webServer: {
        command: `DB_DATABASE=${DATABASE} php artisan serve --port=${PORT}`,
        url: `http://127.0.0.1:${PORT}/login`,
        reuseExistingServer: false,
        timeout: 60_000,
        env: { DB_DATABASE: DATABASE, APP_ENV: 'local' },
    },

    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
