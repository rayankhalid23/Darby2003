<?php

namespace App\Http\Requests\Api\Parent;

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
     * نفس شروط كلمة المرور المعتمدة عند إنشاء حساب ولي الأمر (ParentRegisterRequest)
     * حروف إنجليزية وأرقام فقط، بدون رموز خاصة، 6 خانات على الأقل.
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
                'regex:/^(?=.*[a-zA-Z])(?=.*[0-9])[a-zA-Z0-9]+$/',
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
            'password.min'      => 'كلمة المرور الجديدة يجب ألا تقل عن 6 خانات.',
            'password.regex'    => 'كلمة المرور الجديدة يجب أن تحتوي على أرقام وحرف إنجليزي واحد على الأقل، ويُمنع استخدام الرموز الخاصة والمسافات.',

            'password_confirmation.required' => 'تأكيد كلمة المرور الجديدة مطلوب.',
            'password_confirmation.same'     => 'تأكيد كلمة المرور غير مطابق.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status'     => false,
            'error_code' => 'VALIDATION_ERROR',
            'message'    => $validator->errors()->first() ?: 'خطأ في البيانات المرسلة، يرجى تصحيح الحقول وإعادة المحاولة.',
            'errors'     => $validator->errors(),
        ], 422));
    }
}
