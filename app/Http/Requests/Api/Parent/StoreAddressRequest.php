<?php

namespace App\Http\Requests\Api\Parent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $parentId = auth()->id();

        return [
            // مسمى العنوان (عربي فقط - حرفين على الأقل - إجباري - غير مكرر)
            'label' => [
                'required',
                'string',
                'min:2',
                'max:100',
                // نسمح بالأرقام والشرطة والشرطة المائلة إلى جانب الحروف العربية:
                // مسميات العناوين الواقعية تتضمن أرقاماً مثل «شقة 5» و«مبنى 12-ب»،
                // وكان تقييدها بالحروف العربية وحدها يرفض عناوين مشروعة تماماً.
                'regex:/^[\p{Arabic}\p{N}\s\-\/]+$/u',
                Rule::unique('addresses', 'label')->where(function ($query) use ($parentId) {
                    return $query->where('user_id', $parentId)->whereNull('deleted_at');
                })
            ],

            // إحداثيات خط العرض
            'lat' => [
                'required',
                'numeric',
                'between:-90,90',
                Rule::unique('addresses', 'lat')->where(function ($query) use ($parentId) {
                    return $query->where('user_id', $parentId)->where('lng', $this->lng);
                })
            ],

            // إحداثيات خط الطول
            'lng' => [
                'required',
                'numeric',
                'between:-180,180'
            ],

            // المنطقة الجغرافية التابع لها العنوان (اختياري)
            'zone_id' => [
                'nullable',
                'integer',
                'exists:zones,id'
            ],
        ];
    }

    public function messages(): array
    {
        return [
            // مسمى العنوان
            'label.required' => 'مسمى العنوان مطلوب.',
            'label.string'   => 'مسمى العنوان يجب أن يكون نصاً.',
            'label.min'      => 'مسمى العنوان يجب ألا يقل عن حرفين.',
            'label.max'      => 'مسمى العنوان يجب ألا يتجاوز 100 حرف.',
            'label.regex'    => 'مسمى العنوان يجب أن يكون بالعربية (ويمكن أن يتضمن أرقاماً).',
            'label.unique'   => 'اسم العنوان مسجل لديك مسبقاً.',

            // خط العرض (Lat)
            'lat.required' => 'إحداثيات خط العرض مطلوبة.',
            'lat.numeric'  => 'إحداثيات خط العرض يجب أن تكون رقماً.',
            'lat.between'  => 'إحداثيات خط العرض غير صالحة جغرافياً.',
            'lat.unique'   => 'هذا الموقع الجغرافي مسجل لديك مسبقاً.',

            // خط الطول (Lng)
            'lng.required' => 'إحداثيات خط الطول مطلوبة.',
            'lng.numeric'  => 'إحداثيات خط الطول يجب أن تكون رقماً.',
            'lng.between'  => 'إحداثيات خط الطول غير صالحة جغرافياً.',

            // المنطقة الجغرافية (Zone)
            'zone_id.integer' => 'معرف المنطقة يجب أن يكون رقماً صحيحاً.',
            'zone_id.exists'  => 'المنطقة الجغرافية المختارة غير مسجلة بالنظام.',
        ];
    }

    /**
     * توحيد تنسيق أخطاء الـ API
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status'     => false,
            'error_code' => 'VALIDATION_ERROR',
            'message'    => '',
            'errors'     => $validator->errors()
        ], 422));
    }
}