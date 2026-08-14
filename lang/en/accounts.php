<?php

declare(strict_types=1);

return [
    'title' => 'Accounts',
    'description' => 'Where money is held: banks, safes, wallets, custody and credit accounts.',
    'create_title' => 'Add account',
    'edit_title' => 'Edit :name',
    'empty' => 'No accounts yet.',
    'created' => 'Account added.',
    'updated' => 'Account updated.',

    'fields' => [
        'name' => 'Name',
        'type' => 'Type',
        'counterparty' => 'Belongs to',
        'owner' => 'Owner',
        'provider' => 'Bank or provider',
        'identifier' => 'Account number',
        'currencies' => 'Currencies held',
        'opening_balance' => 'Opening balance',
        'is_active' => 'Active',
        'sort_order' => 'Sort order',
    ],

    'hints' => [
        'identifier' => 'Stored securely and shown masked. The audit trail records that it changed, never the number.',
        'counterparty' => 'Required for credit/trust, customer balance and partner custody. Leave empty for a location the business owns.',
        'currencies' => 'Tick each currency this location holds, and declare what it started with.',
    ],

    'types' => [
        'bank' => 'Bank account',
        'cash_wallet' => 'Cash wallet',
        'safe' => 'Safe',
        'personal_custody' => 'Personal custody',
        'business_custody' => 'Business custody',
        'mobile_wallet' => 'Mobile wallet',
        'exchange_account' => 'Exchange account',
        'partner_custody' => 'Partner custody',
        'customer_balance' => 'Customer balance',
        'credit_trust' => 'Credit / trust',
        'other' => 'Other',
    ],

    'none' => 'Owned by the business',
    'liability_note' => 'Liability — money held on behalf of someone else',
];
