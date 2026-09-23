<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class ParentFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->role_id !== null;
    }

    public function rules(): array
    {
        return [
            'search'    => ['nullable', 'string', 'max:100'], // البحث بالاسم أو البريد أو الهاتف
            'is_active' => ['nullable', 'boolean'], // فلترة حسب حالة تفعيل الحساب
            'per_page'  => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'search.max'       => 'نص البحث طويل جداً، يرجى الاختصار.',
            'is_active.boolean'=> 'قيمة حالة التفعيل غير صحيحة.',
            'per_page.max'     => 'عدد النتائج لا يمكن أن يتجاوز 100.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status'  => false,
            'message' => 'عذراً، مدخلات الفلترة أو البحث تحتوي على أخطاء.',
            'errors'  => $validator->errors()
        ], 422));
    }
}
