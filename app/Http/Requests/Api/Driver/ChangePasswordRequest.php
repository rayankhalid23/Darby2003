<?php

namespace App\Http\Requests\Api\Driver;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check();
    }

    /**
     * نفس شروط كلمة المرور المعتمدة عند إنشاء حساب السائق (RegisterAccountRequest)
     * حرف إنجليزي ورقم على الأقل، 6 خانات على الأقل.
     */
    public function rules(): array
    {
        return [
            'old_password' => [
                'required',
                'string',
            ],

            'password' => [
                'required',
                'string',
                'min:6',
                'regex:/^(?=.*[a-zA-Z])(?=.*\d).+$/',
            ],

            'password_confirmation' => [
                'required',
                'string',
                'same:password',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'old_password.required' => 'كلمة المرور الحالية مطلوبة.',

            'password.required' => 'كلمة المرور الجديدة مطلوبة.',
            'password.min'      => 'يجب ألا تقل كلمة المرور الجديدة عن 6 أحرف.',
            'password.regex'    => 'كلمة المرور الجديدة يجب أن تحتوي على حرف إنجليزي ورقم على الأقل.',

            'password_confirmation.required' => 'تأكيد كلمة المرور الجديدة مطلوب.',
            'password_confirmation.same'     => 'تأكيد كلمة المرور غير مطابق.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status'  => false,
            'message' => $validator->errors()->first() ?: 'خطأ في البيانات المدخلة، يرجى مراجعة الحقول.',
            'errors'  => $validator->errors(),
        ], 422));
    }
}
