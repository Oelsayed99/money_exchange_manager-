<?php

declare(strict_types=1);

return [
    'rates' => [
        'title' => 'Market reference',
        'updated' => 'Published :at · reference only, not used in any deal',
    ],

    'title' => 'Dashboard',
    'description' => 'Where everything stands, and what moved.',

    /*
     * The owner's three cases in their own words, plus the fourth the four-bucket
     * model makes possible and which naming as either one would misrepresent.
     */
    'statuses' => [
        'owes_us' => 'They owe us',
        'has_credit' => 'We owe them',
        'mixed' => 'Both ways',
        'settled' => 'Settled',
    ],
    'status_hints' => [
        'owes_us' => 'The balance runs our way in every currency they are carrying.',
        'has_credit' => 'We are holding money of theirs, or owe it to them.',
        'mixed' => 'They owe us in one currency and we owe them in another. Currencies are never added together.',
        'settled' => 'Nothing either way.',
    ],

    'cards' => [
        'cash_on_hand' => 'Cash on hand',
        'cash_on_hand_hint' => 'In our own custody locations. Not narrowed by client.',
        'owed_to_us' => 'Owed to us',
        'owed_to_us_hint' => 'Receivables plus our money held by others.',
        'owed_to_them' => 'Owed to them',
        'owed_to_them_hint' => 'Payables plus client credit we are holding.',
        'profit' => 'Margin earned',
        'profit_hint' => 'In the period shown, in the currency it was earned in.',
        'received' => 'In from clients',
        'delivered' => 'Out to clients',
    ],

    'filters' => [
        'client' => 'Client',
        'currency' => 'Currency',
        'status' => 'Status',
        'from' => 'From',
        'to' => 'To',
        'all' => 'All',
        'clear' => 'Clear filters',
    ],

    'chart' => [
        'title' => 'Margin by month',
        'hint' => 'One currency at a time — figures in different currencies share no scale.',
        'pick_currency' => 'Choose a currency to see this by month.',
    ],

    'flow' => [
        'title' => 'In and out by month',
        'hint' => 'Shown as two bars. A busy month that nets to zero is not a quiet one.',
    ],
    'split' => [
        'title' => 'Where clients stand',
        'hint' => 'A count of relationships, so it needs no currency. Settled clients are not listed.',
    ],
    'top' => [
        'title' => 'Largest positions',
        'hint' => 'Both sides shown separately. A client can be on both, and the two are never netted.',
    ],

    'parties' => [
        'title' => 'Clients',
        'name' => 'Client',
        'status' => 'Status',
        'balance' => 'Balance',
        'statement' => 'Statement',
        'none' => 'No client matches these filters.',
    ],

    'period_note' => 'Positions are as they stand now. The dates narrow what moved, not where things stand.',
    'no_data' => 'Nothing recorded yet.',
];
