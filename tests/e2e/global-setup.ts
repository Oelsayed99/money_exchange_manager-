import { execSync } from 'node:child_process';

/**
 * Rebuild the end-to-end database before the run.
 *
 * `migrate:fresh` drops every table, so the database name is passed explicitly on the
 * command rather than inherited from `.env` — inheriting it is exactly how this
 * application's real data came to be destroyed twice during development.
 *
 * `E2eSeeder` refuses to run against any database but this one, so a mistake here fails
 * loudly instead of quietly emptying something that mattered.
 */
const DATABASE = 'finance_e2e';

export default function globalSetup(): void {
    const run = (command: string) =>
        execSync(command, {
            stdio: 'inherit',
            env: { ...process.env, DB_DATABASE: DATABASE },
        });

    run(
        `mysql -e "CREATE DATABASE IF NOT EXISTS ${DATABASE} CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"`,
    );
    run(`DB_DATABASE=${DATABASE} php artisan migrate:fresh --seed --seeder=E2eSeeder --force`);
}
