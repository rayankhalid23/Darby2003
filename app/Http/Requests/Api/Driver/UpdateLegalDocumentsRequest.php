<?php

namespace App\Http\Requests\Api\Driver;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateLegalDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        $user = auth()->user();

        return $user->driver !== null 
            || in_array((int) ($user->role_id ?? 0), [8, 4], true)
            || $user->role?->name === 'driver';
    }

    /**
     * مواءمة مسميات الحقول والملفات القادمة من الواجهة الأمامية (Flutter / Mobile) تلقائياً
     */
    public function validationData(): array
    {
        $data = parent::validationData();

        $fileAliases = [
            'license_photo'    => 'doc_license',
            'logbook_photo'    => 'doc_logbook',
            'insurance_photo'  => 'doc_insurance',
            'stamp_photo'      => 'doc_stamp',
            'inspection_photo' => 'doc_technical_inspection',
        ];

        foreach ($fileAliases as $alias => $standard) {
            if ($this->hasFile($alias) && empty($data[$standard])) {
                $data[$standard] = $this->file($alias);
            }
        }

        // تنظيف وتطبيع الرقم الوطني
        if (!empty($data['national_id']) && is_string($data['national_id'])) {
            $data['national_id'] = $this->sanitizeNationalId($data['national_id']);
        }

        // تطبيع التواريخ
        $dateFields = ['license_expiry', 'insurance_expiry', 'stamp_expiry', 'technical_inspection_expiry'];
        foreach ($dateFields as $dateField) {
            if (!empty($data[$dateField]) && is_string($data[$dateField])) {
                $data[$dateField] = $this->normalizeDate($data[$dateField]);
            }
        }

        return $data;
    }

    protected function prepareForValidation(): void
    {
        $fileAliases = [
            'license_photo'    => 'doc_license',
            'logbook_photo'    => 'doc_logbook',
            'insurance_photo'  => 'doc_insurance',
            'stamp_photo'      => 'doc_stamp',
            'inspection_photo' => 'doc_technical_inspection',
        ];

        foreach ($fileAliases as $alias => $standard) {
            if ($this->hasFile($alias) && !$this->hasFile($standard)) {
                $this->files->set($standard, $this->file($alias));
            }
        }

        $merge = [];

        if ($this->filled('national_id')) {
            $merge['national_id'] = $this->sanitizeNationalId((string) $this->input('national_id'));
        }

        $dateFields = ['license_expiry', 'insurance_expiry', 'stamp_expiry', 'technical_inspection_expiry'];
        foreach ($dateFields as $dateField) {
            if ($this->filled($dateField)) {
                $merge[$dateField] = $this->normalizeDate((string) $this->input($dateField));
            }
        }

        if (!empty($merge)) {
            $this->merge($merge);
        }
    }

    private function sanitizeNationalId(string $value): string
    {
        $arabicDigits = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $englishDigits = ['0','1','2','3','4','5','6','7','8','9'];
        $value = str_replace($arabicDigits, $englishDigits, trim($value));

        return preg_replace('/[^0-9]/', '', $value) ?? $value;
    }

    private function normalizeDate(string $value): string
    {
        $arabicDigits = ['٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $englishDigits = ['0','1','2','3','4','5','6','7','8','9'];
        $value = str_replace($arabicDigits, $englishDigits, trim($value));

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
        $driverId = auth()->user()->driver->id ?? null;

        return [
            // البيانات النصية القانونية (تعديل جزئي باستخدام sometimes)
            'national_id'    => ['sometimes', 'numeric', 'digits:12', Rule::unique('drivers', 'national_id')->ignore($driverId)],
            'license_number' => ['sometimes', 'string', 'max:50', Rule::unique('drivers', 'license_number')->ignore($driverId)],
            'license_expiry' => ['sometimes', 'date', 'after:today'],
            'insurance_expiry' => ['sometimes', 'date', 'after:today'],
            'stamp_expiry'                => ['sometimes', 'date', 'after:today'],
            'technical_inspection_expiry' => ['sometimes', 'date', 'after:today'],

            // ملفات المستندات (تدعم الصور بحجم يصل لـ 10MB)
            'doc_license'              => ['sometimes', 'file', 'mimes:jpeg,png,jpg,webp,heic,heif,pdf', 'max:10240'],
            'doc_logbook'              => ['sometimes', 'file', 'mimes:jpeg,png,jpg,webp,heic,heif,pdf', 'max:10240'],
            'doc_insurance'            => ['sometimes', 'file', 'mimes:jpeg,png,jpg,webp,heic,heif,pdf', 'max:10240'],
            'doc_booklet_page'         => ['sometimes', 'file', 'mimes:jpeg,png,jpg,webp,heic,heif,pdf', 'max:10240'],
            'doc_stamp'                => ['sometimes', 'file', 'mimes:jpeg,png,jpg,webp,heic,heif,pdf', 'max:10240'],
            'doc_technical_inspection' => ['sometimes', 'file', 'mimes:jpeg,png,jpg,webp,heic,heif,pdf', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'national_id.digits'        => 'الرقم الوطني يجب أن يتكون من 12 رقماً بالضبط.',
            'national_id.unique'        => 'الرقم الوطني هذا مسجل مسبقاً لسائق آخر.',
            'license_number.unique'     => 'رقم رخصة القيادة هذا مسجل مسبقاً لسائق آخر.',
            'license_expiry.after'      => 'تاريخ انتهاء الرخصة يجب أن يكون تاريخاً مستقبلياً صالحاً.',
            'insurance_expiry.date'     => 'تاريخ انتهاء التأمين غير صالح.',
            'insurance_expiry.after'    => 'تاريخ انتهاء التأمين يجب أن يكون تاريخاً مستقبلياً.',

            'doc_license.file'          => 'يجب أن يكون ملف رخصة القيادة ملفاً صالحاً.',
            'doc_license.mimes'         => 'يُسمح بالصور أو ملف PDF لرخصة القيادة.',
            'doc_logbook.file'          => 'يجب أن يكون ملف كتيب السيارة ملفاً صالحاً.',
            'doc_logbook.mimes'         => 'يُسمح بالصور أو ملف PDF لكتيب السيارة.',
            'doc_insurance.file'        => 'يجب أن يكون ملف وثيقة التأمين ملفاً صالحاً.',
            'doc_insurance.mimes'       => 'يُسمح بالصور أو ملف PDF لوثيقة التأمين.',
            'doc_license.max'           => 'حجم صورة المستند يجب ألا يتجاوز 10 ميجابايت كحد أقصى.',

            'stamp_expiry.date'                    => 'تاريخ انتهاء الدمغة غير صالح.',
            'stamp_expiry.after'                   => 'تاريخ انتهاء الدمغة يجب أن يكون تاريخاً مستقبلياً.',
            'technical_inspection_expiry.date'     => 'تاريخ انتهاء الفحص الفني غير صالح.',
            'technical_inspection_expiry.after'    => 'تاريخ انتهاء الفحص الفني يجب أن يكون تاريخاً مستقبلياً.',

            'doc_booklet_page.file'          => 'يجب أن يكون ملف بيانات الكتيب ملفاً صالحاً.',
            'doc_stamp.file'                 => 'يجب أن يكون ملف الدمغة ملفاً صالحاً.',
            'doc_technical_inspection.file'  => 'يجب أن يكون ملف الفحص الفني ملفاً صالحاً.',
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'status'  => false,
            'message' => 'عذراً، وثائق التجديد المرسلة تحتوي على أخطاء تحقق.',
            'errors'  => $validator->errors()
        ], 422));
    }
}