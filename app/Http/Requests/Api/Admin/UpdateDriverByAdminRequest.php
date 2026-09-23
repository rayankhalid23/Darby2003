<?php

namespace App\Http\Requests\Api\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDriverByAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        $data = parent::validationData();

        if ($this->hasFile('avatar_url') && empty($data['avatar'])) {
            $data['avatar'] = $this->file('avatar_url');
        }
        if ($this->hasFile('photo') && empty($data['avatar'])) {
            $data['avatar'] = $this->file('photo');
        }

        return $data;
    }

    protected function prepareForValidation(): void
    {
        if ($this->hasFile('avatar_url') && !$this->hasFile('avatar')) {
            $this->files->set('avatar', $this->file('avatar_url'));
        }
        if ($this->hasFile('photo') && !$this->hasFile('avatar')) {
            $this->files->set('avatar', $this->file('photo'));
        }
    }

    public function rules(): array
    {
        $driverId = $this->route('id') ?? $this->route('driver');

        return [
            // ── بيانات شخصية وحساب ──
            'full_name'      => ['nullable', 'string', 'max:150'],
            'phone_number'   => ['nullable', 'string', 'regex:/^(09[1-689][0-9]{7}|09[0-9]{8})$/'],
            'national_id'    => ['nullable', 'string', 'max:50'],
            'license_number' => ['nullable', 'string', 'max:50'],
            'license_expiry' => ['nullable', 'date'],
            'status'         => ['nullable', 'string', 'in:Pending,Approved,Rejected,Suspended,Active,Inactive,active,approved,rejected,pending'],
            'is_active'      => ['nullable', 'boolean'],
            'reason'         => ['nullable', 'string', 'max:500'],
            'avatar'         => ['nullable', 'file', 'mimes:jpeg,png,jpg,webp,heic,heif', 'max:10240'],
            'avatar_url'     => ['nullable', 'string', 'max:500'],

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
            'vehicle_image'   => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:4096'],

            // ── الوثائق الرسمية (اختيارية) ──
            'insurance_expiry' => ['nullable', 'date'],
            'doc_license'       => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:4096'],
            'doc_logbook'       => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:4096'],
            'doc_insurance'     => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:4096'],
            'stamp_expiry'                => ['nullable', 'date'],
            'technical_inspection_expiry' => ['nullable', 'date'],
            'doc_booklet_page'         => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:4096'],
            'doc_stamp'                => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:4096'],
            'doc_technical_inspection' => ['nullable', 'image', 'mimes:jpeg,png,jpg', 'max:4096'],
        ];
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
