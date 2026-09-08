<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTermsVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version_number' => ['required', 'string', 'max:20'],
            'title'          => ['required', 'string', 'max:255'],
            'audience'       => ['required', Rule::in(['parent', 'driver', 'both'])],
        ];
    }

    public function messages(): array
    {
        return [
            'version_number.required' => 'رقم النسخة مطلوب (مثال: 1.1).',
            'title.required'          => 'عنوان النسخة مطلوب.',
            'audience.required'       => 'يجب تحديد الجمهور المستهدف.',
            'audience.in'             => 'الجمهور يجب أن يكون parent أو driver أو both.',
        ];
    }
}
