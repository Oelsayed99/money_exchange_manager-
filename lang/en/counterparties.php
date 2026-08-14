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
    'no_positions' => 'No opening positions declared.',
    'negative_not_allowed' => 'A negative :bucket is a :mirror. Record it there instead.',
    'nothing_declared' => '—',
];
