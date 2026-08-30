<?php

declare(strict_types=1);

return [
    'title' => 'Counterparties',
    'description' => 'People and organisations the business deals with, and what each side is holding or owed.',
    'create_title' => 'Add counterparty',
    'edit_title' => 'Edit :name',
    'empty' => 'No counterparties yet.',
    'created' => 'Counterparty added.',
    'updated' => 'Counterparty updated.',

    'fields' => [
        'name' => 'Name',
        'type' => 'Type',
        'phone' => 'Phone',
        'email' => 'Email',
        'country' => 'Country',
        'preferred_currency' => 'Preferred currency',
        'is_active' => 'Active',
    ],

    'types' => [
        'customer' => 'Customer',
        'supplier' => 'Supplier',
        'partner' => 'Partner',
        'personal' => 'Personal contact',
        'business' => 'Business',
        'employee' => 'Employee',
        'other' => 'Other',
    ],

    // The list shows one figure a side. The four buckets are still what the ledger
    // holds and what the statement shows; these name the two sides in the plainest
    // words there are for them.
    'opening_hint' => 'Where the relationship stood before you started recording — one figure per currency. Positive means they owed you; negative means you were holding money of theirs. Saving writes it to the ledger, dated today.',
    'opening_positions' => 'Opening balance',
    'balance' => 'Balance',
    'balance_hint' => 'Everything that went out to them, less everything that came in. Positive means they owe us; negative means we owe them.',
    'they_owe_us' => 'they owe us',
    'we_owe_them' => 'we owe them',
    'settled' => 'Nothing either way',
    'list_hint' => 'One running balance per currency: everything paid out, less everything taken in. Open a statement to see the movements behind a figure.',
    'open_statement' => 'Open the statement for :currency',
    'unposted_opening' => 'Opening position not posted',
    'opening_transaction' => 'Opening balance',
    'unposted_opening_hint' => 'Declared on this party but never recorded as a transaction, so it is not in the figures here. Open the statement to see it.',
    'no_positions' => 'No opening positions declared.',
    'nothing_declared' => '—',
];
