<?php

namespace App\Http\Requests\Api\Parent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use App\Enums\Shared\SchoolStage;
use App\Enums\Shared\SubscriptionDuration;

class StoreChildRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; 
    }

    public function rules(): array
    {
        $minDate = Carbon::now()->subYears(21)->format('Y-m-d');
        $maxDate = Carbon::now()->subYears(6)->format('Y-m-d');

        return [
            // جعلنا parent_id اختياري هنا لأن الكنترولر يجذبه تلقائياً من التوكن (auth)
            'parent_id'           => 'nullable|exists:users,id',
            'school_id'           => 'required|exists:schools,id',
            'address_id'          => 'required|exists:addresses,id',
            'full_name'           => ['required', 'string', 'min:8', 'max:150', 'regex:/^[\p{L}]+([\s]+[\p{L}]+){2,}$/u'],
            'birth_date'          => "required|date|after_or_equal:{$minDate}|before_or_equal:{$maxDate}",
            'gender'              => ['required', Rule::in(['male', 'female'])],
            // grade: 0 = روضة، 1-6 = ابتدائي، 7-9 = إعدادي، 10-12 = ثانوي
            'grade'               => 'required|integer|min:0|max:12',
            'photo'               => 'nullable|image|mimes:jpeg,png,jpg|max:2048',
            'medical_notes'       => 'nullable|string|max:1000',
            'notification_radius' => 'nullable|integer|min:100|max:5000',

            // البيانات اللوجستية والاشتراك
            'preferred_time_slot' => ['required', Rule::in(['morning', 'evening', 'both'])],
            'trip_direction'      => ['required', Rule::in(['go', 'return', 'both'])],
            'pickup_time'         => 'nullable|date_format:H:i',
            'dropoff_time'        => 'nullable|date_format:H:i',
            'start_date'          => 'required|date',
            'end_date'            => 'required|date',
            'subscription_type'   => ['required', Rule::in(SubscriptionDuration::childValues())],
        ];
    }

    public function messages(): array
    {
        return [
            // رسائل التحقق من الوجود في قاعدة البيانات (تمنع ظهور validation.exists للفرنت)
            'parent_id.exists'           => 'حساب ولي الأمر غير موجود بالنظام.',
            'school_id.required'         => 'يرجى تحديد المدرسة.',
            'school_id.exists'           => 'المدرسة المختارة غير مسجلة في النظام.',
            'address_id.required'        => 'يرجى تحديد عنوان التوصيل.',
            'address_id.exists'          => 'العنوان المختار غير موجود بالنظام.',

            // البيانات الأساسية
            'full_name.required'         => 'الاسم الثلاثي مطلوب.',
            'full_name.regex'            => 'يرجى إدخال الاسم الثلاثي باللغة العربية بشكل صحيح.',
            'birth_date.required'        => 'تاريخ الميلاد مطلوب.',
            'birth_date.after_or_equal'  => 'عمر الطفل لا يمكن أن يتجاوز 21 سنة.',
            'birth_date.before_or_equal' => 'عمر الطفل لا يمكن أن يقل عن 6 سنوات.',
            'gender.required'            => 'يرجى تحديد جنس الطفل.',
            'gender.in'                  => 'يرجى تحديد جنس الطفل (ذكر أو أنثى).',
            'grade.required'             => 'يرجى تحديد الصف الدراسي.',
            'photo.image'                => 'يجب أن يكون الملف المرفوع صورة صالحة.',
            'photo.max'                  => 'حجم الصورة كبير جداً، الحد الأقصى 2 ميجابايت.',
            
            // اللوجستيات
            'preferred_time_slot.required' => 'يجب اختيار الفترة الزمنية المفضلة.',
            'preferred_time_slot.in'       => 'الفترة المختارة غير صالحة.',
            'trip_direction.required'      => 'يجب تحديد اتجاه الرحلة.',
            'trip_direction.in'            => 'اتجاه الرحلة غير صالح.',
            'pickup_time.date_format'      => 'صيغة وقت الالتقاط يجب أن تكون HH:MM.',
            'dropoff_time.date_format'     => 'صيغة وقت التوصيل يجب أن تكون HH:MM.',
            
            // الاشتراكات
            'start_date.required'        => 'تاريخ بدء الاشتراك مطلوب.',
            'start_date.date'            => 'تاريخ البدء غير صالح.',
            'end_date.required'          => 'تاريخ انتهاء الاشتراك مطلوب.',
            'end_date.date'              => 'تاريخ الانتهاء غير صالح.',
            'subscription_type.required' => 'يجب اختيار نوع الاشتراك.',
            'subscription_type.in'       => 'نوع الاشتراك غير صحيح (يجب أن يكون single_day أو multi_day).',
        ];
    }

    /**
     * التحقق من شروط التواريخ ونوع الاشتراك
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $data  = $this->input();
            $type  = $data['subscription_type'] ?? null;
            $start = isset($data['start_date']) ? Carbon::parse($data['start_date'])->startOfDay() : null;
            $end   = isset($data['end_date'])   ? Carbon::parse($data['end_date'])->startOfDay()   : null;
            $now   = Carbon::now();

            if (!$start || !$end) {
                return;
            }

            // ── 1. لا تواريخ ماضية ──────────────────────────────────
            if ($start->lt($now->copy()->startOfDay())) {
                $v->errors()->add('start_date', 'لا يمكن اختيار تاريخ في الماضي.');
                return;
            }

            // ── 2. حد الإغلاق الليلي ──────────────────────────────
            // بعد الساعة 22:00 لا يُقبل الحجز لليوم القادم
            $minStart = $now->copy()->addDay()->startOfDay();
            if ($now->hour >= 22) {
                $minStart->addDay();
            }
            // تخطّ عطلة نهاية الأسبوع لأول يوم عمل صالح
            while ($minStart->isFriday() || $minStart->isSaturday()) {
                $minStart->addDay();
            }

            if ($start->lt($minStart)) {
                $msg = $now->hour >= 22
                    ? 'بعد الساعة 10 مساءً لا يُقبل الحجز إلا من بعد غد على الأقل.'
                    : 'يجب أن يكون تاريخ البدء غداً على الأقل (لإشعار السائق مسبقاً).';
                $v->errors()->add('start_date', $msg);
                return;
            }

            // ── 3. بداية في يوم عمل فعلي ───────────────────────────
            if ($start->isFriday() || $start->isSaturday()) {
                $v->errors()->add('start_date', 'لا يمكن أن يبدأ الاشتراك في يوم عطلة (جمعة أو سبت).');
                return;
            }

            // ── 4. حد أقصى للحجز المسبق: 60 يوماً ─────────────────
            if ($start->gt($now->copy()->addDays(60)->startOfDay())) {
                $v->errors()->add('start_date', 'لا يمكن الحجز لأكثر من 60 يوماً مقدماً.');
                return;
            }

            // ── 5. تاريخ الانتهاء لا يسبق البدء ───────────────────
            if ($end->lt($start)) {
                $v->errors()->add('end_date', 'تاريخ الانتهاء لا يمكن أن يكون قبل تاريخ البدء.');
                return;
            }

            // ── 6. single_day: البداية = النهاية ───────────────────
            if ($type === SubscriptionDuration::SINGLE_DAY->value && !$start->eq($end)) {
                $v->errors()->add('end_date', 'اشتراك يوم واحد يجب أن يكون تاريخ البدء والانتهاء في نفس اليوم.');
                return;
            }

            // ── 7. multi_day: يجب أن تحتوي على يومي عمل على الأقل ─
            if ($type === SubscriptionDuration::MULTI_DAY->value) {
                if ($start->eq($end)) {
                    $v->errors()->add('end_date', 'اشتراك عدة أيام يجب أن يكون تاريخ الانتهاء بعد تاريخ البدء.');
                    return;
                }

                // عدّ أيام العمل بين البداية والنهاية
                $workingDays = 0;
                $cur = $start->copy();
                while ($cur->lte($end)) {
                    if (!$cur->isFriday() && !$cur->isSaturday()) {
                        $workingDays++;
                    }
                    $cur->addDay();
                }
                if ($workingDays < 2) {
                    $v->errors()->add('end_date', 'اشتراك عدة أيام يجب أن يحتوي على يومي عمل على الأقل (غير جمعة وسبت).');
                    return;
                }

                // ── 8. حد أقصى لمدة الاشتراك: 120 يوماً ──────────
                if ($end->gt($start->copy()->addDays(120))) {
                    $v->errors()->add('end_date', 'مدة الاشتراك لا يمكن أن تتجاوز 120 يوماً (4 أشهر).');
                }
            }
        });
    }

    /**
     * معالجة أخطاء الفلترة: تسجيلها بالـ Log وإعادة رد واضح للفرنت إند
     */
    protected function failedValidation(Validator $validator)
    {
        // 1. كتابة تفاصيل الحقول المسببة للخطأ في الـ Log
        Log::warning('StoreChild Validation Error', [
            'user_id'        => auth()->id(),
            'failed_fields'  => $validator->errors()->toArray(),
            'payload_sent'   => $this->except(['photo']),
        ]);

        // 2. إرجاع استجابة مرتبة للفرنت إند كـ JSON بكود 422
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'بيانات مدخلة غير صالحة، يرجى مراجعة الحقول.',
            'errors'  => $validator->errors()
        ], 422));
    }
}