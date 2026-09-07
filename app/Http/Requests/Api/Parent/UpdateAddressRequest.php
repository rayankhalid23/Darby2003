<?php

namespace App\Http\Requests\Api\Parent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateAddressRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $parentId = auth()->id();
        $addressId = $this->route('address') ? ($this->route('address') instanceof \App\Models\Parent\Address ? $this->route('address')->id : $this->route('address')) : $this->route('id');

        return [
            // مسمى العنوان (عربي فقط - حرفين على الأقل - يستثنى العنوان الحالي من التكرار)
            'label' => [
                'sometimes',
                'required',
                'string',
                'min:2',
                'max:100',
                // نفس قاعدة الإنشاء: الأرقام مسموحة في مسميات العناوين («شقة 5»).
                'regex:/^[\p{Arabic}\p{N}\s\-\/]+$/u',
                Rule::unique('addresses', 'label')
                    ->where(function ($query) use ($parentId) {
                        return $query->where('user_id', $parentId)->whereNull('deleted_at');
                    })->ignore($addressId)
            ],

            // إحداثيات خط العرض
            'lat' => [
                'sometimes',
                'required',
                'numeric',
                'between:-90,90',
                Rule::unique('addresses', 'lat')
                    ->where(function ($query) use ($parentId) {
                        return $query->where('user_id', $parentId)->where('lng', $this->lng ?? ($this->route('address')->lng ?? null));
                    })->ignore($addressId)
            ],

            // إحداثيات خط الطول
            'lng' => [
                'sometimes',
                'required',
                'numeric',
                'between:-180,180'
            ],

            // المنطقة الجغرافية (اختياري)
            'zone_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:zones,id'
            ],

            // تبديل العنوان الرئيسي (اختياري).
            // true  ⇒ يمر عبر حراسات الاشتراكات وقد يُلغي طلبات الاشتراك المعلّقة.
            // false ⇒ مرفوض على العنوان الرئيسي الحالي (يجب ترقية عنوان بديل بدلاً منه).
            'is_default' => [
                'sometimes',
                'nullable',
                'boolean'
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
            'label.unique'   => 'اسم العنوان مسجل لديك مسبقاً في عنوان آخر.',

            // خط العرض (Lat)
            'lat.required' => 'إحداثيات خط العرض مطلوبة.',
            'lat.numeric'  => 'إحداثيات خط العرض يجب أن تكون رقماً.',
            'lat.between'  => 'إحداثيات خط العرض غير صالحة جغرافياً.',
            'lat.unique'   => 'هذا الموقع الجغرافي مسجل لديك مسبقاً في عنوان آخر.',

            // خط الطول (Lng)
            'lng.required' => 'إحداثيات خط الطول مطلوبة.',
            'lng.numeric'  => 'إحداثيات خط الطول يجب أن تكون رقماً.',
            'lng.between'  => 'إحداثيات خط الطول غير صالحة جغرافياً.',

            // المنطقة الجغرافية (Zone)
            'zone_id.integer' => 'معرف المنطقة يجب أن يكون رقماً صحيحاً.',
            'zone_id.exists'  => 'المنطقة الجغرافية المختارة غير مسجلة بالنظام.',

            // العنوان الرئيسي
            'is_default.boolean' => 'قيمة تفعيل العنوان الرئيسي يجب أن تكون true أو false.',
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