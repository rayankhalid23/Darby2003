<?php

namespace App\Http\Requests\Api\Shared;

use Illuminate\Foundation\Http\FormRequest;
use Carbon\Carbon;

class StoreSubscriptionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare data for validation (Normalization & Fallback Mapping).
     */
    protected function prepareForValidation(): void
    {
        // تطبيع اتجاه الرحلة على مستوى الطلب (حقل مشترك)
        if ($this->has('trip_direction') || $this->has('direction')) {
            $dir = strtolower($this->trip_direction ?? $this->direction ?? '');
            $normalized = match ($dir) {
                'go', 'morning', 'one_way_morning'     => 'go',
                'return', 'evening', 'one_way_evening' => 'return',
                'both', 'two_way'                      => 'both',
                default                                => $dir,
            };
            $this->merge(['trip_direction' => $normalized]);
        }

        // تطبيع الفترة (صباحي/مسائي/كلاهما). غيابها ليس خطأً: تُشتق لكل طفل من
        // children.preferred_time_slot في الخدمة، فلا نضع هنا قيمة افتراضية.
        if ($this->filled('timing')) {
            $timing = strtoupper(trim((string) $this->timing));
            $this->merge(['timing' => match ($timing) {
                'MORNING'              => 'MORNING',
                'EVENING', 'AFTERNOON' => 'EVENING',
                'BOTH'                 => 'BOTH',
                default                => $timing,
            }]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $parentId = $this->user()?->id;

        return [
            // ─── السائق ─────────────────────────────────────────────────────────
            'driver_id' => [
                'required',
                'integer',
                'exists:drivers,id',
            ],

            // ─── الحقول المشتركة على مستوى الطلب بأكمله ───────────────────────

            // نوع الاشتراك
            'subscription_type' => [
                'required',
                'string',
                'in:single_day,multi_day',
            ],

            // اتجاه الرحلة
            'trip_direction' => [
                'required',
                'string',
                'in:go,return,both',
            ],

            // الفترة (صباحي/مسائي/كلاهما).
            // اختيارية للتوافق مع العملاء الحاليين؛ عند غيابها تُشتق لكل طفل من
            // تفضيله المسجَّل. ⚠️ كانت مثبَّتة على BOTH لكل طلب، فكان الطفل الصباحي
            // يحجز مقعداً في الفترة المسائية أيضاً ويستهلك ضعف طاقة السائق.
            'timing' => [
                'nullable',
                'string',
                'in:MORNING,EVENING,BOTH',
            ],

            // تاريخ البداية
            'start_date' => [
                'required',
                'date',
                function ($attribute, $value, $fail) {
                    $startDate = Carbon::parse($value)->startOfDay();
                    $today     = Carbon::today();

                    if ($startDate->lt($today)) {
                        $fail('تاريخ بدء الاشتراك لا يمكن أن يكون في الماضي.');
                    }
                },
            ],

            // تاريخ النهاية
            'end_date' => [
                'required',
                'date',
                'after_or_equal:start_date',
            ],

            // عنوان المنزل المشترك — إما مرجع لعنوان محفوظ (الأفضل) أو إحداثيات خام
            'home_address_id' => [
                'required_without:home_address',
                'nullable',
                'integer',
                function ($attribute, $value, $fail) use ($parentId) {
                    if (!$value) {
                        return;
                    }
                    $exists = \App\Models\Parent\Address::where('id', $value)
                        ->where('user_id', $parentId)
                        ->exists();
                    if (!$exists) {
                        $fail('العنوان المحدد غير موجود أو لا ينتمي لحسابك.');
                    }
                },
            ],
            'home_address'       => ['required_without:home_address_id', 'nullable', 'array'],
            'home_address.lat'   => ['required_with:home_address', 'numeric', 'between:-90,90'],
            'home_address.lng'   => ['required_with:home_address', 'numeric', 'between:-180,180'],
            'home_address.label' => ['nullable', 'string', 'max:255'],

            // ─── الأطفال ─────────────────────────────────────────────────────────
            'children' => [
                'required',
                'array',
                'min:1',
            ],

            // كل طفل يحتاج child_id فقط — باقي التفاصيل تُؤخذ من بياناته في DB
            'children.*.child_id' => [
                'required',
                'integer',
                'exists:children,id',
                // التحقق من أن الطفل ينتمي لولي الأمر المُسجَّل دخوله
                function ($attribute, $value, $fail) use ($parentId) {
                    if ($parentId && !empty($value)) {
                        $exists = \App\Models\Parent\Child::where('id', $value)
                            ->where('parent_id', $parentId)
                            ->exists();
                        if (!$exists) {
                            $fail('أحد الأطفال المحددين لا ينتمي لحسابك.');
                        }
                    }
                },
            ],

            // ─── الحقول العامة الاختيارية ────────────────────────────────────────
            'notes' => [
                'nullable',
                'string',
                'max:1000',
            ],

            // السعر الإجمالي اختياري (يُحسب في Service)
            'total_price' => [
                'nullable',
                'numeric',
                'min:0',
            ],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array
     */
    public function messages(): array
    {
        return [
            // السائق
            'driver_id.required' => 'يرجى تحديد السائق المطلوب.',
            'driver_id.integer'  => 'معرف السائق يجب أن يكون رقماً صحيحاً.',
            'driver_id.exists'   => 'السائق المحدد غير موجود في المنظومة.',

            // نوع الاشتراك
            'subscription_type.required' => 'نوع الاشتراك مطلوب.',
            'subscription_type.in'       => 'نوع الاشتراك غير صالح، القيم المقبولة: single_day أو multi_day.',

            // الاتجاه
            'trip_direction.required' => 'اتجاه الرحلة مطلوب.',
            'trip_direction.in'       => 'اتجاه الرحلة غير صالح (go للذهاب، return للإياب، both للاتجاهين).',
            'timing.in'               => 'الفترة غير صالحة (MORNING صباحي، EVENING مسائي، BOTH كلاهما).',

            // تواريخ
            'start_date.required'       => 'تاريخ بدء الاشتراك مطلوب.',
            'start_date.date'           => 'صيغة تاريخ البدء غير صحيحة.',
            'end_date.required'         => 'تاريخ نهاية الاشتراك مطلوب.',
            'end_date.date'             => 'صيغة تاريخ النهاية غير صحيحة.',
            'end_date.after_or_equal'   => 'تاريخ النهاية يجب أن يكون مساوياً أو بعد تاريخ البدء.',

            // عنوان المنزل
            'home_address_id.required_without' => 'يرجى تحديد عنوان محفوظ أو إرسال إحداثيات المنزل.',
            'home_address.required_without'    => 'عنوان المنزل مطلوب لإتمام الاشتراك.',
            'home_address.array'            => 'صيغة بيانات عنوان المنزل غير صحيحة.',
            'home_address.lat.required_with' => 'خط العرض (lat) لعنوان المنزل مطلوب.',
            'home_address.lat.numeric'      => 'خط العرض يجب أن يكون رقماً.',
            'home_address.lat.between'      => 'خط العرض يجب أن يكون بين -90 و 90.',
            'home_address.lng.required_with' => 'خط الطول (lng) لعنوان المنزل مطلوب.',
            'home_address.lng.numeric'      => 'خط الطول يجب أن يكون رقماً.',
            'home_address.lng.between'      => 'خط الطول يجب أن يكون بين -180 و 180.',

            // الأطفال
            'children.required'          => 'يجب إضافة طفل واحد على الأقل للاشتراك.',
            'children.array'             => 'صيغة بيانات الأطفال غير صحيحة.',
            'children.min'               => 'يجب تحديد طفل واحد على الأقل.',
            'children.*.child_id.required' => 'معرف الطفل مطلوب.',
            'children.*.child_id.integer'  => 'معرف الطفل يجب أن يكون رقماً صحيحاً.',
            'children.*.child_id.exists'   => 'أحد الأطفال المحددين غير موجود في النظام.',

            // السعر
            'total_price.numeric' => 'يجب أن يكون السعر الإجمالي رقماً.',
            'total_price.min'     => 'لا يمكن أن يكون السعر الإجمالي أقل من صفر.',
        ];
    }
}
