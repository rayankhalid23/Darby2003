<?php

namespace App\Http\Requests\Api\Driver;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class RegisterAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name'    => 'required|string|min:10|max:100',
            'email'        => 'required|email|unique:users,email',
            'phone_number' => 'required|digits:10|unique:users,phone_number|regex:/^09[0-9]{8}$/',
            'gender'       => 'required|in:male,female', // الحقل الجديد
            'password'     => ['required', 'string', 'min:6', 'regex:/^(?=.*[a-zA-Z])(?=.*\d).+$/'],
            'avatar_url'   => 'nullable|image|mimes:jpeg,png,jpg|max:2048',

            'alternative_phone' => 'nullable|string|min:7',
            'device_name'       => 'nullable|string',
            'platform'          => 'nullable|string',
            'fcm_token'         => 'nullable|string',

            // موافقة إلزامية على الشروط والأحكام. يُعاد إرسالها وجوباً في خطوة
            // التحقق من الـ OTP أيضاً (registerAccountAfterOtp يتحقق منها هناك
            // دفاعياً لأنه يقرأ $request->all() من OtpRequest لا من هذا الطلب).
            'terms_accepted'    => 'required|accepted',
        ];
    }

    public function messages(): array
    {
        return [
            // الاسم الكامل
            'full_name.required'     => 'الاسم الكامل مطلوب.',
            'full_name.string'       => 'اسم السائق يجب أن يكون نصاً صالحاً.',
            'full_name.min'          => 'يرجى إدخال الاسم الثلاثي على الأقل (10 أحرف كحد أدنى).',
            'full_name.max'          => 'اسم السائق لا يمكن أن يتجاوز 100 حرف.',

            // البريد الإلكتروني
            'email.required'         => 'البريد الإلكتروني مطلوب.',
            'email.email'            => 'تنسيق البريد الإلكتروني غير صحيح، يرجى كتابته بشكل سليم.',
            'email.unique'           => 'البريد الإلكتروني مسجل بالفعل، يرجى استخدام بريد آخر.',

            // رقم الهاتف
            'phone_number.required'  => 'رقم الهاتف مطلوب.',
            'phone_number.digits'    => 'يجب أن يتكون رقم الهاتف من 10 أرقام.',
            'phone_number.unique'    => 'رقم الهاتف هذا مستخدم من قبل سائق آخر بالفعل.',
            'phone_number.regex'     => 'يجب أن يبدأ رقم الهاتف بـ 09 ويتكون من 10 أرقام (مثال: 0910000000).',

            // الجنس
            'gender.required'        => 'يرجى تحديد الجنس.',
            'gender.in'              => 'القيمة المختارة للجنس غير صحيحة، يجب أن تكون male أو female.',

            // كلمة المرور
            'password.required'      => 'كلمة المرور مطلوبة.',
            'password.string'        => 'كلمة المرور يجب أن تكون نصاً.',
            'password.min'           => 'يجب ألا تقل كلمة المرور عن 6 أحرف.',
            'password.regex'         => 'كلمة المرور يجب أن تحتوي على حرف إنجليزي ورقم على الأقل.',

            // الصورة الشخصية
            'avatar_url.image'       => 'الملف المرفوع يجب أن يكون صورة.',
            'avatar_url.mimes'       => 'يسمح فقط بالصور بصيغ jpeg, png, jpg.',
            'avatar_url.max'         => 'حجم الصورة يجب ألا يتجاوز 2 ميجابايت.',

            // الهاتف الاحتياطي وبيانات الجهاز
            'alternative_phone.string' => 'رقم الهاتف الاحتياطي يجب أن يكون نصاً صالحاً.',
            'alternative_phone.min'    => 'رقم الهاتف الاحتياطي يجب ألا يقل عن 7 أرقام.',
            'device_name.string'       => 'اسم الجهاز يجب أن يكون نصاً صالحاً.',
            'platform.string'          => 'نوع المنصة يجب أن يكون نصاً صالحاً.',
            'fcm_token.string'         => 'رمز الإشعارات (FCM Token) يجب أن يكون نصاً صالحاً.',

            // الموافقة على الشروط
            'terms_accepted.required'  => 'يجب الموافقة على الشروط والأحكام لإتمام التسجيل.',
            'terms_accepted.accepted'  => 'يجب الموافقة على الشروط والأحكام لإتمام التسجيل.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status'  => false,
            'message' => $validator->errors()->first() ?: 'خطأ في البيانات المدخلة، يرجى مراجعة الحقول.',
            'errors'  => $validator->errors()
        ], 422));
    }
}