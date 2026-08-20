<?php

declare(strict_types=1);

return [
    'title' => 'Audit trail',
    'description' => 'Every change to a record, who made it and what it was before.',

    'events' => [
        'created' => 'Created',
        'updated' => 'Changed',
        'deleted' => 'Deleted',
        'restored' => 'Restored',
    ],

    'types' => [
        'transaction' => 'Transaction',
        'reconciliation' => 'Reconciliation',
        'account' => 'Account',
        'counterparty' => 'Counterparty',
        'currency' => 'Currency',
        'user' => 'User',
    ],

    'columns' => [
        'when' => 'When',
        'who' => 'Who',
        'what' => 'What',
        'change' => 'Change',
    ],

    'filters' => [
        'event' => 'Event',
        'type' => 'Record',
        'user' => 'Who',
        'from' => 'From',
        'to' => 'To',
        'search' => 'Name or record number',
        'all' => 'All',
        'clear' => 'Clear filters',
    ],

    'record' => 'Record #:id',
    'no_changes' => 'No field changed.',
    'nothing' => 'Nothing was recorded before.',
    'empty' => 'Empty',
    'none' => 'No entry matches these filters.',
    'showing' => 'Showing :from–:to of :total',
    'previous' => 'Previous',
    'next' => 'Next',
    'system' => 'System',
    'console' => 'Command line',

    'append_only' => 'The trail is append-only. Entries cannot be edited or removed, including from here.',
    'redaction' => 'Secrets are recorded as changed without recording their value — a password change appears, the password does not.',
];
