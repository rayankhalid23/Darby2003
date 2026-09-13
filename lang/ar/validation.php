<?php

/**
 * ترجمة عربية لرسائل التحقق الافتراضية بـ Laravel.
 *
 * ⚠️ لم يكن هذا الملف موجوداً إطلاقاً رغم أن `config/app.php` يضبط
 * locale='ar' — أي قاعدة تحقق (validate()) لا تحمل رسالة مخصّصة صريحة
 * بالكنترولر كانت تُسرّب مفتاح الترجمة الخام للمستخدم حرفياً، مثال حقيقي
 * لاحظته أثناء اختبار شحن المحفظة: إرسال payment_method_id غير موجود
 * أرجع رسالة الخطأ "validation.exists" بدل نص عربي مفهوم. هذا يمس أي
 * endpoint بالمشروع كله وليس مسار الشحن فقط.
 */
return [

    'accepted'             => 'يجب قبول :attribute.',
    'accepted_if'          => 'يجب قبول :attribute عندما يكون :other هو :value.',
    'active_url'           => ':attribute ليس رابطاً صالحاً.',
    'after'                => 'يجب أن يكون :attribute تاريخاً بعد :date.',
    'after_or_equal'       => 'يجب أن يكون :attribute تاريخاً بعد أو يساوي :date.',
    'alpha'                => 'يجب أن يحتوي :attribute على أحرف فقط.',
    'alpha_dash'           => 'يجب أن يحتوي :attribute على أحرف وأرقام وشرطات وشرطات سفلية فقط.',
    'alpha_num'            => 'يجب أن يحتوي :attribute على أحرف وأرقام فقط.',
    'array'                => 'يجب أن يكون :attribute قائمة (array).',
    'ascii'                => 'يجب أن يحتوي :attribute على أحرف ورموز إنجليزية فقط.',
    'before'               => 'يجب أن يكون :attribute تاريخاً قبل :date.',
    'before_or_equal'      => 'يجب أن يكون :attribute تاريخاً قبل أو يساوي :date.',
    'between'              => [
        'array'   => 'يجب أن يحتوي :attribute على عدد عناصر بين :min و :max.',
        'file'    => 'يجب أن يكون حجم :attribute بين :min و :max كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute بين :min و :max.',
        'string'  => 'يجب أن يكون طول :attribute بين :min و :max حرفاً.',
    ],
    'boolean'              => 'يجب أن تكون قيمة :attribute صحيحة أو خاطئة (true/false).',
    'confirmed'            => 'تأكيد :attribute غير متطابق.',
    'current_password'     => 'كلمة المرور غير صحيحة.',
    'date'                 => ':attribute ليس تاريخاً صالحاً.',
    'date_equals'          => 'يجب أن يكون :attribute تاريخاً مساوياً لـ :date.',
    'date_format'          => 'لا يطابق :attribute الصيغة :format.',
    'decimal'              => 'يجب أن يحتوي :attribute على :decimal منزلة عشرية.',
    'declined'             => 'يجب رفض :attribute.',
    'different'            => 'يجب أن يكون :attribute و :other مختلفين.',
    'digits'               => 'يجب أن يتكوّن :attribute من :digits أرقام.',
    'digits_between'       => 'يجب أن يتكوّن :attribute من عدد أرقام بين :min و :max.',
    'dimensions'           => 'أبعاد الصورة :attribute غير صالحة.',
    'distinct'             => 'تحتوي قيمة :attribute على عنصر مكرر.',
    'doesnt_end_with'      => 'يجب ألا ينتهي :attribute بأي من القيم التالية: :values.',
    'doesnt_start_with'    => 'يجب ألا يبدأ :attribute بأي من القيم التالية: :values.',
    'email'                => 'يجب أن يكون :attribute بريداً إلكترونياً صالحاً.',
    'ends_with'            => 'يجب أن ينتهي :attribute بأحد القيم التالية: :values.',
    'enum'                 => 'القيمة المختارة لـ :attribute غير صالحة.',
    'exists'               => 'القيمة المختارة لـ :attribute غير موجودة.',
    'file'                 => 'يجب أن يكون :attribute ملفاً.',
    'filled'               => 'يجب ألا يكون حقل :attribute فارغاً.',
    'gt'                   => [
        'array'   => 'يجب أن يحتوي :attribute على عناصر أكثر من :value.',
        'file'    => 'يجب أن يكون حجم :attribute أكبر من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أكبر من :value.',
        'string'  => 'يجب أن يكون طول :attribute أكبر من :value حرفاً.',
    ],
    'gte'                  => [
        'array'   => 'يجب أن يحتوي :attribute على :value عنصر أو أكثر.',
        'file'    => 'يجب أن يكون حجم :attribute أكبر من أو يساوي :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أكبر من أو تساوي :value.',
        'string'  => 'يجب أن يكون طول :attribute أكبر من أو يساوي :value حرفاً.',
    ],
    'image'                => 'يجب أن يكون :attribute صورة.',
    'in'                   => 'القيمة المختارة لـ :attribute غير صالحة.',
    'in_array'             => 'حقل :attribute غير موجود ضمن :other.',
    'integer'              => 'يجب أن يكون :attribute رقماً صحيحاً.',
    'ip'                   => 'يجب أن يكون :attribute عنوان IP صالحاً.',
    'ipv4'                 => 'يجب أن يكون :attribute عنوان IPv4 صالحاً.',
    'ipv6'                 => 'يجب أن يكون :attribute عنوان IPv6 صالحاً.',
    'json'                 => 'يجب أن يكون :attribute نص JSON صالحاً.',
    'lowercase'            => 'يجب أن يكون :attribute بحروف صغيرة.',
    'lt'                   => [
        'array'   => 'يجب أن يحتوي :attribute على عناصر أقل من :value.',
        'file'    => 'يجب أن يكون حجم :attribute أقل من :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أقل من :value.',
        'string'  => 'يجب أن يكون طول :attribute أقل من :value حرفاً.',
    ],
    'lte'                  => [
        'array'   => 'يجب ألا يحتوي :attribute على أكثر من :value عنصر.',
        'file'    => 'يجب أن يكون حجم :attribute أقل من أو يساوي :value كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute أقل من أو تساوي :value.',
        'string'  => 'يجب أن يكون طول :attribute أقل من أو يساوي :value حرفاً.',
    ],
    'mac_address'          => 'يجب أن يكون :attribute عنوان MAC صالحاً.',
    'max'                  => [
        'array'   => 'يجب ألا يحتوي :attribute على أكثر من :max عنصر.',
        'file'    => 'يجب ألا يتجاوز حجم :attribute عن :max كيلوبايت.',
        'numeric' => 'يجب ألا تكون قيمة :attribute أكبر من :max.',
        'string'  => 'يجب ألا يتجاوز طول :attribute عن :max حرفاً.',
    ],
    'max_digits'           => 'يجب ألا يحتوي :attribute على أكثر من :max رقم.',
    'mimes'                => 'يجب أن يكون :attribute ملفاً من نوع: :values.',
    'mimetypes'            => 'يجب أن يكون :attribute ملفاً من نوع: :values.',
    'min'                  => [
        'array'   => 'يجب أن يحتوي :attribute على :min عنصر على الأقل.',
        'file'    => 'يجب ألا يقل حجم :attribute عن :min كيلوبايت.',
        'numeric' => 'يجب ألا تقل قيمة :attribute عن :min.',
        'string'  => 'يجب ألا يقل طول :attribute عن :min حرفاً.',
    ],
    'min_digits'           => 'يجب أن يحتوي :attribute على :min رقم على الأقل.',
    'missing'              => 'يجب أن يكون حقل :attribute مفقوداً.',
    'missing_if'           => 'يجب أن يكون حقل :attribute مفقوداً عندما يكون :other هو :value.',
    'missing_unless'       => 'يجب أن يكون حقل :attribute مفقوداً إلا إذا كان :other هو :value.',
    'missing_with'         => 'يجب أن يكون حقل :attribute مفقوداً عند وجود :values.',
    'missing_with_all'     => 'يجب أن يكون حقل :attribute مفقوداً عند وجود :values.',
    'multiple_of'          => 'يجب أن تكون قيمة :attribute من مضاعفات :value.',
    'not_in'               => 'القيمة المختارة لـ :attribute غير صالحة.',
    'not_regex'            => 'صيغة :attribute غير صالحة.',
    'numeric'              => 'يجب أن يكون :attribute رقماً.',
    'password'             => [
        'letters'       => 'يجب أن يحتوي :attribute على حرف واحد على الأقل.',
        'mixed'         => 'يجب أن يحتوي :attribute على حرف كبير وحرف صغير على الأقل.',
        'numbers'       => 'يجب أن يحتوي :attribute على رقم واحد على الأقل.',
        'symbols'       => 'يجب أن يحتوي :attribute على رمز واحد على الأقل.',
        'uncompromised' => 'القيمة المُدخلة لـ :attribute ظهرت ضمن تسريب بيانات معروف. يرجى اختيار قيمة أخرى.',
    ],
    'present'              => 'يجب أن يكون حقل :attribute موجوداً.',
    'present_if'           => 'يجب أن يكون حقل :attribute موجوداً عندما يكون :other هو :value.',
    'present_unless'       => 'يجب أن يكون حقل :attribute موجوداً إلا إذا كان :other هو :value.',
    'present_with'         => 'يجب أن يكون حقل :attribute موجوداً عند وجود :values.',
    'present_with_all'     => 'يجب أن يكون حقل :attribute موجوداً عند وجود :values.',
    'prohibited'           => 'حقل :attribute محظور.',
    'prohibited_if'        => 'حقل :attribute محظور عندما يكون :other هو :value.',
    'prohibited_unless'    => 'حقل :attribute محظور إلا إذا كان :other ضمن :values.',
    'prohibits'            => 'يمنع حقل :attribute وجود :other.',
    'regex'                => 'صيغة :attribute غير صالحة.',
    'required'             => 'حقل :attribute مطلوب.',
    'required_array_keys'  => 'يجب أن يحتوي حقل :attribute على مدخلات لـ: :values.',
    'required_if'          => 'حقل :attribute مطلوب عندما يكون :other هو :value.',
    'required_if_accepted' => 'حقل :attribute مطلوب عندما يكون :other مقبولاً.',
    'required_unless'      => 'حقل :attribute مطلوب إلا إذا كان :other ضمن :values.',
    'required_with'        => 'حقل :attribute مطلوب عند وجود :values.',
    'required_with_all'    => 'حقل :attribute مطلوب عند وجود :values.',
    'required_without'     => 'حقل :attribute مطلوب عند عدم وجود :values.',
    'required_without_all' => 'حقل :attribute مطلوب عند عدم وجود أي من :values.',
    'same'                 => 'يجب أن يتطابق :attribute و :other.',
    'size'                 => [
        'array'   => 'يجب أن يحتوي :attribute على :size عنصر.',
        'file'    => 'يجب أن يكون حجم :attribute مساوياً لـ :size كيلوبايت.',
        'numeric' => 'يجب أن تكون قيمة :attribute مساوية لـ :size.',
        'string'  => 'يجب أن يكون طول :attribute مساوياً لـ :size حرفاً.',
    ],
    'starts_with'          => 'يجب أن يبدأ :attribute بأحد القيم التالية: :values.',
    'string'               => 'يجب أن يكون :attribute نصاً.',
    'timezone'             => 'يجب أن يكون :attribute منطقة زمنية صالحة.',
    'unique'               => ':attribute مُستخدم من قبل، يرجى اختيار قيمة أخرى.',
    'uploaded'             => 'فشل رفع :attribute.',
    'uppercase'            => 'يجب أن يكون :attribute بحروف كبيرة.',
    'url'                  => 'يجب أن يكون :attribute رابطاً صالحاً.',
    'ulid'                 => 'يجب أن يكون :attribute من نوع ULID صالح.',
    'uuid'                 => 'يجب أن يكون :attribute من نوع UUID صالح.',

    /*
    |--------------------------------------------------------------------------
    | رسائل مخصّصة لحقل + قاعدة محدّدين
    |--------------------------------------------------------------------------
    | استخدم الصيغة "field.rule" لتخصيص رسالة حقل بعينه بدل الرسالة العامة أعلاه.
    */
    'custom' => [],

    /*
    |--------------------------------------------------------------------------
    | أسماء عربية للحقول الشائعة (تُستبدل :attribute تلقائياً)
    |--------------------------------------------------------------------------
    */
    'attributes' => [
        'amount'            => 'المبلغ',
        'payment_method'    => 'طريقة الدفع',
        'payment_method_id' => 'طريقة الدفع',
        'reference_number'  => 'الرقم المرجعي',
        'session_token'     => 'رمز الجلسة',
        'name_ar'           => 'الاسم بالعربي',
        'name_en'           => 'الاسم بالإنجليزي',
        'code'              => 'الكود',
        'target_audience'   => 'الفئة المستهدفة',
        'processing_type'   => 'نوع المعالجة',
        'min_amount'        => 'الحد الأدنى للمبلغ',
        'max_amount'        => 'الحد الأقصى للمبلغ',
        'is_active'         => 'الحالة',
        'sort_order'        => 'ترتيب العرض',
        'email'             => 'البريد الإلكتروني',
        'password'          => 'كلمة المرور',
        'phone_number'      => 'رقم الهاتف',
        'full_name'         => 'الاسم الكامل',
        'trip_id'           => 'رقم الرحلة',
        'reason'            => 'السبب',
    ],

];
