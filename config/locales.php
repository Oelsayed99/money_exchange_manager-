<?php

declare(strict_types=1);

/**
 * Supported interface locales.
 *
 * Direction travels with the locale rather than being inferred at the call site, so
 * that adding a further RTL language later is a data change here and nothing else.
 */
return [
    'supported' => [
        'en' => [
            'name' => 'English',
            'native' => 'English',
            'direction' => 'ltr',
        ],
        'ar' => [
            'name' => 'Arabic',
            'native' => 'العربية',
            'direction' => 'rtl',
        ],
    ],
];
