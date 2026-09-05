<?php

namespace App\Http\Requests\Api\Driver;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // جلب معرف السائق ديناميكياً من التوكن، وإذا لم يوجد (في مرحلة التطوير) نضع 1
        $driverId = auth()->id() ?? 1; 

        return [
            'label' => [
                'required',
                'string',
                'max:100',
                // يمنع تكرار اسم العنوان (مثال: الموقف الرئيسي) لنفس السائق عند الإضافة
                Rule::unique('addresses', 'label')->where(function ($query) use ($driverId) {
                    return $query->where('driver_id', $driverId)->whereNull('deleted_at');
                })
            ],
            'lat' => [
                'required',
                'numeric',
                'between:-90,90',
                // يمنع تكرار نفس الإحداثيات الجغرافية لنفس السائق
                Rule::unique('addresses', 'lat')->where(function ($query) use ($driverId) {
                    return $query->where('driver_id', $driverId)
                        ->where('lng', $this->lng)
                        ->whereNull('deleted_at');
                })
            ],
            'lng'     => 'required|numeric|between:-180,180',
            'zone_id' => 'nullable|integer|exists:zones,id',
        ];
    }

    public function messages(): array
    {
        return [
            'label.required'  => 'يرجى تحديد مسمى لعنوان السائق (مثل: الموقف الرئيسي، محطة الانتظار).',
            'label.unique'    => 'لديك عنوان مسجل مسبقاً بنفس هذا الاسم، يرجى اختيار اسم آخر.',
            'lat.required'    => 'إحداثيات خط العرض مطلوبة لتعيين الموقع على الخريطة.',
            'lat.unique'      => 'هذا الموقع الجغرافي (الإحداثيات) مضاف لديك بالفعل في قائمة عناوينك.',
            'lng.required'    => 'إحداثيات خط الطول مطلوبة لتعيين الموقع على الخريطة.',
            'lat.between'     => 'إحداثيات خط العرض المرسلة غير صالحة جغرافياً.',
            'lng.between'     => 'إحداثيات خط الطول المرسلة غير صالحة جغرافياً.',
            'zone_id.integer' => 'معرف المنطقة يجب أن يكون رقماً صحيحاً.',
            'zone_id.exists'  => 'المنطقة الجغرافية المختارة غير مسجلة بالنظام.',
        ];
    }
}