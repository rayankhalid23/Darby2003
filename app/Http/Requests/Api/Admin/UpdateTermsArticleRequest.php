<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTermsArticleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'article_number' => ['sometimes', 'integer', 'min:1'],
            'title'          => ['sometimes', 'string', 'max:255'],
            'body'           => ['sometimes', 'string'],
            'sort_order'     => ['nullable', 'integer', 'min:0'],
        ];
    }
}
