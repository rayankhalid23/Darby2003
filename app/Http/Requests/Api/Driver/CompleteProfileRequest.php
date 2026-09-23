<?php

namespace App\Http\Requests\Api\Driver;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class CompleteProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * مواءمة مسميات الحقول والملفات القادمة من الواجهة الأمامية (Flutter / Mobile) تلقائياً
     */
    public function validationData(): array
    {
        $data = parent::validationData();

        if ($this->hasFile('vehicle_photo') && empty($data['vehicle_image'])) {
            $data['vehicle_image'] = $this->file('vehicle_photo');
        }
        if ($this->hasFile('license_photo') && empty($data['doc_license'])) {
            $data['doc_license'] = $this->file('license_photo');
        }
        if ($this->hasFile('logbook_photo') && empty($data['doc_logbook'])) {
            $data['doc_logbook'] = $this->file('logbook_photo');
        }
        if ($this->hasFile('insurance_photo') && empty($data['doc_insurance'])) {
            $data['doc_insurance'] = $this->file('insurance_photo');
        }
        foreach (['avatar_url', 'photo', 'driver_photo', 'personal_photo'] as $alias) {
            if ($this->hasFile($alias) && empty($data['avatar'])) {
                $data['avatar'] = $this->file($alias);
            }
        }
        if (!empty($data['vehicle_type']) && empty($data['type'])) {
            $data['type'] = $data['vehicle_type'];
        }

        // 1. تنظيف وتطبيع الرقم الوطني (الملاحظة 1)
        if (!empty($data['national_id']) && is_string($data['national_id'])) {
            $data['national_id'] = $this->sanitizeNationalId($data['national_id']);
        }

        // 2. تطبيع التواريخ (الملاحظة 2)
        if (!empty($data['license_expiry']) && is_string($data['license_expiry'])) {
            $data['license_expiry'] = $this->normalizeDate($data['license_expiry']);
        }
        if (!empty($data['insurance_expiry']) && is_string($data['insurance_expiry'])) {
            $data['insurance_expiry'] = $this->normalizeDate($data['insurance_expiry']);
        }
        if (!empty($data['stamp_expiry']) && is_string($data['stamp_expiry'])) {
            $data['stamp_expiry'] = $this->normalizeDate($data['stamp_expiry']);
        }
        if (!empty($data['technical_inspection_expiry']) && is_string($data['technical_inspection_expiry'])) {
            $data['technical_inspection_expiry'] = $this->normalizeDate($data['technical_inspection_expiry']);
        }

        return $data;
    }

    protected function prepareForValidation(): void
    {
        if ($this->hasFile('vehicle_photo') && !$this->hasFile('vehicle_image')) {
            $this->files->set('vehicle_image', $this->file('vehicle_photo'));
        }
        if ($this->hasFile('license_photo') && !$this->hasFile('doc_license')) {
            $this->files->set('doc_license', $this->file('license_photo'));
        }
        if ($this->hasFile('logbook_photo') && !$this->hasFile('doc_logbook')) {
            $this->files->set('doc_logbook', $this->file('logbook_photo'));
        }
        if ($this->hasFile('insurance_photo') && !$this->hasFile('doc_insurance')) {
            $this->files->set('doc_insurance', $this->file('insurance_photo'));
        }
        foreach (['avatar_url', 'photo', 'driver_photo', 'personal_photo'] as $alias) {
            if ($this->hasFile($alias) && !$this->hasFile('avatar')) {
                $this->files->set('avatar', $this->file($alias));
            }
        }

        $merge = [];

        if ($this->filled('vehicle_type') && !$this->filled('type')) {
            $merge['type'] = $this->input('vehicle_type');
        }

        // تنظيف وتطبيع الرقم الوطني (الملاحظة 1)
        if ($this->filled('national_id')) {
            $merge['national_id'] = $this->sanitizeNationalId((string) $this->input('national_id'));
        }

        // تنظيف وتطبيع التواريخ (الملاحظة 2)
        if ($this->filled('license_expiry')) {
            $merge['license_expiry'] = $this->normalizeDate((string) $this->input('license_expiry'));
        }
        if ($this->filled('insurance_expiry')) {
            $merge['insurance_expiry'] = $this->normalizeDate((string) $this->input('insurance_expiry'));
        }
        if ($this->filled('stamp_expiry')) {
            $merge['stamp_expiry'] = $this->normalizeDate((string) $this->input('stamp_expiry'));
        }
        if ($this->filled('technical_inspection_expiry')) {
            $merge['technical_inspection_expiry'] = $this->normalizeDate((string) $this->input('technical_inspection_expiry'));
        }

        if (!empty($merge)) {
            $this->merge($merge);
        }
    }

    /**
     * تنظيف الرقم الوطني وتحويل الأرقام الشرقية وإزالة المسافات والشرطات
     */
    private function sanitizeNationalId(string $value): string
    {
        $arabicDigits = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $englishDigits = ['0','1','2','3','4','5','6','7','8','9'];
        $value = str_replace($arabicDigits, $englishDigits, trim($value));

        return preg_replace('/[^0-9]/', '', $value) ?? $value;
    }

    /**
     * تطبيع التواريخ وتحويل الصيغ مثل YYYY/MM/DD أو ISO Timestamps إلى YYYY-MM-DD
     */
    private function normalizeDate(string $value): string
    {
        $arabicDigits = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $englishDigits = ['0','1','2','3','4','5','6','7','8','9'];
        $value = str_replace($arabicDigits, $englishDigits, trim($value));

        // استخراج صيغة التاريخ YYYY-MM-DD في حال إرسال timestamp أو فواصل مائلة /
        if (preg_match('/^(\d{4})[\/\-](\d{1,2})[\/\-](\d{1,2})/', $value, $matches)) {
            $year = $matches[1];
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[3], 2, '0', STR_PAD_LEFT);
            return "{$year}-{$month}-{$day}";
        }

        return str_replace('/', '-', $value);
    }

    public function rules(): array
    {
        return [
            // بيانات السائق - تم توحيد الرقم الوطني ليكون 12 رقماً بالضبط
            'national_id'      => 'required|numeric|digits:12',
            'license_number'   => 'required|string|max:50',
            'license_expiry'   => 'required|date|after:today',
            'insurance_expiry' => 'required|date|after:today',
            'stamp_expiry'                => 'required|date|after:today',
            'technical_inspection_expiry' => 'required|date|after:today',
            'avatar'           => 'nullable|file|mimes:jpeg,png,jpg,webp,heic,heif|max:10240',

            // بيانات المركبة
            'plate_number'    => 'required|string|max:20',
            'brand'           => 'required|string|max:50',
            'model'           => 'required|string|max:50',
            'year'            => 'required|integer|min:2000|max:' . (date('Y') + 1),
            'color'           => 'required|string|max:30',
            'type'            => 'required|string|max:30',
            'capacity_manual' => 'required|integer|min:1|max:60',
            'vehicle_image'   => 'required|file|mimes:jpeg,png,jpg,webp,heic,heif|max:10240',
            'has_ac'          => 'required|boolean',

            // صور ومستندات السائق (تدعم الصور)
            'doc_license'     => 'required|file|mimes:jpeg,png,jpg,webp,heic,heif,pdf|max:10240',
            'doc_logbook'     => 'required|file|mimes:jpeg,png,jpg,webp,heic,heif,pdf|max:10240',
            'doc_insurance'   => 'required|file|mimes:jpeg,png,jpg,webp,heic,heif,pdf|max:10240',
            'doc_booklet_page'         => 'required|file|mimes:jpeg,png,jpg,webp,heic,heif,pdf|max:10240',
            'doc_stamp'                => 'required|file|mimes:jpeg,png,jpg,webp,heic,heif,pdf|max:10240',
            'doc_technical_inspection' => 'required|file|mimes:jpeg,png,jpg,webp,heic,heif,pdf|max:10240',
        ];
    }

    public function messages(): array
    {
        return [
            'national_id.required'      => 'رقم الهوية الوطنية مطلوب.',
            'national_id.numeric'       => 'الرقم الوطني يجب أن يتكون من أرقام فقط.',
            'national_id.digits'        => 'الرقم الوطني يجب أن يتكون من 12 رقماً بالضبط.',
            'license_number.required'   => 'رقم رخصة القيادة مطلوب.',
            'license_expiry.required'   => 'تاريخ انتهاء الرخصة مطلوب.',
            'license_expiry.date'       => 'تاريخ انتهاء الرخصة غير صالح.',
            'license_expiry.after'      => 'تاريخ انتهاء الرخصة يجب أن يكون في المستقبل.',
            'insurance_expiry.required' => 'تاريخ انتهاء التأمين مطلوب.',
            'insurance_expiry.date'     => 'تاريخ انتهاء التأمين غير صالح.',
            'insurance_expiry.after'    => 'تاريخ انتهاء التأمين يجب أن يكون في المستقبل.',
            'plate_number.required'     => 'رقم لوحة المركبة مطلوب.',
            'brand.required'            => 'ماركة المركبة مطلوبة.',
            'model.required'            => 'موديل المركبة مطلوب.',
            'year.required'             => 'سنة صنع المركبة مطلوبة.',
            'year.min'                  => 'سنة صنع المركبة يجب أن تكون من عام 2000 فما فوق.',
            'year.max'                  => 'سنة صنع المركبة غير منطقية.',
            'color.required'            => 'لون المركبة مطلوب.',
            'type.required'             => 'نوع المركبة مطلوب.',
            'capacity_manual.required'  => 'سعة الركاب مطلوبة.',
            'capacity_manual.min'       => 'سعة المركبة يجب أن تكون على الأقل مقعد واحد.',
            'capacity_manual.max'       => 'سعة الركاب القصوى المتاحة للتسجيل هي 60 راكباً.',
            'has_ac.required'           => 'يرجى تحديد ما إذا كانت المركبة مكيفة.',
            'vehicle_image.required'    => 'يرجى إرفاق صورة المركبة.',
            'vehicle_image.file'        => 'يجب أن تكون صورة المركبة ملفاً صالحاً.',
            'vehicle_image.mimes'       => 'يُسمح فقط بالصيغ (jpeg, png, jpg, webp) لصورة المركبة.',
            'vehicle_image.max'         => 'حجم صورة المركبة يجب ألا يتجاوز 10 ميجابايت.',
            'doc_license.required'      => 'يرجى إرفاق صورة رخصة القيادة.',
            'doc_license.file'          => 'ملف رخصة القيادة يجب أن يكون ملفاً صالحاً.',
            'doc_license.mimes'         => 'يُسمح بالصور أو ملف PDF لرخصة القيادة.',
            'doc_license.max'           => 'حجم ملف رخصة القيادة يجب ألا يتجاوز 10 ميجابايت.',
            'doc_logbook.required'      => 'يرجى إرفاق صورة كتيب المركبة.',
            'doc_logbook.file'          => 'ملف كتيب المركبة يجب أن يكون ملفاً صالحاً.',
            'doc_logbook.mimes'         => 'يُسمح بالصور أو ملف PDF لكتيب المركبة.',
            'doc_logbook.max'           => 'حجم ملف كتيب المركبة يجب ألا يتجاوز 10 ميجابايت.',
            'doc_insurance.required'    => 'يرجى إرفاق صورة وثيقة التأمين.',
            'doc_insurance.file'        => 'ملف وثيقة التأمين يجب أن يكون ملفاً صالحاً.',
            'doc_insurance.mimes'       => 'يُسمح بالصور أو ملف PDF لوثيقة التأمين.',
            'doc_insurance.max'         => 'حجم ملف وثيقة التأمين يجب ألا يتجاوز 10 ميجابايت.',

            'stamp_expiry.required'                => 'تاريخ انتهاء الدمغة مطلوب.',
            'stamp_expiry.date'                     => 'تاريخ انتهاء الدمغة غير صالح.',
            'stamp_expiry.after'                    => 'تاريخ انتهاء الدمغة يجب أن يكون في المستقبل.',
            'technical_inspection_expiry.required'  => 'تاريخ انتهاء الفحص الفني مطلوب.',
            'technical_inspection_expiry.date'      => 'تاريخ انتهاء الفحص الفني غير صالح.',
            'technical_inspection_expiry.after'     => 'تاريخ انتهاء الفحص الفني يجب أن يكون في المستقبل.',

            'doc_booklet_page.required' => 'يرجى إرفاق صورة بيانات الكتيب الشخصية.',
            'doc_booklet_page.file'     => 'ملف بيانات الكتيب يجب أن يكون ملفاً صالحاً.',
            'doc_booklet_page.mimes'    => 'يُسمح بالصور أو ملف PDF لصورة بيانات الكتيب.',
            'doc_booklet_page.max'      => 'حجم ملف بيانات الكتيب يجب ألا يتجاوز 10 ميجابايت.',

            'doc_stamp.required'        => 'يرجى إرفاق صورة الدمغة.',
            'doc_stamp.file'            => 'ملف الدمغة يجب أن يكون ملفاً صالحاً.',
            'doc_stamp.mimes'           => 'يُسمح بالصور أو ملف PDF للدمغة.',
            'doc_stamp.max'             => 'حجم ملف الدمغة يجب ألا يتجاوز 10 ميجابايت.',

            'doc_technical_inspection.required' => 'يرجى إرفاق صورة الفحص الفني.',
            'doc_technical_inspection.file'     => 'ملف الفحص الفني يجب أن يكون ملفاً صالحاً.',
            'doc_technical_inspection.mimes'    => 'يُسمح بالصور أو ملف PDF للفحص الفني.',
            'doc_technical_inspection.max'      => 'حجم ملف الفحص الفني يجب ألا يتجاوز 10 ميجابايت.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status'  => false,
            'message' => 'بيانات إكمال الملف الشخصي غير مكتملة أو تحتوي على أخطاء.',
            'errors'  => $validator->errors()
        ], 422));
    }
}