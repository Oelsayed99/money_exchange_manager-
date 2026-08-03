<?php

declare(strict_types=1);

/**
 * Arabic validation messages.
 *
 * Deliberately a subset rather than a full translation of Laravel's validation file.
 * The translator resolves each key individually and falls back to the fallback locale
 * for anything missing, so covering the rules this application actually uses gives
 * correct Arabic where it matters without shipping a hundred machine-quality strings.
 *
 * Add to this file whenever a new rule reaches a user-facing form.
 */
return [
    'required' => 'حقل :attribute مطلوب.',
    'string' => 'يجب أن يكون :attribute نصًا.',
    'integer' => 'يجب أن يكون :attribute رقمًا صحيحًا.',
    'boolean' => 'يجب أن يكون :attribute صحيحًا أو خطأً.',
    'unique' => ':attribute مستخدم من قبل.',
    'in' => ':attribute المحدد غير صالح.',
    'regex' => 'صيغة :attribute غير صحيحة.',
    'confirmed' => 'تأكيد :attribute غير مطابق.',
    'email' => 'يجب أن يكون :attribute بريدًا إلكترونيًا صحيحًا.',

    'between' => [
        'numeric' => 'يجب أن يكون :attribute بين :min و :max.',
        'string' => 'يجب أن يكون :attribute بين :min و :max حرفًا.',
    ],

    'max' => [
        'numeric' => 'يجب ألا يزيد :attribute عن :max.',
        'string' => 'يجب ألا يزيد :attribute عن :max حرفًا.',
    ],

    'min' => [
        'numeric' => 'يجب ألا يقل :attribute عن :min.',
        'string' => 'يجب ألا يقل :attribute عن :min حرفًا.',
    ],

    'attributes' => [
        'code' => 'الرمز',
        'name' => 'الاسم',
        'name_ar' => 'الاسم بالعربية',
        'symbol' => 'العلامة',
        'decimal_places' => 'عدد الخانات العشرية',
        'is_active' => 'الحالة',
        'sort_order' => 'ترتيب العرض',
        'email' => 'البريد الإلكتروني',
        'password' => 'كلمة المرور',
        'name_field' => 'الاسم',
        'locale' => 'اللغة',
    ],
];
