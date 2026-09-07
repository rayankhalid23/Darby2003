<?php

namespace App\Http\Requests\Api\Parent;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Log;

class UpdateChildRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function prepareForValidation(): void
    {
        // دعم المسميات البديلة للصور
        if ($this->hasFile('child_photo') && !$this->hasFile('photo')) {
            $this->files->set('photo', $this->file('child_photo'));
        }
        if ($this->hasFile('image') && !$this->hasFile('photo')) {
            $this->files->set('photo', $this->file('image'));
        }

        // دعم تحويل الجنس بالعربية والإنجليزية
        if ($this->filled('gender')) {
            $gender = strtolower(trim((string)$this->input('gender')));
            if (in_array($gender, ['male', 'ذكر', '1', 'm'], true)) {
                $this->merge(['gender' => 'male']);
            } elseif (in_array($gender, ['female', 'أنثى', '2', 'f'], true)) {
                $this->merge(['gender' => 'female']);
            }
        }
    }

    public function rules(): array
    {
        $minDate = Carbon::now()->subYears(21)->format('Y-m-d');
        $maxDate = Carbon::now()->subYears(4)->format('Y-m-d');

        return [
            'school_id'           => 'sometimes|nullable|exists:schools,id',
            'address_id'          => 'sometimes|nullable|exists:addresses,id',
            'full_name'           => ['sometimes', 'nullable', 'string', 'min:3', 'max:150'],
            'birth_date'          => "sometimes|nullable|date|after_or_equal:{$minDate}|before_or_equal:{$maxDate}",
            'gender'              => ['sometimes', 'nullable', Rule::in(['male', 'female'])],
            'grade'               => 'sometimes|nullable|integer|min:0|max:12',
            'photo'               => 'sometimes|nullable|file|mimes:jpeg,png,jpg,webp,heic,heif|max:10240',
            'medical_notes'       => 'sometimes|nullable|string|max:1000',
            'notification_radius' => 'sometimes|nullable|integer|min:50|max:5000',

            // البيانات اللوجستية والاشتراك (كلها اختيارية وجزئية)
            'preferred_time_slot' => ['sometimes', 'nullable', Rule::in(['morning', 'evening', 'both'])],
            'pickup_time'         => 'sometimes|nullable|date_format:H:i',
            'dropoff_time'        => 'sometimes|nullable|date_format:H:i',
            'is_active'           => 'sometimes|nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'school_id.exists'           => 'المدرسة المختارة غير مسجلة في النظام.',
            'address_id.exists'          => 'العنوان المختار غير موجود بالنظام.',
            'full_name.min'              => 'اسم الطفل يجب ألا يقل عن 3 أحرف.',
            'birth_date.after_or_equal'  => 'عمر الطفل لا يمكن أن يتجاوز 21 سنة.',
            'birth_date.before_or_equal' => 'عمر الطفل لا يمكن أن يقل عن 4 سنوات.',
            'gender.in'                  => 'يرجى تحديد جنس الطفل (ذكر أو أنثى).',
            'grade.min'                  => 'الصف الدراسي يبدأ من 0 (روضة) وحتى 12 (ثانوي).',
            'grade.max'                  => 'الصف الدراسي لا يتجاوز 12.',
            'photo.mimes'                => 'صيغة الصورة يجب أن تكون: jpeg, png, jpg, webp, heic, heif.',
            'photo.max'                  => 'حجم الصورة كبير جداً، الحد الأقصى 10 ميجابايت.',
            'preferred_time_slot.in'     => 'الفترة المختارة غير صالحة (morning, evening, both).',
        ];
    }

    /**
     * الطفل مسنَد دائماً للعنوان الرئيسي المفعّل، فلا يُقبل نقله لعنوان ثانوي
     * عبر تعديل بياناته — تغيير العنوان يتم حصراً من مسار تعيين العنوان الرئيسي.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $addressId = $this->input('address_id');

            if (empty($addressId) || !auth()->id()) {
                return;
            }

            $defaultAddress = app(\App\Services\Parent\AddressService::class)
                ->resolveEffectiveDefault(auth()->id());

            if (!$defaultAddress) {
                $v->errors()->add('address_id', 'لا يوجد عنوان رئيسي مفعّل في حسابك، يرجى إضافة عنوان أولاً.');
                return;
            }

            if ((int) $addressId !== (int) $defaultAddress->id) {
                $v->errors()->add(
                    'address_id',
                    'لا يمكن إسناد الطفل إلا للعنوان الرئيسي المفعّل [' . $defaultAddress->label . ']، يرجى تعيين العنوان المطلوب كعنوان رئيسي أولاً.'
                );
            }
        });
    }

    protected function failedValidation(Validator $validator)
    {
        Log::warning('UpdateChild Validation Error', [
            'user_id'       => auth()->id(),
            'failed_fields' => $validator->errors()->toArray(),
            'payload_sent'  => $this->except(['photo']),
        ]);

        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'بيانات مدخلة غير صالحة، يرجى مراجعة الحقول.',
            'errors'  => $validator->errors()
        ], 422));
    }
}