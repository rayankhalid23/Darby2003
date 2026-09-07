<?php

namespace App\Http\Requests\Api\Parent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateParentProfileRequest extends FormRequest
{
    /**
     * التحقق من تسجيل الدخول والصلاحية الأمنية قبل التعديل
     */
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * قواعد التحقق الخاصة بتعديل بيانات ولي الأمر (تعديل جزئي 100%)
     */
    public function rules(): array
    {
        $userId = auth()->id();

        return [
            // الاسم الكامل: أزيل شرط الـ unique لأنه منطقياً قد تتشابه أسماء الأولياء
            'full_name' => [
                'sometimes', 
                'string', 
                'min:3',
            ],

            // البريد الإلكتروني: يستثني الحساب الحالي أثناء التحديث
            'email' => [
                'sometimes', 
                'email', 
                Rule::unique('users', 'email')->ignore($userId)
            ],

            // رقم الهاتف الأساسي: يستثني الرقم الحالي لمنع التعارض
            'phone_number' => [
                'sometimes', 
                'string', 
                'min:7', 
                Rule::unique('users', 'phone_number')->ignore($userId)
            ],
            // التحقق من الصورة أثناء التحديث (تعديل جزئي)
'avatar' => [
    'sometimes',
    'nullable',
    'image',
    'mimes:jpeg,png,jpg,gif,svg',
    'max:2048'
],

            // الرقم البديل: اختياري دائماً
            'alternative_phone' => ['nullable', 'string', 'min:7'],

            // ملاحظة أمنية: أُزيل حقل is_trusted من مدخلات ولي الأمر لأنه صفة ثقة
            // تُدار من الإدارة فقط، وكان السماح به يتيح لولي الأمر منح نفسه صفة "موثوق".

            // كلمة المرور: قابلة للتحديث الجزئي إذا أُرسلت فقط
            'password' => [
                'nullable', 
                'string', 
                'min:7', 
                'regex:/^(?=.*[0-9])(?=.*[a-zA-Z])(?!.*[!@#$%^&*]).+$/'
            ],
        ];
    }

    /**
     * توحيد رسائل الخطأ تماشياً مع تجربة المستخدم المعتمدة في النظام
     */
    public function messages(): array
    {
        return [
            'full_name.min'          => 'الاسم يجب أن يتكون من 3 أحرف على الأقل.',
            
            'email.email'            => 'صيغة البريد الإلكتروني غير صحيحة، يرجى كتابته بشكل سليم.',
            'email.unique'           => 'هذا البريد الإلكتروني مستخدم بالفعل لحساب آخر في النظام.',
            
            'phone_number.min'       => 'رقم الهاتف يجب ألا يقل عن 7 أرقام.',
            'phone_number.unique'    => 'رقم الهاتف هذا مسجل لدينا بالفعل لحساب آخر.',
            
            'alternative_phone.min'  => 'رقم الهاتف البديل يجب ألا يقل عن 7 أرقام.',
            
            'is_trusted.boolean'     => 'حقل حالة الموثوقية يجب أن يكون منطقياً (true/false) أو (1/0).',
            
            'password.min'           => 'كلمة المرور الجديدة يجب ألا تقل عن 7 خانات لحماية الحساب.',
            'password.regex'         => 'كلمة المرور الجديدة يجب أن تحتوي على أرقام وحروف، ويُمنع استخدام الرموز الخاصة.',
            'avatar.image' => 'الملف المرفق يجب أن يكون صورة صحيحة.',
'avatar.mimes' => 'صيغة الصورة يجب أن تكون: jpeg, png, jpg, gif, أو svg.',
'avatar.max'   => 'حجم الصورة الشخصية يجب ألا يتجاوز 2 ميجابايت.',
        ];
    }

    /**
     * رد موحد للأخطاء متوافق تماماً مع واجهات الـ API بمشروع دربي
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status'     => false,
            'error_code' => 'VALIDATION_ERROR',
            'message'    => 'خطأ في البيانات المرسلة، يرجى تصحيح الحقول وإعادة المحاولة.',
            'errors'     => $validator->errors()
        ], 422));
    }
}