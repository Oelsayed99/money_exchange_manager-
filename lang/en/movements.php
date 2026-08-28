<?php

declare(strict_types=1);

return [
    'title' => 'Record a movement',
    'description' => 'Credit in and out, lending and borrowing, settlements, transfers, fees and expenses.',

    'type' => 'What happened',
    'occurred_at' => 'Date',
    'amount' => 'Amount',
    'amount_positive' => 'An amount has to be greater than zero. A movement the other way is a different type, not a negative number.',
    'currency' => 'Currency',
    'account' => 'From / into',
    'destination_account' => 'To',
    'counterparty' => 'Counterparty',
    'bucket' => 'Which position',
    'method' => 'How the money moved',
    'reference' => 'Reference',
    'note' => 'Notes',

    'record' => 'Record movement',
    'recorded' => 'Movement recorded.',

    'positions' => 'Their positions',
    'positions_hint' => 'Where this counterparty stands with you now, in this currency.',
    'after' => 'After this movement',
    'increases' => 'increases',
    'decreases' => 'decreases',
    'pick_counterparty' => 'Choose a counterparty to see where they stand.',

    'negative' => 'This takes the balance below zero',
    'negative_body' => 'Paying out more than they left with you means they now owe you. This is allowed and will be recorded as a negative credit — but a loan given may be what you actually mean.',
];
