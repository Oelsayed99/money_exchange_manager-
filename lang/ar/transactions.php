<?php

declare(strict_types=1);

return [
    'exchange' => [
        'title' => 'صرف عملة',
        'description' => 'سجّل المبلغ المستلم والمبلغ المسلَّم كطرفين، مع السعر وهامش الربح.',
        'received' => 'المستلم',
        'delivered' => 'المسلَّم',
        'received_hint' => 'ما دخل، وإلى أين ذهب.',
        'delivered_hint' => 'ما خرج، ومن أين خرج.',
        'into_account' => 'إلى',
        'from_account' => 'من',
        'counterparty' => 'الطرف',
        'method' => 'طريقة الحركة',
        'occurred_at' => 'التاريخ',
        'reference' => 'المرجع',
        'description' => 'ملاحظات',
        'profit' => 'الربح',
        'profit_method' => 'طريقة احتساب الهامش',
        'cost_rate' => 'سعر التكلفة',
        'cost_rate_hint' => 'ما كلّفتك فعليًا وحدة العملة المسلَّمة.',
        'spread_type' => 'قيمة الفارق تعني',
        'spread_value' => 'الفارق',
        'fees' => 'رسوم محصّلة',
        'expenses' => 'مصروفات',
        'commissions' => 'عمولات مدفوعة',
        'record' => 'تسجيل العملية',
        'recorded' => 'تم تسجيل العملية.',
        'no_counterparty' => 'بدون طرف',
    ],

    'preview' => [
        'title' => 'الحساب',
        'customer_rate' => 'سعر العميل',
        'cost_rate' => 'سعر التكلفة',
        'customer_value' => 'قيمة العميل',
        'cost_value' => 'قيمة التكلفة',
        'gross_profit' => 'الربح الإجمالي',
        'fees' => 'رسوم محصّلة',
        'expenses' => 'مصروفات',
        'commissions' => 'عمولات',
        'net_profit' => 'صافي الربح',
        'awaiting' => 'أدخل المبلغين لعرض الحساب.',
        'per_unit' => 'لكل وحدة مسلَّمة',
    ],

    'loss' => [
        'heading' => 'هذه العملية بخسارة',
        'body' => 'صافي الربح سالب. أكّد أن ذلك مقصود قبل التسجيل.',
        'confirm' => 'أفهم أن هذه العملية تُسجَّل بخسارة',
        'required' => 'هذه العملية بخسارة. أكّد ذلك قبل التسجيل.',
    ],

    'types' => [
        'opening_balance' => 'رصيد افتتاحي',
        'deposit' => 'إيداع رأس مال',
        'withdrawal' => 'سحب رأس مال',
        'transfer' => 'تحويل داخلي',
        'money_received' => 'مبلغ مستلم',
        'money_paid' => 'مبلغ مدفوع',
        'loan_given' => 'قرض ممنوح',
        'loan_received' => 'قرض مستلم',
        'receivable_settlement' => 'تسوية مستحق لنا',
        'payable_settlement' => 'تسوية مستحق علينا',
        'currency_exchange' => 'صرف عملة',
        'credit_deposit' => 'إيداع أمانة',
        'credit_settlement' => 'تسوية أمانة',
        'fee' => 'رسوم',
        'expense' => 'مصروف',
        'profit_adjustment' => 'تسوية ربح',
        'balance_adjustment' => 'تسوية رصيد',
        'refund' => 'استرداد',
        'reversal' => 'عكس قيد',
    ],

    'methods' => [
        'transfer' => 'تحويل بنكي',
        'deposit' => 'إيداع',
        'cash' => 'كاش',
        'cheque' => 'شيك',
        'other' => 'أخرى',
    ],

    'profit_methods' => [
        'rate_difference' => 'فرق السعر',
        'fixed_amount' => 'مبلغ ثابت',
        'percentage' => 'فارق',
        'manual' => 'يدوي',
        'none' => 'بدون ربح',
    ],

    'spread_types' => [
        'per_unit' => 'وحدات عملة لكل وحدة مسلَّمة',
        'percentage' => 'نسبة مئوية من القيمة',
        'fixed_amount' => 'مبلغ مقطوع للعملية',
    ],

    'spread_warning' => 'حدّد المقصود: 0.02 كوحدات لكل وحدة تختلف كثيرًا عن 0.02 بالمئة.',
];
