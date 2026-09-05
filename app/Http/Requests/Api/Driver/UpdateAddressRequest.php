<?php

namespace App\Http\Requests\Api\Driver;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // التعديل جزئي وصارم بفضل الـ sometimes ويتم فحص التكرار المتقدم داخل الـ Service
        return [
            'label'   => 'sometimes|required|string|max:100',
            'lat'     => 'sometimes|required|numeric|between:-90,90',
            'lng'     => 'sometimes|required|numeric|between:-180,180',
            'zone_id' => 'sometimes|nullable|integer|exists:zones,id',
        ];
    }

    public function messages(): array
    {
        return [
            'label.required'  => 'مسمى العنوان لا يمكن أن يكون فارغاً.',
            'lat.required'    => 'إحداثيات خط العرض مطلوبة.',
            'lng.required'    => 'إحداثيات خط الطول مطلوبة.',
            'lat.between'     => 'إحداثيات خط العرض المرسلة غير صالحة جغرافياً.',
            'lng.between'     => 'إحداثيات خط الطول المرسلة غير صالحة جغرافياً.',
            'zone_id.integer' => 'معرف المنطقة يجب أن يكون رقماً صحيحاً.',
            'zone_id.exists'  => 'المنطقة الجغرافية المختارة غير مسجلة بالنظام.',
        ];
    }
}