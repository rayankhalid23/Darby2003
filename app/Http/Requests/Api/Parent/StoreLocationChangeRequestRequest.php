<?php

namespace App\Http\Requests\Api\Parent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * طلب تغيير موقع مُجمَّع (يدعم عدة أطفال وعدة رحلات).
 * يُستخدم لكل من preview و store.
 */
class StoreLocationChangeRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'point_type'  => ['required', 'string', 'in:pickup,dropoff'],
            'date'        => ['required', 'date', 'after_or_equal:today'],

            // العنوان الجديد — إما id لعنوان محفوظ أو (lat + lng).
            'address_id'  => ['nullable', 'integer', 'exists:addresses,id', 'required_without_all:lat,lng'],
            'lat'         => ['nullable', 'numeric', 'between:-90,90',  'required_without:address_id'],
            'lng'         => ['nullable', 'numeric', 'between:-180,180', 'required_without:address_id'],
            'label'       => ['nullable', 'string', 'max:255'],

            // تحديد الأطفال والاشتراكات — [{child_id, trip_ids: [active_subscription_id, ...]}]
            'selections'                => ['required', 'array', 'min:1'],
            'selections.*.child_id'     => ['required', 'integer', 'exists:children,id'],
            'selections.*.trip_ids'     => ['required', 'array', 'min:1'],
            'selections.*.trip_ids.*'   => ['integer', 'exists:active_subscriptions,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'point_type.required'         => 'يجب تحديد نوع النقطة (استلام أو تسليم).',
            'point_type.in'               => 'نوع النقطة يجب أن يكون pickup أو dropoff.',
            'date.required'               => 'يجب تحديد التاريخ.',
            'date.after_or_equal'         => 'لا يمكن تحديد تاريخ سابق.',
            'address_id.exists'           => 'العنوان المحدد غير موجود.',
            'address_id.required_without_all' => 'يجب اختيار عنوان محفوظ أو إدخال إحداثيات.',
            'lat.required_without'        => 'يجب إدخال خط العرض عند عدم اختيار عنوان محفوظ.',
            'lng.required_without'        => 'يجب إدخال خط الطول عند عدم اختيار عنوان محفوظ.',
            'selections.required'         => 'يجب تحديد طفل واحد ورحلة واحدة على الأقل.',
            'selections.*.child_id.required' => 'كل عنصر يجب أن يحتوي child_id.',
            'selections.*.trip_ids.required' => 'كل طفل يجب أن يكون له رحلة واحدة على الأقل.',
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
