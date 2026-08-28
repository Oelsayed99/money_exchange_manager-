<?php

declare(strict_types=1);

/**
 * The brand assets are referenced by path, not imported.
 *
 * Everything under `public/` is served straight off disk, which means nothing checks
 * these references: no compiler resolves them, no bundler fails on them, and a
 * mistyped or deleted file produces a broken image in the interface and a missing
 * logo on a document a client is handed. This is the only thing that would notice.
 */

use App\Domain\Statement\StatementPdf;

/** Every `/brand/…` path the interface asks for. */
function brandReferences(): array
{
    $paths = [];

    // Recursive by hand: PHP's glob() does not expand `**`, so a pattern that looks
    // recursive would quietly only ever reach one directory down.
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path()));

    foreach ($files as $file) {
        if (! $file instanceof SplFileInfo || ! in_array($file->getExtension(), ['tsx', 'ts', 'php'], true)) {
            continue;
        }

        preg_match_all('#/brand/[\w.-]+#', (string) file_get_contents($file->getPathname()), $matches);

        foreach ($matches[0] as $match) {
            $paths[$match] = true;
        }
    }

    return array_keys($paths);
}

it('ships every brand image the interface asks for', function (): void {
    $references = brandReferences();

    // Not merely non-empty: if the scan silently stopped finding files, an empty result
    // would make every assertion below vacuous.
    expect($references)->toContain('/brand/icon.png')
        ->toContain('/brand/wordmark-light.png')
        ->toContain('/brand/wordmark-dark.png');

    foreach ($references as $reference) {
        expect(public_path(ltrim($reference, '/')))->toBeReadableFile("{$reference} is referenced but not present in public/");
    }
});

it('ships every icon the head and the manifest name', function (): void {
    $head = (string) file_get_contents(resource_path('views/app.blade.php'));

    preg_match_all('#href="(/[\w.-]+\.(?:ico|png|webmanifest))"#', $head, $matches);

    expect($matches[1])->toContain('/favicon.ico')
        ->toContain('/apple-touch-icon.png')
        ->toContain('/site.webmanifest');

    foreach ($matches[1] as $path) {
        expect(public_path(ltrim($path, '/')))->toBeReadableFile("{$path} is linked from the document head but not present in public/");
    }
});

it('names icons the browser can actually fetch from the manifest', function (): void {
    $manifest = json_decode((string) file_get_contents(public_path('site.webmanifest')), true, 512, JSON_THROW_ON_ERROR);

    expect($manifest['name'])->toBe(config('app.name'));

    // The generator that produced this file wrote an absolute URL and a stray comma
    // into every `src`. An installed web app with an unreachable icon falls back to a
    // screenshot of the page, which is the sort of thing nobody reports.
    foreach ($manifest['icons'] as $icon) {
        expect($icon['src'])->toStartWith('/')
            ->and(public_path(ltrim($icon['src'], '/')))->toBeReadableFile("{$icon['src']} is named by the manifest but not present in public/");
    }
});

it('renders the statement with the brand assets it was given', function (): void {
    $html = (new ReflectionClass(StatementPdf::class))->getFileName();

    expect((string) file_get_contents((string) $html))
        ->toContain("public_path('brand/icon.png')")
        ->toContain("public_path('brand/wordmark-light.png')");

    expect(public_path('brand/icon.png'))->toBeReadableFile()
        ->and(public_path('brand/wordmark-light.png'))->toBeReadableFile();
});
