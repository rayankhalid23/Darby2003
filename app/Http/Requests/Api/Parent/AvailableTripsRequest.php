<?php

namespace App\Http\Requests\Api\Parent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class AvailableTripsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'child_ids'   => ['required', 'array', 'min:1'],
            'child_ids.*' => ['integer', 'exists:children,id'],
            'date'        => ['required', 'date', 'after_or_equal:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'child_ids.required' => 'يجب تحديد طفل واحد على الأقل.',
            'child_ids.array'    => 'يجب إرسال معرفات الأطفال كمصفوفة.',
            'child_ids.*.exists' => 'أحد الأطفال المحددين غير موجود.',
            'date.required'      => 'يجب تحديد التاريخ.',
            'date.after_or_equal'=> 'لا يمكن تحديد تاريخ سابق.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status'     => false,
            'error_code' => 'VALIDATION_ERROR',
            'message'    => '',
            'errors'     => $validator->errors(),
        ], 422));
    }
}
