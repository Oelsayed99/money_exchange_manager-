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

    /*
     * What a balance in each bucket means to whoever is reading the page.
     *
     * The sheet this replaces used one signed column, so "(899,510)" and "50,490" were
     * distinguished by a parenthesis. These labels exist so nobody has to interpret a
     * sign to know whether they are owed money or holding it.
     */
    'positions' => [
        'custody' => 'Our money held by them',
        'receivable' => 'Owed to us',
        'payable' => 'Owed to them',
        'credit_trust' => 'Client credit with us',
    ],

    'columns' => [
        'date' => 'Date',
        'details' => 'Details',
        'in' => 'In',
        'out' => 'Out',
        'position' => 'Position after',
        'profit' => 'Profit',
    ],

    'in_hint' => 'Value from them to us.',
    'out_hint' => 'Value from us to them.',

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

    'settled' => 'Settled — neither party holds anything of the other.',
    'no_activity' => 'Nothing recorded in this period.',
    'no_currencies' => 'This party has no ledger activity yet, so there is nothing to state.',

    'declared_opening' => 'Declared opening position not in the ledger',
    'declared_opening_body' => 'An opening position was recorded on this party but never posted as a transaction, so it is not part of the figures below. Post it as an opening balance to include it.',

    'from_ledger' => 'Every figure here comes from the ledger. Positions are kept apart and never added together.',
];
