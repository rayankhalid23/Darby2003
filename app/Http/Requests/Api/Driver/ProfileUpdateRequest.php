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

    private const AVATAR_ALIASES = [
        'avatar_url',
        'photo',
        'driver_photo',
        'personal_photo',
        'image',
        'profile_image',
    ];

    public function validationData(): array
    {
        $data = parent::validationData();

        foreach (self::AVATAR_ALIASES as $alias) {
            if ($this->hasFile($alias) && empty($data['avatar'])) {
                $data['avatar'] = $this->file($alias);
                break;
            }
        }

        // تجاهل الحقول النصية الفارغة المرسلة ضمن multipart/form-data عند تحديث الصورة الشخصية فقط
        foreach (['full_name', 'email', 'phone_number', 'gender', 'password'] as $optionalField) {
            if (array_key_exists($optionalField, $data) && (is_null($data[$optionalField]) || trim((string) $data[$optionalField]) === '' || strtolower(trim((string) $data[$optionalField])) === 'null')) {
                unset($data[$optionalField]);
            }
        }

        if (!empty($data['gender']) && is_string($data['gender'])) {
            $data['gender'] = trim(mb_strtolower($data['gender']));
        }

        if (!empty($data['phone_number']) && is_string($data['phone_number'])) {
            $data['phone_number'] = $this->sanitizeDigits($data['phone_number']);
        }
        if (!empty($data['alternative_phone']) && is_string($data['alternative_phone'])) {
            $sanitizedAlt = $this->sanitizeDigits($data['alternative_phone']);
            $data['alternative_phone'] = $sanitizedAlt !== '' ? $sanitizedAlt : null;
        }

        return $data;
    }

    protected function prepareForValidation(): void
    {
        foreach (self::AVATAR_ALIASES as $alias) {
            if ($this->files->has($alias) && !$this->files->has('avatar')) {
                $this->files->set('avatar', $this->files->get($alias));
                break;
            }
        }

        foreach (['full_name', 'email', 'phone_number', 'gender', 'password'] as $optionalField) {
            if ($this->request->has($optionalField)) {
                $val = $this->request->get($optionalField);
                if ($val === null || trim((string) $val) === '' || strtolower(trim((string) $val)) === 'null') {
                    $this->request->remove($optionalField);
                }
            }
        }

        $merge = [];
        if ($this->filled('gender') && is_string($this->input('gender'))) {
            $merge['gender'] = trim(mb_strtolower($this->input('gender')));
        }
        if ($this->filled('phone_number')) {
            $merge['phone_number'] = $this->sanitizeDigits((string) $this->input('phone_number'));
        }
        if ($this->filled('alternative_phone')) {
            $sanitizedAlt = $this->sanitizeDigits((string) $this->input('alternative_phone'));
            $merge['alternative_phone'] = $sanitizedAlt !== '' ? $sanitizedAlt : null;
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
        $data = $this->validationData();

        $rules = [
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
        ];

        foreach (array_merge(['avatar'], self::AVATAR_ALIASES) as $field) {
            $isFile = $this->hasFile($field) || (($data[$field] ?? null) instanceof \Illuminate\Http\UploadedFile);
            $rules[$field] = $isFile
                ? ['sometimes', 'nullable', 'file', 'mimes:jpeg,png,jpg,webp,heic,heif', 'max:10240']
                : ['sometimes', 'nullable', 'string'];
        }

        return $rules;
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