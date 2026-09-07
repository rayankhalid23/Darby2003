<?php

namespace App\Http\Requests\Api\Driver;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use App\Enums\driver\DriverShift;
use App\Enums\Shared\SchoolStage;
use App\Enums\Shared\SubscriptionDuration;

class UpdateDriverPreferencesRequest extends FormRequest
{
    /**
     * تحديد ما إذا كان المستخدم مخولاً لإجراء هذا الطلب
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * قواعد التحقق المطبقة على الطلب
     */
    public function rules(): array
    {
        return [
            'morning_go'        => ['sometimes', 'boolean'],
            'morning_return'    => ['sometimes', 'boolean'],
            'afternoon_go'      => ['sometimes', 'boolean'],
            'afternoon_return'  => ['sometimes', 'boolean'],
            'subscription_type' => ['sometimes', 'string', Rule::in(SubscriptionDuration::driverValues())],
            'school_stages'     => ['sometimes', 'array', 'min:1'],
            'school_stages.*'   => ['required', 'string', Rule::in(array_column(SchoolStage::cases(), 'value'))],
            'zones'             => ['sometimes', 'array', 'min:1', 'max:5'],
            'zones.*'           => ['required', 'integer', 'exists:zones,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'zones.max' => 'عذراً، الحد الأقصى لعدد المناطق المختارة هو 5 مناطق.',
            'zones.min' => 'يجب اختيار منطقة واحدة على الأقل في حال إرسال المناطق.',
        ];
    }

    /**
     * تحقق إضافي: في حال أرسل المستخدم جميع الفترات كـ false يرفض الطلب
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $shiftKeys = ['morning_go', 'morning_return', 'afternoon_go', 'afternoon_return'];
            $sentShiftKeys = array_intersect($shiftKeys, array_keys($this->all()));

            // فقط إذا أرسل المستخدم جميع الفترات الأربعة كـ false
            if (count($sentShiftKeys) === 4 &&
                !$this->boolean('morning_go') &&
                !$this->boolean('morning_return') &&
                !$this->boolean('afternoon_go') &&
                !$this->boolean('afternoon_return')
            ) {
                $v->errors()->add('shift_slots', 'يجب اختيار فترة عمل واحدة على الأقل (صباحي ذهاب أو إياب أو مسائي ذهاب أو إياب).');
            }
        });
    }
}