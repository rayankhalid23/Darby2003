<?php

namespace App\Http\Requests\Api\Parent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class ParentRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // الاسم ثلاثي فأكثر بالعربية فقط (بدون أرقام أو رموز).
            // {2,} وليس {2}: الأسماء الليبية كثيراً ما تتجاوز ثلاث كلمات
            // مثل «صالح عبد الرحيم التاورغي»، وتقييدها بثلاث بالضبط كان يرفض مستخدمين حقيقيين.
            'full_name' => [
                'required',
                'string',
                'regex:/^[\p{Arabic}]+(\s+[\p{Arabic}]+){2,}$/u'
            ],

            // البريد الإلكتروني
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                'unique:users,email'
            ],

            // رقم الهاتف الأساسي (10 أرقام ويبدأ بـ 09)
            'phone_number' => [
                'required',
                'string',
                'regex:/^09[0-9]{8}$/',
                'unique:users,phone_number'
            ],

            // رقم الهاتف الاحتياطي (اختياري، 10 أرقام، ولا يطابق الأساسي)
            'alternative_phone' => [
                'nullable',
                'string',
                'regex:/^09[0-9]{8}$/',
                'different:phone_number'
            ],

            // كلمة المرور: 6 خانات على الأقل، حروف إنجليزية وأرقام فقط.
            // الرموز الخاصة ممنوعة لأنها تُدخل عبر لوحات مفاتيح عربية بأشكال متعددة
            // فتسبب فشل تسجيل الدخول لاحقاً على أجهزة مختلفة.
            'password' => [
                'required',
                'string',
                'min:6',
                'regex:/^(?=.*[a-zA-Z])(?=.*[0-9])[a-zA-Z0-9]+$/'
            ],

            // تأكيد كلمة المرور
            'password_confirmation' => [
                'required',
                'string',
                'same:password'
            ],

            // كود التحقق OTP
            'otp' => [
                'required',
                'numeric',
                'digits:6'
            ],

            // بيانات إضافية
            'device_name' => ['nullable', 'string'],
            'platform'    => ['nullable', 'string'],
            'fcm_token'   => ['nullable', 'string'],
            'avatar'      => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
        ];
    }

    public function messages(): array
    {
        return [
            // الاسم
            'full_name.required' => 'الاسم الكامل مطلوب.',
            'full_name.regex'    => 'الاسم يجب أن يكون ثلاثياً على الأقل وباللغة العربية فقط.',

            // البريد الإلكتروني
            'email.required' => 'البريد الإلكتروني مطلوب.',
            'email.email'    => 'صيغة البريد الإلكتروني غير صحيحة.',
            'email.unique'   => 'البريد الإلكتروني مستخدم بالفعل.',

            // الهاتف الأساسي
            'phone_number.required' => 'رقم الهاتف مطلوب.',
            'phone_number.regex'    => 'رقم الهاتف يجب أن يتكون من 10 أرقام ويبدأ بـ 09.',
            'phone_number.unique'   => 'رقم الهاتف مستخدم بالفعل.',

            // الهاتف الاحتياطي
            'alternative_phone.regex'     => 'الرقم الاحتياطي يجب أن يتكون من 10 أرقام ويبدأ بـ 09.',
            'alternative_phone.different' => 'الرقم الاحتياطي يجب ألا يكون مطابقاً للرقم الأساسي.',

            // كلمة المرور
            'password.required' => 'كلمة المرور مطلوبة.',
            'password.min'      => 'كلمة المرور يجب ألا تقل عن 6 خانات.',
            'password.regex'    => 'كلمة المرور يجب أن تحتوي على أرقام وحرف إنجليزي واحد على الأقل، ويُمنع استخدام الرموز الخاصة والمسافات.',

            // تأكيد كلمة المرور
            'password_confirmation.required' => 'تأكيد كلمة المرور مطلوب.',
            'password_confirmation.same'     => 'تأكيد كلمة المرور غير مطابق.',

            // كود التحقق
            'otp.required' => 'رمز التحقق مطلوب.',
            'otp.numeric'  => 'رمز التحقق يجب أن يكون أرقاماً فقط.',
            'otp.digits'   => 'رمز التحقق يجب أن يتكون من 6 أرقام.',

            // الصورة الشخصية
            'avatar.image' => 'الملف يجب أن يكون صورة.',
            'avatar.mimes' => 'صيغة الصورة غير مدعومة.',
            'avatar.max'   => 'حجم الصورة يتجاوز الحد المسموح (2 ميجابايت).',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status'     => false,
            'error_code' => 'VALIDATION_ERROR',
            'message'    => '',
            'errors'     => $validator->errors()
        ], 422));
    }
}