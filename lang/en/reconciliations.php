<?php

declare(strict_types=1);

return [
    'title' => 'Reconciliation',
    'description' => 'Does what you hold agree with what the ledger says you hold?',

    'statuses' => [
        'balanced' => 'Balanced',
        'open' => 'Difference',
        'resolved' => 'Explained',
    ],
    'status_hints' => [
        'balanced' => 'The count and the ledger agree exactly.',
        'open' => 'They disagree, and nobody has said why yet.',
        'resolved' => 'They disagree, and the reason is recorded.',
    ],

    'record' => 'Record a count',
    'recorded' => 'Count recorded.',
    'resolved' => 'Difference explained.',

    'account' => 'Where',
    'currency' => 'Currency',
    'as_of' => 'As of',
    'counted' => 'Counted',
    'counted_hint' => 'What is actually there. Count first, then compare.',
    'ledger' => 'Ledger says',
    'difference' => 'Difference',
    'note' => 'Notes',
    'show_expected' => 'Show what the ledger says',
    'expected_hidden' => 'Hidden until you ask, so the figure does not lead the count.',

    'surplus' => 'More than expected',
    'shortfall' => 'Less than expected',

    'resolution' => 'Explanation',
    'resolution_hint' => 'What accounts for the difference. If you corrected the ledger, give the transaction number.',
    'adjustment' => 'Adjusting transaction',
    'explain' => 'Explain',
    'explained_by' => 'Explained by :name on :date',
    'adjusted_by' => 'Corrected by transaction #:id',
    'nothing_to_explain' => 'This reconciliation balanced. There is no difference to explain.',
    'already_counted' => 'This account and currency were already counted on that day. Record a different day, or leave the original in place.',

    'drift' => 'The ledger has moved since this count',
    'drift_hint' => 'Something dated on or before this day was posted after the count, so this reconciliation no longer describes the ledger. Not necessarily wrong — backdated entries are normal — but worth knowing.',

    'none' => 'No reconciliation matches these filters.',
    'never' => 'Nothing has been reconciled yet.',
    'read_only' => 'A reconciliation records what was found. Its figures cannot be edited, and it never writes a balance — a real error is corrected by posting an adjustment.',
    'all' => 'All',
];
