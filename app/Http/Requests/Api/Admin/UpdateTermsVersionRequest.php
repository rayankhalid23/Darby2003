<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTermsVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'version_number' => ['sometimes', 'string', 'max:20'],
            'title'          => ['sometimes', 'string', 'max:255'],
            'audience'       => ['sometimes', Rule::in(['parent', 'driver', 'both'])],
        ];
    }

    public function messages(): array
    {
        return [
            'audience.in' => 'الجمهور يجب أن يكون parent أو driver أو both.',
        ];
    }
}
