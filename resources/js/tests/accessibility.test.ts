import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Structural accessibility rules, checked across the interface.
 *
 * These are the ones a machine can settle. Whether a label reads clearly, or whether a
 * colour carries meaning nothing else does, is a judgement — but "this input has no
 * name at all" is a fact, and it is the kind of fact that slips in one screen at a time
 * while every test stays green.
 *
 * Vendored `components/ui` is excluded: it is upstream code, exempted from the same
 * lint rules, and diverging from it is a deliberate act (ADR 0020).
 */
function sources(directory: string): string[] {
    const files: string[] = [];

    for (const entry of readdirSync(directory)) {
        const path = join(directory, entry);

        if (statSync(path).isDirectory()) {
            if (path.includes('components/ui')) {
                continue;
            }

            files.push(...sources(path));
        } else if (entry.endsWith('.tsx') && !entry.includes('.test.')) {
            files.push(path);
        }
    }

    return files;
}

/** Opening tags of one element. Arrow functions are masked so `=>` is not read as a tag end. */
function openingTags(source: string, tag: string): string[] {
    return source.replace(/=>/g, '=→').match(new RegExp(`<${tag}\\b[^>]*?/?>`, 'gs')) ?? [];
}

const files = sources('resources/js').map((path) => ({ path, source: readFileSync(path, 'utf-8') }));

describe('every control can be named', () => {
    it.each(['select', 'Input', 'MoneyInput', 'textarea'])('gives every <%s> an id or an aria-label', (tag) => {
        const unnamed = files.flatMap(({ path, source }) =>
            openingTags(source, tag)
                .filter((el) => !el.includes('id=') && !el.includes('aria-label'))
                .map((el) => `${path}: ${el.split(/\s+/).slice(0, 4).join(' ')}`),
        );

        expect(unnamed).toEqual([]);
    });
});

describe('tables', () => {
    // Without a scope, a screen reader reads a row of figures with nothing attached to
    // them — which on a statement is a column of amounts and no idea which is which.
    it('marks every column header with a scope', () => {
        const unscoped = files.flatMap(({ path, source }) =>
            openingTags(source, 'th')
                .filter((el) => !el.includes('scope='))
                .map((el) => `${path}: ${el.slice(0, 40)}`),
        );

        expect(unscoped).toEqual([]);
    });
});

describe('the shell', () => {
    it('offers a way past the navigation', () => {
        const layout = readFileSync('resources/js/layouts/app/app-sidebar-layout.tsx', 'utf-8');

        expect(layout).toContain('href="#main"');
        expect(layout).toContain('id="main"');
    });

    // A validation message that merely appears is silent to anybody not watching it.
    it('announces validation messages', () => {
        expect(readFileSync('resources/js/components/input-error.tsx', 'utf-8')).toContain('role="alert"');
    });

    it('announces the result of a saved form', () => {
        expect(readFileSync('resources/js/components/flash-message.tsx', 'utf-8')).toContain('role="status"');
    });
});

describe('decorative icons', () => {
    // An icon beside a word is read twice without this, once as the word and once as
    // whatever the icon's name happens to be.
    it('hides icons that sit beside their own label', () => {
        const exposed = files.flatMap(({ path, source }) =>
            (
                source.match(
                    /<(?:Check|Plus|Pencil|Printer|Download|Sheet|FileText|AlertTriangle|LoaderCircle|ArrowDownLeft|ArrowUpRight|Eye)\b[^>]*?\/>/gs,
                ) ?? []
            )
                .filter((el) => !el.includes('aria-hidden'))
                .map((el) => `${path}: ${el.slice(0, 50)}`),
        );

        expect(exposed).toEqual([]);
    });
});
