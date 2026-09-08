<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreTermsArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'article_number' => ['required', 'integer', 'min:1'],
            'title'          => ['required', 'string', 'max:255'],
            'body'           => ['required', 'string'],
            'sort_order'     => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'article_number.required' => 'رقم المادة مطلوب.',
            'title.required'          => 'عنوان المادة مطلوب.',
            'body.required'           => 'نص المادة مطلوب.',
        ];
    }
}
