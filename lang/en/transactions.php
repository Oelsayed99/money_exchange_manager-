<?php

declare(strict_types=1);

return [
    'exchange' => [
        'title' => 'Currency exchange',
        'description' => 'Record money received and money delivered as two legs, with the rate and the margin.',
        'received' => 'Received',
        'delivered' => 'Delivered',
        'received_hint' => 'What came in, and where it went.',
        'delivered_hint' => 'What went out, and where it came from.',
        'into_account' => 'Into',
        'from_account' => 'From',
        'counterparty' => 'Counterparty',
        'method' => 'How the money moved',
        'occurred_at' => 'Date',
        'reference' => 'Reference',
        'description' => 'Notes',
        'profit' => 'Profit',
        'profit_method' => 'How the margin is worked out',
        'cost_rate' => 'Cost rate',
        'cost_rate_hint' => 'What the delivered currency actually cost you, per unit.',
        'spread_type' => 'The spread value means',
        'spread_value' => 'Spread',
        'fees' => 'Fees charged',
        'expenses' => 'Expenses',
        'commissions' => 'Commissions paid',
        'record' => 'Record exchange',
        'recorded' => 'Exchange recorded.',
        'no_counterparty' => 'No counterparty',
    ],

    'preview' => [
        'title' => 'Calculation',
        'customer_rate' => 'Customer rate',
        'cost_rate' => 'Cost rate',
        'customer_value' => 'Customer value',
        'cost_value' => 'Cost value',
        'gross_profit' => 'Gross profit',
        'fees' => 'Fees charged',
        'expenses' => 'Expenses',
        'commissions' => 'Commissions',
        'net_profit' => 'Net profit',
        'awaiting' => 'Fill in both amounts to see the calculation.',
        'per_unit' => 'per unit delivered',
    ],

    'loss' => [
        'heading' => 'This deal loses money',
        'body' => 'The net profit is negative. Confirm that this is intended before recording it.',
        'confirm' => 'I understand this records a loss',
        'required' => 'This deal records a loss. Tick the confirmation before recording it.',
    ],

    'types' => [
        'opening_balance' => 'Opening balance',
        'deposit' => 'Deposit',
        'withdrawal' => 'Withdrawal',
        'transfer' => 'Transfer',
        'money_received' => 'Money received',
        'money_paid' => 'Money paid',
        'loan_given' => 'Loan given',
        'loan_received' => 'Loan received',
        'receivable_settlement' => 'Receivable settlement',
        'payable_settlement' => 'Payable settlement',
        'currency_exchange' => 'Currency exchange',
        'credit_deposit' => 'Credit deposit',
        'credit_settlement' => 'Credit settlement',
        'fee' => 'Fee',
        'expense' => 'Expense',
        'profit_adjustment' => 'Profit adjustment',
        'balance_adjustment' => 'Balance adjustment',
        'refund' => 'Refund',
        'reversal' => 'Reversal',
    ],

    'methods' => [
        'transfer' => 'Bank transfer',
        'deposit' => 'Deposit',
        'cash' => 'Cash',
        'cheque' => 'Cheque',
        'other' => 'Other',
    ],

    'profit_methods' => [
        'rate_difference' => 'Rate difference',
        'fixed_amount' => 'Fixed amount',
        'percentage' => 'Spread',
        'manual' => 'Entered by hand',
        'none' => 'No profit',
    ],

    'spread_types' => [
        'per_unit' => 'Currency units per unit delivered',
        'percentage' => 'A percentage of the value',
        'fixed_amount' => 'A flat amount for the deal',
    ],

    'spread_warning' => 'Say which you mean: 0.02 as units per unit is a very different margin from 0.02 per cent.',
];
