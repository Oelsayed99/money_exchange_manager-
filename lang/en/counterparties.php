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

    'buckets' => [
        'custody' => 'Custody',
        'receivable' => 'Receivable',
        'payable' => 'Payable',
        'credit_trust' => 'Credit held',
    ],

    'bucket_hints' => [
        'custody' => 'Our money, physically held by them.',
        'receivable' => 'Their money, owed to us.',
        'payable' => 'Our money, owed to them.',
        'credit_trust' => 'Their money, physically held by us.',
    ],

    'opening_positions' => 'Opening positions',
    'opening_hint' => 'These are kept apart on purpose. A party can owe money and hold money at the same time, and netting the two hides what you need to act on. A negative figure is not accepted — record it in the opposite column instead.',
    'assets' => 'Owed to us or held for us',
    'liabilities' => 'Owed by us or held by us',

    // The list shows one figure a side. The four buckets are still what the ledger
    // holds and what the statement shows; these name the two sides in the plainest
    // words there are for them.
    'ours_with_them' => 'Our money with them',
    'theirs_with_us' => 'Their money with us',
    'ours_hint' => 'What they hold of ours and what they owe us, added together. The split is on the statement.',
    'theirs_hint' => 'What we hold of theirs and what we owe them, added together. The split is on the statement.',
    'settled' => 'Nothing either way',
    'list_hint' => 'Each column is one side added up. Behind them the ledger keeps four positions apart — :buckets — and never nets one against another. Open a statement to see the split and the movements behind it.',
    'open_statement' => 'Open the statement for :currency',
    'unposted_opening' => 'Opening position not posted',
    'opening_transaction' => 'Opening position — :bucket',
    'unposted_opening_hint' => 'Declared on this party but never recorded as a transaction, so it is not in the figures here. Open the statement to see it.',
    'no_positions' => 'No opening positions declared.',
    'negative_not_allowed' => 'A negative :bucket is a :mirror. Record it there instead.',
    'nothing_declared' => '—',
];
