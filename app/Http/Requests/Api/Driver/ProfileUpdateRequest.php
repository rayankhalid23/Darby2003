<?php

namespace App\Http\Requests\Api\Driver;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        $user = auth()->user();

        return $user->driver !== null 
            || in_array((int) ($user->role_id ?? 0), [8, 4], true)
            || $user->role?->name === 'driver';
    }

    public function validationData(): array
    {
        $data = parent::validationData();

        if ($this->hasFile('avatar_url') && empty($data['avatar'])) {
            $data['avatar'] = $this->file('avatar_url');
        }
        if ($this->hasFile('photo') && empty($data['avatar'])) {
            $data['avatar'] = $this->file('photo');
        }

        if (!empty($data['phone_number']) && is_string($data['phone_number'])) {
            $data['phone_number'] = $this->sanitizeDigits($data['phone_number']);
        }
        if (!empty($data['alternative_phone']) && is_string($data['alternative_phone'])) {
            $data['alternative_phone'] = $this->sanitizeDigits($data['alternative_phone']);
        }

        return $data;
    }

    protected function prepareForValidation(): void
    {
        if ($this->hasFile('avatar_url') && !$this->hasFile('avatar')) {
            $this->files->set('avatar', $this->file('avatar_url'));
        }
        if ($this->hasFile('photo') && !$this->hasFile('avatar')) {
            $this->files->set('avatar', $this->file('photo'));
        }

        $merge = [];
        if ($this->filled('phone_number')) {
            $merge['phone_number'] = $this->sanitizeDigits((string) $this->input('phone_number'));
        }
        if ($this->filled('alternative_phone')) {
            $merge['alternative_phone'] = $this->sanitizeDigits((string) $this->input('alternative_phone'));
        }

        if (!empty($merge)) {
            $this->merge($merge);
        }
    }

    private function sanitizeDigits(string $value): string
    {
        $arabicDigits = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $englishDigits = ['0','1','2','3','4','5','6','7','8','9'];
        $value = str_replace($arabicDigits, $englishDigits, trim($value));

        return preg_replace('/[^0-9]/', '', $value) ?? $value;
    }

    public function rules(): array
    {
        $userId = auth()->id();

        return [
            'full_name' => [
                'sometimes', 'string', 'min:10', 'max:100', 'regex:/^[\p{L} ]+/u',
                function ($attribute, $value, $fail) {
                    $words = explode(' ', trim(preg_replace('/\s+/', ' ', $value)));
                    if (count($words) < 3) {
                        $fail('يجب إدخال الاسم الثلاثي بالكامل.');
                    }
                },
                Rule::unique('users', 'full_name')->ignore($userId),
            ],
            'email' => [
                'sometimes', 'email',
                Rule::unique('users', 'email')->ignore($userId)
            ],
            'phone_number' => [
                'sometimes', 'numeric', 'digits:10', 'regex:/^09/',
                Rule::unique('users', 'phone_number')->ignore($userId)
            ],
            'alternative_phone' => [
                'nullable', 'numeric', 'digits:10', 'regex:/^09/',
                Rule::unique('users', 'alternative_phone')->ignore($userId)
            ],
            'password' => [
                'nullable', 'string', 'min:6', 'regex:/^(?=.*[a-zA-Z])(?=.*\d).+$/'
            ],
            'gender' => ['sometimes', 'in:male,female'],
            'avatar' => ['sometimes', 'nullable', 'file', 'mimes:jpeg,png,jpg,webp,heic,heif', 'max:10240']
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.min'            => 'يرجى إدخال الاسم الثلاثي على الأقل.',
            'full_name.unique'         => 'هذا الاسم مسجل في النظام مسبقاً لحساب آخر.',
            'email.email'              => 'تنسيق البريد الإلكتروني غير صحيح.',
            'email.unique'             => 'هذا البريد الإلكتروني مستخدم بالفعل مسبقاً.',
            'phone_number.digits'      => 'يجب أن يتكون رقم الهاتف من 10 أرقام بالضبط.',
            'phone_number.regex'       => 'رقم الهاتف غير صحيح، يجب أن يبدأ بـ 09.',
            'phone_number.unique'      => 'رقم الهاتف هذا مستخدم من قبل حساب آخر.',
            'alternative_phone.digits' => 'يجب أن يتكون رقم الهاتف البديل من 10 أرقام بالضبط.',
            'alternative_phone.regex'  => 'رقم الهاتف البديل غير صحيح، يجب أن يبدأ بـ 09.',
            'alternative_phone.unique' => 'رقم الهاتف البديل هذا مستخدم من قبل حساب آخر.',
            'password.min'             => 'يجب ألا تقل كلمة المرور المحدثة عن 6 خانات.',
            'password.regex'           => 'كلمة المرور يجب أن تحتوي على حرف ورقم واحد على الأقل للأمان.',
            'gender.in'                => 'القيمة المختارة للجنس غير صحيحة.',
            'avatar.image'             => 'الملف المرفوع يجب أن يكون صورة صالحة.',
            'avatar.mimes'             => 'يسمح فقط بالصور بصيغ jpeg, png, jpg.',
            'avatar.max'               => 'حجم الصورة الشخصية يجب ألا يتجاوز 2 ميجابايت.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status'  => false,
            'message' => 'عذراً، البيانات المرسلة لتعديل الحساب تحتوي على أخطاء.',
            'errors'  => $validator->errors()
        ], 422));
    }
}