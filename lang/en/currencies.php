<?php

declare(strict_types=1);

return [
    'title' => 'Currencies',
    'description' => 'Currencies available to the system. Adding one never requires a code change.',

    'create_title' => 'Add currency',
    'edit_title' => 'Edit :code',

    'fields' => [
        'code' => 'Code',
        'name' => 'Name',
        'name_ar' => 'Name (Arabic)',
        'symbol' => 'Symbol',
        'decimal_places' => 'Decimal places',
        'is_active' => 'Active',
        'sort_order' => 'Sort order',
    ],

    'hints' => [
        'code' => 'ISO 4217 where one exists, e.g. USD. Stored uppercase.',
        'decimal_places' => 'How many decimals this currency is displayed to. 2 for USD, 3 for KWD, 0 for JPY.',
    ],

    'sample' => 'Sample',
    'empty' => 'No currencies yet.',
    'created' => 'Currency added.',
    'updated' => 'Currency updated.',
];
