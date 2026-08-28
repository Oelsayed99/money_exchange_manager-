import { execSync } from 'node:child_process';
import { existsSync, renameSync } from 'node:fs';

/**
 * Rebuild the end-to-end database, and make sure the browser gets the built assets.
 *
 * `migrate:fresh` drops every table, so the database name is passed explicitly on the
 * command rather than inherited from `.env` — inheriting it is exactly how this
 * application's real data came to be destroyed twice during development.
 *
 * `E2eSeeder` refuses to run against any database but this one, so a mistake here fails
 * loudly instead of quietly emptying something that mattered.
 */
const DATABASE = 'finance_e2e';

/**
 * `public/hot` is how Blade decides to load modules from the Vite dev server instead of
 * the build. If a `npm run dev` happens to be running, these tests exercise whatever
 * Vite has in its dependency cache rather than what ships — which is not a theoretical
 * difference: it hid a fixed Inertia bug behind a stale pre-bundle for an entire
 * afternoon, and the suite passed against code nobody was going to deploy.
 *
 * So the file is parked for the length of the run and put back afterwards. Parking
 * rather than deleting, because it belongs to a dev server that is still running and
 * will want it again.
 */
export const HOT = 'public/hot';
export const PARKED = 'public/hot.parked-by-e2e';

export default function globalSetup(): void {
    const run = (command: string) =>
        execSync(command, {
            stdio: 'inherit',
            env: { ...process.env, DB_DATABASE: DATABASE },
        });

    // A run killed part-way leaves the file parked. Put it back before parking again,
    // so the state is the same however the last run ended.
    restoreHotFile();

    run('npm run build');

    if (existsSync(HOT)) {
        renameSync(HOT, PARKED);
    }

    run(`mysql -e "CREATE DATABASE IF NOT EXISTS ${DATABASE} CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"`);
    run(`DB_DATABASE=${DATABASE} php artisan migrate:fresh --seed --seeder=E2eSeeder --force`);
}

export function restoreHotFile(): void {
    if (existsSync(PARKED) && !existsSync(HOT)) {
        renameSync(PARKED, HOT);
    }
}
