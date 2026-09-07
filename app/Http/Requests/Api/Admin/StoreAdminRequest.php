<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; 
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_active'          => $this->input('is_active', 1),
            'role_id'            => $this->input('role_id', 2),
            'custom_permissions' => $this->input('custom_permissions', null),
            'created_by'         => $this->user()?->id ?? 1,
        ]);
    }

    public function rules(): array
    {
        return [
            // يدعم العربية والانجليزية ويشترط 3 مقاطع على الأقل لتوثيق الهوية
            'full_name' => [
                'required',
                'string',
                'regex:/^[\x{0600}-\x{06FF}\s]+$/u', // يمنع الإنجليزية، الأرقام، والرموز ويقبل أحرف عربية ومسافات فقط
                'unique:users,full_name',
                function ($attribute, $value, $fail) {
                    $words = explode(' ', trim(preg_replace('/\s+/', ' ', $value)));
                    if (count($words) < 3) {
                        $fail('الاسم الثلاثي مطلوب: يجب ألا يقل الاسم عن ثلاث كلمات.');
                    }
                }
            ],
            'email' => [
                'required',
                'email:filter', // فحص دقيق لصيغة البريد
                'unique:users,email'
            ],
            'phone_number' => [
                'required',
                'numeric',
                'digits:10',
                'regex:/^09/',
                'unique:users,phone_number'
            ],
            'password' => [
                'nullable',
                'string',
                'min:6',
                'regex:/[a-zA-Z]/' // يشترط وجود حرف إنجليزي واحد على الأقل
            ],
            'avatar' => [
                'sometimes',
                'nullable',
                'image',
                'mimes:jpeg,png,jpg',
                'max:2048'
            ],
            'is_active'          => 'required|boolean',
            // role_id يجب أن يكون موجوداً فعلاً في جدول الأدوار لمنع تسريب خطأ SQL الخام
            // ولمنع إسناد دور غير موجود عبر قيد قاعدة البيانات فقط.
            'role_id'            => 'required|integer|exists:roles,id',
            'custom_permissions' => 'nullable|array',
            // created_by يُفرض من المستخدم المصادَق في prepareForValidation ولا يُعتمد على قيمة العميل.
            'created_by'         => 'required|integer|exists:users,id',
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'الاسم الكامل مطلوب.',
'full_name.regex'    => 'الاسم يجب أن يكون باللغة العربية فقط وبدون رموز أو أرقام.',
'full_name.unique'   => 'الاسم مُسجّل مسبقاً.',

'email.required'     => 'البريد الإلكتروني مطلوب.',
'email.email'        => 'صيغة البريد الإلكتروني غير صحيحة.',
'email.unique'       => 'البريد الإلكتروني مُسجّل مسبقاً.',

'phone_number.required' => 'رقم الهاتف مطلوب.',
'phone_number.numeric'  => 'رقم الهاتف يجب أن يتكون من أرقام فقط.',
'phone_number.digits'   => 'رقم الهاتف يجب أن يكون 10 أرقام.',
'phone_number.regex'    => 'رقم الهاتف يجب أن يبدأ بـ 09.',
'phone_number.unique'   => 'رقم الهاتف مُسجّل مسبقاً.',
'password.min'   => 'كلمة المرور يجب ألا تقل عن 6 خانات.',
'password.regex' => 'كلمة المرور يجب أن تحتوي على حرف واحد على الأقل.',

'avatar.image'          => 'الملف يجب أن يكون صورة.',
'avatar.mimes'          => 'الصيغ المسموحة: jpeg, png, jpg.',
'avatar.max'            => 'حجم الصورة يجب ألا يتجاوز 2 ميجابايت.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status'  => false,
            'message' => 'عذراً، مدخلات إنشاء الحساب تحتوي على أخطاء.',
            'errors'  => $validator->errors()
        ], 422));
    }
}