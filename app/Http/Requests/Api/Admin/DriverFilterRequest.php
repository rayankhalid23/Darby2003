<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class DriverFilterRequest extends FormRequest
{
    /**
     * التحقق من الصلاحية الأمنية للأدمن
     */
    public function authorize(): bool
    {
        // نتحقق من أن المستخدم مسجل دخول وله رتبة أدمن (يمكنك تعديل الشرط حسب نظام الـ Roles لديك)
        return auth()->check() && auth()->user()->role_id !== null; 
    }

    /**
     * تجهيز المدخلات قبل التحقق لمعالجة حساسية حالة الأحرف
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('status') && is_string($this->status)) {
            $statusMap = [
                'pending'   => 'Pending',
                'approved'  => 'Approved',
                'suspended' => 'Suspended',
                'rejected'  => 'Rejected',
                'offline'   => 'Offline',
                'on_trip'   => 'ON_TRIP',
                'ontrip'    => 'ON_TRIP',
            ];
            $normalized = strtolower(trim($this->status));
            if (isset($statusMap[$normalized])) {
                $this->merge(['status' => $statusMap[$normalized]]);
            }
        }
    }

    /**
     * قواعد التحقق لفلترة السائقين
     */
    public function rules(): array
    {
        return [
            // الفلترة حسب حالات السائقين في قاعدة البيانات مع دعم الأحرف الصغيرة والكبيرة
            'status'   => ['nullable', 'string', 'in:Pending,Approved,Suspended,Rejected,Offline,ON_TRIP,pending,approved,suspended,rejected,offline,on_trip,ontrip'],
            'search'   => ['nullable', 'string', 'max:100'], // البحث باسم السائق أو بريده أو هاتفه
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'], // عدد النتائج لكل صفحة
        ];
    }

    /**
     * رسائل الخطأ المخصصة باللغة العربية
     */
    public function messages(): array
    {
        return [
            'status.in'       => 'الحالة المحددة للفلترة غير صحيحة، القيم المسموحة: Pending, Approved, Suspended, Rejected, Offline, ON_TRIP.',
            'search.max'      => 'نص البحث طويل جداً، يرجى الاختصار.',
            'per_page.integer' => 'عدد النتائج يجب أن يكون رقماً صحيحاً.',
            'per_page.min'    => 'عدد النتائج يجب أن يكون 1 على الأقل.',
            'per_page.max'    => 'عدد النتائج لا يمكن أن يتجاوز 100.',
        ];
    }

    /**
     * رد موحد في حال فشل التحقق للـ APIs المتوافقة مع لوحة التحكم
     */
    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status'  => false,
            'message' => 'عذراً، مدخلات الفلترة أو البحث تحتوي على أخطاء.',
            'errors'  => $validator->errors()
        ], 422));
    }
}