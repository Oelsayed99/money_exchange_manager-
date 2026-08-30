<?php

declare(strict_types=1);

return [
    'actions' => 'Actions',
    'active' => 'Active',
    'cancel' => 'Cancel',
    'create' => 'Create',
    'edit' => 'Edit',
    'inactive' => 'Inactive',
    'language' => 'Language',
    'no_results' => 'Nothing here yet',
    'save' => 'Save',
    'saved' => 'Saved',
    'status' => 'Status',
    'currency' => 'Currency',
    'export_csv' => 'Download CSV',
    'skip_to_content' => 'Skip to content',

    'welcome' => [
        'tagline' => 'Every exchange and every balance, kept in the currency it was made in.',
        'enter' => 'Open the dashboard',
        'sign_in' => 'Sign in',
    ],

    'landing' => [
        'headline' => 'The books an exchange office actually keeps',
        'tagline' => 'Money in, money out, and one running balance per client per currency. Nothing is converted behind your back and nothing is added across currencies.',
        'start' => 'Start free',
        'sign_in' => 'Sign in',
        'sign_up' => 'Sign up',
        'enter' => 'Open the dashboard',
        'free_note' => 'No card. Your books are yours and nobody else can see them.',

        'features' => [
            'record' => [
                'title' => 'In and Out, nothing else to decide',
                'body' => 'Money you took, and money you paid. No loan, credit, settlement or refund to classify at the counter before you know which it was.',
            ],
            'balance' => [
                'title' => 'One balance, and it says which way',
                'body' => 'A client owes you or you owe them. One figure per currency, with the direction written beside it rather than left to a minus sign.',
            ],
            'currencies' => [
                'title' => 'Take dollars, book pounds',
                'body' => 'Record a movement in whichever currency you keep the account in, at the rate you agreed. What actually changed hands stays on the record.',
            ],
            'statements' => [
                'title' => 'A statement you can hand over',
                'body' => 'Every line of one account in one currency, as a page, a PDF or a spreadsheet. Your copy shows the margin; the client\'s copy never does.',
            ],
            'trail' => [
                'title' => 'Nothing is edited away',
                'body' => 'The ledger is append-only, enforced by the database itself. A mistake is corrected by reversing it, and both entries stay.',
            ],
            'language' => [
                'title' => 'Arabic and English',
                'body' => 'The whole interface, right to left or left to right, switched whenever you like. Statements print correctly in both.',
            ],
        ],

        'privacy' => [
            'title' => 'Your books are only yours',
            'body' => 'Every business here has its own separate set of books. No client, balance, rate or margin is shared between them, and the application refuses to run a query that has not said whose books it is reading.',
        ],

        'footer' => 'MonyMonk :year — books for exchange offices.',
    ],
];
