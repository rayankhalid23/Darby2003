<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDriverByAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    private const AVATAR_ALIASES = [
        'avatar_url',
        'photo',
        'driver_photo',
        'personal_photo',
        'image',
        'profile_image',
    ];

    public function validationData(): array
    {
        $data = parent::validationData();

        foreach (self::AVATAR_ALIASES as $alias) {
            if ($this->hasFile($alias) && empty($data['avatar'])) {
                $data['avatar'] = $this->file($alias);
                break;
            }
        }

        return $data;
    }

    protected function prepareForValidation(): void
    {
        foreach (self::AVATAR_ALIASES as $alias) {
            if ($this->files->has($alias) && !$this->files->has('avatar')) {
                $this->files->set('avatar', $this->files->get($alias));
                break;
            }
        }
    }

    public function rules(): array
    {
        $driverId = $this->route('id') ?? $this->route('driver');
        $data = $this->validationData();

        $rules = [
            // ── بيانات شخصية وحساب ──
            'full_name'      => ['nullable', 'string', 'max:150'],
            'phone_number'   => ['nullable', 'string', 'regex:/^(09[1-689][0-9]{7}|09[0-9]{8})$/'],
            'national_id'    => ['nullable', 'string', 'max:50'],
            'license_number' => ['nullable', 'string', 'max:50'],
            'license_expiry' => ['nullable', 'date'],
            'status'         => ['nullable', 'string', 'in:Pending,Approved,Rejected,Suspended,Active,Inactive,active,approved,rejected,pending'],
            'is_active'      => ['nullable', 'boolean'],
            'reason'         => ['nullable', 'string', 'max:500'],

            // ── بيانات المركبة (اختيارية) ──
            'vehicle_id'      => ['nullable', 'integer', 'exists:vehicles,id'],
            'plate_number'    => ['nullable', 'string', 'max:50'],
            'brand'           => ['nullable', 'string', 'max:100'],
            'model'           => ['nullable', 'string', 'max:100'],
            'year'            => ['nullable', 'integer', 'min:1980', 'max:' . (date('Y') + 1)],
            'color'           => ['nullable', 'string', 'max:50'],
            'type'            => ['nullable', 'string', 'max:50'],
            'capacity_manual' => ['nullable', 'integer', 'min:1', 'max:60'],
            'has_ac'          => ['nullable', 'boolean'],
            'vehicle_image'   => ['nullable', 'file', 'mimes:jpeg,png,jpg,webp,heic,heif', 'max:10240'],

            // ── الوثائق الرسمية (اختيارية) ──
            'insurance_expiry' => ['nullable', 'date'],
            'doc_license'       => ['nullable', 'file', 'mimes:jpeg,png,jpg,webp,heic,heif,pdf', 'max:10240'],
            'doc_logbook'       => ['nullable', 'file', 'mimes:jpeg,png,jpg,webp,heic,heif,pdf', 'max:10240'],
            'doc_insurance'     => ['nullable', 'file', 'mimes:jpeg,png,jpg,webp,heic,heif,pdf', 'max:10240'],
            'stamp_expiry'                => ['nullable', 'date'],
            'technical_inspection_expiry' => ['nullable', 'date'],
            'doc_booklet_page'         => ['nullable', 'file', 'mimes:jpeg,png,jpg,webp,heic,heif,pdf', 'max:10240'],
            'doc_stamp'                => ['nullable', 'file', 'mimes:jpeg,png,jpg,webp,heic,heif,pdf', 'max:10240'],
            'doc_technical_inspection' => ['nullable', 'file', 'mimes:jpeg,png,jpg,webp,heic,heif,pdf', 'max:10240'],
        ];

        foreach (array_merge(['avatar'], self::AVATAR_ALIASES) as $field) {
            $isFile = $this->hasFile($field) || (($data[$field] ?? null) instanceof \Illuminate\Http\UploadedFile);
            $rules[$field] = $isFile
                ? ['nullable', 'file', 'mimes:jpeg,png,jpg,webp,heic,heif', 'max:10240']
                : ['nullable', 'string'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'phone_number.regex'    => 'رقم الهاتف غير صالح، يجب أن يبدأ بـ 09 ويتكون من 10 أرقام.',
            'license_expiry.date'   => 'تاريخ انتهاء الرخصة غير صالح.',
            'status.in'             => 'حالة السائق المختارة غير صالحة.',
            'vehicle_id.exists'     => 'المركبة المحددة غير موجودة.',
            'insurance_expiry.date' => 'تاريخ انتهاء التأمين غير صالح.',
            'vehicle_image.image'   => 'صورة المركبة يجب أن تكون ملف صورة صالح.',
            'doc_license.image'     => 'مستند الرخصة يجب أن يكون ملف صورة صالح.',
            'doc_logbook.image'     => 'مستند دفتر السيارة يجب أن يكون ملف صورة صالح.',
            'doc_insurance.image'   => 'مستند التأمين يجب أن يكون ملف صورة صالح.',
            'stamp_expiry.date'                 => 'تاريخ انتهاء الدمغة غير صالح.',
            'technical_inspection_expiry.date'  => 'تاريخ انتهاء الفحص الفني غير صالح.',
            'doc_booklet_page.image'         => 'مستند بيانات الكتيب يجب أن يكون ملف صورة صالح.',
            'doc_stamp.image'                => 'مستند الدمغة يجب أن يكون ملف صورة صالح.',
            'doc_technical_inspection.image' => 'مستند الفحص الفني يجب أن يكون ملف صورة صالح.',
        ];
    }
}
