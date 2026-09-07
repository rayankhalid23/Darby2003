<?php

namespace App\Http\Requests\Api\Parent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;
use App\Enums\Shared\SchoolStage;

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
            'school_id'           => 'required|integer|exists:schools,id',
            // اختياري: كل أطفال ولي الأمر يُسنَدون تلقائياً للعنوان الرئيسي المفعّل،
            // فإن أُغفل الحقل يُملأ من العنوان الرئيسي، وإن أُرسل وجب أن يطابقه.
            'address_id'          => 'nullable|integer|exists:addresses,id',
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
            'pickup_time'         => 'nullable|date_format:H:i',
            'dropoff_time'        => 'nullable|date_format:H:i',
        ];
    }

    public function messages(): array
    {
        return [
            // التحقق من الوجود بالمنظومة
            'parent_id.exists'             => 'حساب ولي الأمر غير مسجل في النظام.',
            'school_id.required'           => 'يرجى تحديد المدرسة.',
            'school_id.integer'            => 'معرف المدرسة غير صالح.',
            'school_id.exists'             => 'المدرسة المختارة غير مسجلة في النظام.',
            'address_id.integer'           => 'معرف العنوان غير صالح.',
            'address_id.exists'            => 'العنوان المختار غير موجود بالنظام.',

            // البيانات الأساسية للطفل
            'full_name.required'           => 'الاسم الثلاثي للطفل مطلوب.',
            'full_name.string'             => 'اسم الطفل يجب أن يكون نصاً.',
            'full_name.min'                => 'اسم الطفل يجب ألا يقل عن 8 أحرف (الاسم الثلاثي).',
            'full_name.max'                => 'اسم الطفل يجب ألا يتجاوز 150 حرفاً.',
            'full_name.regex'              => 'يرجى إدخال الاسم الثلاثي للطفل بشكل صحيح.',

            'birth_date.required'          => 'تاريخ ميلاد الطفل مطلوب.',
            'birth_date.date'              => 'صيغة تاريخ الميلاد غير صحيحة.',
            'birth_date.after_or_equal'    => 'عمر الطفل لا يمكن أن يتجاوز 21 سنة.',
            'birth_date.before_or_equal'   => 'عمر الطفل لا يمكن أن يقل عن 6 سنوات.',

            'gender.required'              => 'يرجى تحديد جنس الطفل.',
            'gender.in'                    => 'جنس الطفل يجب أن يكون ذكر أو أنثى.',

            'grade.required'               => 'يرجى تحديد الصف الدراسي.',
            'grade.integer'                => 'الصف الدراسي يجب أن يكون رقماً صحيحاً.',
            'grade.min'                    => 'الصف الدراسي يجب أن يكون بين 0 (روضة) و 12 (ثانوي).',
            'grade.max'                    => 'الصف الدراسي يجب أن يكون بين 0 (روضة) و 12 (ثانوي).',

            'photo.image'                  => 'الملف المرفوع يجب أن يكون صورة.',
            'photo.mimes'                  => 'صيغة الصورة يجب أن تكون jpeg أو png أو jpg.',
            'photo.max'                    => 'حجم الصورة لا يجب أن يتجاوز 2 ميجابايت.',

            'medical_notes.string'         => 'الملاحظات الصحية يجب أن تكون نصاً.',
            'medical_notes.max'            => 'الملاحظات الصحية لا يمكن أن تتجاوز 1000 حرف.',

            'notification_radius.integer'   => 'نطاق الإشعار يجب أن يكون رقماً صحيحاً.',
            'notification_radius.min'       => 'نطاق الإشعار لا يقل عن 100 متر.',
            'notification_radius.max'       => 'نطاق الإشعار لا يتجاوز 5000 متر.',
            
            // اللوجستيات
            'preferred_time_slot.required' => 'يجب اختيار الفترة المفضلة (صباحي، مسائي، كلاهما).',
            'preferred_time_slot.in'       => 'الفترة المفضلة المختارة غير صالحة.',

            'pickup_time.date_format'      => 'وقت الالتقاط يجب أن يكون بصيغة HH:MM.',
            'dropoff_time.date_format'     => 'وقت التوصيل يجب أن يكون بصيغة HH:MM.',
        ];
    }

    /**
     * التحقق من سلامة العنوان ومنع تكرار الطفل
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $data  = $this->input();

            // ── 0. منع إسناد طفل إلى عنوان محذوف ────────────────────
            if (!empty($data['address_id'])) {
                $address = \App\Models\Parent\Address::withTrashed()->find($data['address_id']);
                if ($address && $address->trashed()) {
                    $v->errors()->add('address_id', 'العنوان المختار تم حذفه، لا يمكن إسناد طفل لعنوان محذوف.');
                }
            }

            // ── 0-أ. الطفل يُسنَد حصراً للعنوان الرئيسي المفعّل ─────────
            $ownerId        = auth()->id();
            $defaultAddress = $ownerId
                ? app(\App\Services\Parent\AddressService::class)->resolveEffectiveDefault($ownerId)
                : null;

            if ($ownerId && !$defaultAddress) {
                $v->errors()->add('address_id', 'لا يوجد عنوان رئيسي مفعّل في حسابك، يرجى إضافة عنوان أولاً قبل إضافة طفل.');
            } elseif ($defaultAddress && !empty($data['address_id'])
                && (int) $data['address_id'] !== (int) $defaultAddress->id) {
                $v->errors()->add(
                    'address_id',
                    'لا يمكن إسناد الطفل إلا للعنوان الرئيسي المفعّل [' . $defaultAddress->label . ']، يرجى تعيين العنوان المطلوب كعنوان رئيسي أولاً.'
                );
            }

            // ── 0.1 منع تكرار إضافة طفل مضاف مسبقاً لنفس ولي الأمر ────
            $parentId = auth()->id() ?? ($data['parent_id'] ?? null);
            if ($parentId && !empty($data['full_name'])) {
                $duplicateExists = \App\Models\Parent\Child::where('parent_id', $parentId)
                    ->where('full_name', $data['full_name'])
                    ->exists();
                if ($duplicateExists) {
                    $v->errors()->add('full_name', 'هذا الطفل مضاف مسبقاً في حسابك.');
                }
            }

        });
    }

    /**
     * معالجة أخطاء الفلترة: تسجيلها بالـ Log وإعادة رد واضح ودقيق للفرنت إند
     */
    protected function failedValidation(Validator $validator)
    {
        // 1. كتابة تفاصيل الحقول المسببة للخطأ في الـ Log
        Log::warning('StoreChild Validation Error', [
            'user_id'        => auth()->id(),
            'failed_fields'  => $validator->errors()->toArray(),
            'payload_sent'   => $this->except(['photo']),
        ]);

        // 2. إرجاع استجابة مرتبة للفرنت إند كـ JSON بكود 422 مع الرسالة الدقيقة المباشرة
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => $validator->errors()->first() ?: 'بيانات مدخلة غير صالحة، يرجى مراجعة الحقول.',
            'errors'  => $validator->errors()
        ], 422));
    }
}