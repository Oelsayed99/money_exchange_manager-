<?php

declare(strict_types=1);

return [
    'title' => 'Statement',
    'description' => 'Every line of this account, in one currency, taken from the ledger.',

    'modes' => [
        'internal' => 'My copy',
        'client' => "Client's copy",
    ],
    'mode' => 'Showing',
    'mode_hint_internal' => 'Includes the margin on each deal. Not for sending out.',
    'mode_hint_client' => 'The money that moved, and nothing else.',

    'columns' => [
        'date' => 'Date',
        'details' => 'Details',
        'in' => 'In',
        'out' => 'Out',
        'position' => 'Balance after',
        'moved' => 'Actually moved',
        'rate' => 'Rate',
        'profit' => 'Profit',
    ],

    'totals' => 'Totals',
    'in_hint' => 'Money we took from them.',
    'out_hint' => 'Money we paid to them.',
    'they_owe_us' => 'They owe us :amount.',
    'we_hold_theirs' => 'We are holding :amount of theirs.',
    'settled_now' => 'Nothing either way.',
    'moved_as' => ':amount at :rate',

    'opening' => 'Opening',
    'closing' => 'Closing',
    'total_in' => 'Total in',
    'total_out' => 'Total out',
    'profit_total' => 'Margin earned',

    'currency' => 'Currency',
    'from' => 'From',
    'to' => 'To',
    'clear_dates' => 'Any date',
    'print' => 'Print',
    'download_pdf' => 'Download PDF',
    'page' => 'Page',
    'period' => ':from to :to',
    'all_dates' => 'All dates',
    'generated_at' => 'Generated :at',

    'settled' => 'Settled — neither party holds anything of the other.',
    'no_activity' => 'Nothing recorded in this period.',
    'no_currencies' => 'This party has no ledger activity yet, so there is nothing to state.',

    'declared_opening' => 'Declared opening position not in the ledger',
    'declared_opening_body' => 'An opening position was recorded on this party but never posted as a transaction, so it is not part of the figures below. Post it as an opening balance to include it.',

    'from_ledger' => 'Every figure here comes from the ledger. Positions are kept apart and never added together.',
];
