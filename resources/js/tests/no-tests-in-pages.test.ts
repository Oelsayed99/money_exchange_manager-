import { readdirSync, statSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Page tests must not live under `resources/js/pages`.
 *
 * Two separate things go wrong when they do, and this guards both:
 *
 * 1. Inertia's page glob picks them up, so the test file is **bundled into the
 *    production assets** and `exchange/create.test` becomes a resolvable page name.
 * 2. Excluding them with a negative glob pattern fixes that and breaks something
 *    worse: Vite stops tracking the glob for new files, so any page added while the
 *    dev server runs 404s until it is restarted.
 *
 * Keeping tests outside `pages/` avoids having to choose. This test is the reminder,
 * because nothing else fails when somebody puts one back.
 */
function findTests(directory: string): string[] {
    const found: string[] = [];

    for (const entry of readdirSync(directory)) {
        const path = join(directory, entry);

        if (statSync(path).isDirectory()) {
            found.push(...findTests(path));
        } else if (/\.(test|spec)\.(ts|tsx)$/.test(entry)) {
            found.push(path);
        }
    }

    return found;
}

it('keeps test files out of the Inertia page directory', () => {
    expect(findTests('resources/js/pages')).toEqual([]);
});
