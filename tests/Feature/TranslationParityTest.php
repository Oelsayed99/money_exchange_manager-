<?php

declare(strict_types=1);

/**
 * Every string the application can say, it can say in both languages.
 *
 * A missing key does not fail — Laravel falls back to English and the page renders —
 * so this is invisible without a test. The worst case is validation: the message an
 * Arabic operator sees at the moment they have made a mistake, in English.
 *
 * Section 12 forbids hardcoded interface strings. This is the other half: strings that
 * are translatable and simply have not been translated.
 */

/** @return array<string, string> flattened dotted keys */
function flattenTranslations(array $values, string $prefix = ''): array
{
    $flat = [];

    foreach ($values as $key => $value) {
        $dotted = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            $flat = [...$flat, ...flattenTranslations($value, $dotted)];

            continue;
        }

        $flat[$dotted] = (string) $value;
    }

    return $flat;
}

/**
 * @return array<string, array<string, string>> group => keys
 */
function translationGroups(string $locale): array
{
    $groups = [];

    foreach (glob(base_path("lang/{$locale}/*.php")) ?: [] as $file) {
        $keys = flattenTranslations(require $file);

        // Laravel ships validation.custom as a worked example of the shape —
        // "attribute-name.rule-name" => "custom-message". Nobody ever sees it.
        $keys = array_filter(
            $keys,
            fn (string $key): bool => ! str_starts_with($key, 'custom.'),
            ARRAY_FILTER_USE_KEY,
        );

        $groups[basename($file, '.php')] = $keys;
    }

    return $groups;
}

it('has an Arabic translation for every English string', function (): void {
    $english = translationGroups('en');
    $arabic = translationGroups('ar');

    $missing = [];

    foreach ($english as $group => $keys) {
        foreach (array_keys($keys) as $key) {
            if (! isset($arabic[$group][$key])) {
                $missing[] = "{$group}.{$key}";
            }
        }
    }

    expect($missing)->toBe([], count($missing).' string(s) would render in English for an Arabic user: '.implode(', ', array_slice($missing, 0, 15)));
});

// The other direction matters less but still means something: an Arabic key with no
// English counterpart is either a typo or a string nobody can read in the fallback.
it('has an English string for every Arabic translation', function (): void {
    $english = translationGroups('en');
    $arabic = translationGroups('ar');

    $orphaned = [];

    foreach ($arabic as $group => $keys) {
        foreach (array_keys($keys) as $key) {
            if (! isset($english[$group][$key])) {
                $orphaned[] = "{$group}.{$key}";
            }
        }
    }

    expect($orphaned)->toBe([], 'Arabic keys with no English counterpart: '.implode(', ', array_slice($orphaned, 0, 15)));
});

// A translation that still reads as the English one is usually a copied line somebody
// meant to come back to. Placeholders, punctuation and format strings are excluded —
// ":from to :to" is legitimately the same in both.
it('has no Arabic string left identical to its English source', function (): void {
    $english = translationGroups('en');
    $arabic = translationGroups('ar');

    $untranslated = [];

    foreach ($arabic as $group => $keys) {
        foreach ($keys as $key => $value) {
            $source = $english[$group][$key] ?? null;

            if ($source === null || $source !== $value) {
                continue;
            }

            // An address or a link is the same in both languages by nature; translating
            // "email@example.com" would make the example wrong.
            if (preg_match('#[@]|https?://#', $value) === 1) {
                continue;
            }

            // Nothing to translate: no word survives once placeholders and symbols are
            // removed. Order matters — stripping symbols first would eat the colon and
            // leave the placeholder's name looking like prose.
            $words = preg_replace('/[^\p{L}]+/u', '', (string) preg_replace('/:\w+/u', '', $value));

            if ($words === '' || $words === null) {
                continue;
            }

            $untranslated[] = "{$group}.{$key}";
        }
    }

    expect($untranslated)->toBe([], 'Still in English: '.implode(', ', $untranslated));
});
