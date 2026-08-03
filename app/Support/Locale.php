<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Locale metadata lookup.
 *
 * Section 12 requires real RTL layouts rather than right-aligned text, which starts
 * with the server knowing the direction of the active locale and telling the client.
 */
final class Locale
{
    /** @return array<string, array{name: string, native: string, direction: string}> */
    public static function supported(): array
    {
        /** @var array<string, array{name: string, native: string, direction: string}> $locales */
        $locales = config('locales.supported', []);

        return $locales;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::supported());
    }

    public static function isSupported(string $locale): bool
    {
        return array_key_exists($locale, self::supported());
    }

    /** 'rtl' or 'ltr'. Falls back to ltr for an unknown locale rather than throwing. */
    public static function direction(string $locale): string
    {
        return self::supported()[$locale]['direction'] ?? 'ltr';
    }

    public static function isRtl(string $locale): bool
    {
        return self::direction($locale) === 'rtl';
    }
}
