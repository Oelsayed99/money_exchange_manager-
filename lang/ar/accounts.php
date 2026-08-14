<?php

declare(strict_types=1);

return [
    'title' => 'الحسابات',
    'description' => 'أماكن حفظ الأموال: البنوك، الخزائن، المحافظ، العُهد وحسابات الأمانة.',
    'create_title' => 'إضافة حساب',
    'edit_title' => 'تعديل :name',
    'empty' => 'لا توجد حسابات بعد.',
    'created' => 'تمت إضافة الحساب.',
    'updated' => 'تم تحديث الحساب.',

    'fields' => [
        'name' => 'الاسم',
        'type' => 'النوع',
        'counterparty' => 'يخص',
        'owner' => 'المسؤول',
        'provider' => 'البنك أو مزود الخدمة',
        'identifier' => 'رقم الحساب',
        'currencies' => 'العملات المحفوظة',
        'opening_balance' => 'الرصيد الافتتاحي',
        'is_active' => 'مفعّل',
        'sort_order' => 'ترتيب العرض',
    ],

    'hints' => [
        'identifier' => 'يُحفظ بشكل آمن ويُعرض مخفيًا. سجل التدقيق يسجل أنه تغيّر، لا الرقم نفسه.',
        'counterparty' => 'مطلوب لحسابات الأمانة ورصيد العميل وعهدة الشريك. اتركه فارغًا للأماكن المملوكة للنشاط.',
        'currencies' => 'اختر كل عملة يحتفظ بها هذا المكان، وحدد الرصيد الافتتاحي لها.',
    ],

    'types' => [
        'bank' => 'حساب بنكي',
        'cash_wallet' => 'محفظة نقدية',
        'safe' => 'خزنة',
        'personal_custody' => 'عهدة شخصية',
        'business_custody' => 'عهدة النشاط',
        'mobile_wallet' => 'محفظة إلكترونية',
        'exchange_account' => 'حساب صرافة',
        'partner_custody' => 'عهدة شريك',
        'customer_balance' => 'رصيد عميل',
        'credit_trust' => 'أمانة / ائتمان',
        'other' => 'أخرى',
    ],

    'none' => 'مملوك للنشاط',
    'liability_note' => 'التزام — أموال محفوظة لحساب طرف آخر',
];
