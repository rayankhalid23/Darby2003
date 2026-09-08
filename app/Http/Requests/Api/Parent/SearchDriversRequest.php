<?php

namespace App\Http\Requests\Api\Parent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchDriversRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search_query'  => ['nullable', 'string', 'max:255'],
            'query'         => ['nullable', 'string', 'max:255'],
            'keyword'       => ['nullable', 'string', 'max:255'],
            'name'          => ['nullable', 'string', 'max:255'],
            'phone'         => ['nullable', 'string', 'max:255'],
            'driver_gender' => ['nullable', 'string', Rule::in(['male', 'female', 'both'])],
            'has_ac'        => ['nullable', 'boolean'],
            'child_ids'     => ['nullable', 'array'],
            'child_ids.*'   => ['required', 'integer', 'exists:children,id'],

            // ─── فترة الاشتراك المطلوبة ──────────────────────────────────────
            // بدونها كان فلتر المقاعد يفحص اليوم الحالي فقط، فيظهر سائق ممتلئ
            // طوال الفصل الدراسي لمجرد أن مقاعد اليوم شاغرة.
            'start_date'         => ['nullable', 'date', 'after_or_equal:today'],
            'end_date'           => ['nullable', 'date', 'after_or_equal:start_date'],
            'trip_direction'     => ['nullable', 'string', Rule::in(['go', 'return', 'both'])],
            // نوع الاشتراك — يحدد طريقة حساب أيام العمل بالتسعير التقديري (يوم واحد أو كل أيام الفترة).
            'subscription_type'  => ['nullable', 'string', Rule::in(['single_day', 'multi_day'])],
        ];
    }

    /**
     * تجهيز البيانات قبل عملية التحقق (تحويل القيم النصية وتوحيد حقل البحث)
     */
    protected function prepareForValidation(): void
    {
        if (!$this->filled('search_query')) {
            $searchVal = $this->input('query') 
                ?? $this->input('keyword') 
                ?? $this->input('name') 
                ?? $this->input('phone') 
                ?? $this->input('search');

            if ($searchVal !== null && trim((string) $searchVal) !== '') {
                $this->merge(['search_query' => trim((string) $searchVal)]);
            }
        }

        if ($this->has('has_ac') && $this->input('has_ac') !== null) {
            $this->merge([
                'has_ac' => filter_var($this->has_ac, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE),
            ]);
        }

        // توحيد اتجاه الرحلة بنفس مفردات StoreSubscriptionRequest حتى لا تختلف
        // مفردات البحث عن مفردات الحجز فينتهي الفلتر بفحص فترة غير التي ستُحجز.
        if ($this->has('trip_direction') || $this->has('direction')) {
            $dir = strtolower((string) ($this->trip_direction ?? $this->direction ?? ''));
            $this->merge(['trip_direction' => match ($dir) {
                'go', 'morning', 'one_way_morning'     => 'go',
                'return', 'evening', 'one_way_evening' => 'return',
                'both', 'two_way'                      => 'both',
                default                                => $dir,
            }]);
        }

        // نهاية غير مُرسلة تعني اشتراك ليوم واحد
        if ($this->filled('start_date') && !$this->filled('end_date')) {
            $this->merge(['end_date' => $this->input('start_date')]);
        }
    }

    public function messages(): array
    {
        return [
            'child_ids.array'    => 'قائمة الأطفال يجب أن تكون مصفوفة صحيحة.',
            'child_ids.*.exists' => 'أحد الأطفال المحددين غير موجود في النظام.',
            'driver_gender.in'   => 'جنس السائق المحدد غير صحيح (مسموح: male, female, both).',
            'has_ac.boolean'     => 'قيمة التكييف يجب أن تكون true أو false.',

            'start_date.date'             => 'صيغة تاريخ بدء الاشتراك غير صحيحة.',
            'start_date.after_or_equal'   => 'تاريخ بدء الاشتراك لا يمكن أن يكون في الماضي.',
            'end_date.date'               => 'صيغة تاريخ نهاية الاشتراك غير صحيحة.',
            'end_date.after_or_equal'     => 'تاريخ النهاية يجب أن يكون مساوياً أو بعد تاريخ البدء.',
            'trip_direction.in'           => 'اتجاه الرحلة غير صالح (go للذهاب، return للإياب، both للاتجاهين).',
            'subscription_type.in'        => 'نوع الاشتراك غير صالح (single_day ليوم واحد، multi_day لعدة أيام).',
        ];
    }
}