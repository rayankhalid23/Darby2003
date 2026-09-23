<?php

namespace App\Http\Resources\Api\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminPendingChangeResource extends JsonResource
{
    /**
     * تنسيق مخرجات طلب التعديل المعلق ليعرض للأدمن البيانات الحالية مقارنة بالبيانات المطلوبة
     */
    public function toArray(Request $request): array
    {
        // فك قيم التعديلات المرسلة في الـ JSON أمنياً
        $newValues = is_string($this->new_values) ? json_decode($this->new_values, true) : ($this->new_values_decoded ?? []);

        return [
            'change_id'     => $this->id,
            'driver_id'     => $this->driver_id,
            'status'        => $this->status, // Pending
            'submitted_at'  => $this->created_at,

            // بيانات السائق الأساسية الحالية للتعرف عليه
            'driver_info' => [
                'full_name'    => $this->driver_name ?? ($this->driver?->user?->full_name),
                'phone_number' => $this->driver_phone ?? ($this->driver?->user?->phone_number),
            ],

            // 🚀 البيانات الحالية المخزنة في النظام (قبل التعديل)
            'current_system_data' => $this->driver ? [
                'full_name'         => $this->driver->user?->full_name,
                'phone_number'      => $this->driver->user?->phone_number,
                'alternative_phone' => $this->driver->user?->alternative_phone,
                'avatar_url'        => $this->driver->user?->avatar_url ? asset($this->driver->user->avatar_url) : null,
                'national_id'       => $this->driver->national_id,
                'license_number'    => $this->driver->license_number,
                'license_expiry'    => $this->driver->license_expiry,
                'license_image_url' => $this->driver->license_image_url ? asset($this->driver->license_image_url) : null,
                'vehicle'           => $this->driver->vehicles->where('is_verified', true)->first() ? [
                    'plate_number'        => $this->driver->vehicles->where('is_verified', true)->first()->plate_number,
                    'brand'               => $this->driver->vehicles->where('is_verified', true)->first()->brand,
                    'model'               => $this->driver->vehicles->where('is_verified', true)->first()->model,
                    'year'                => $this->driver->vehicles->where('is_verified', true)->first()->year,
                    'color'               => $this->driver->vehicles->where('is_verified', true)->first()->color,
                    'capacity_manual'     => $this->driver->vehicles->where('is_verified', true)->first()->capacity_manual,
                    'vehicle_image_url'   => $this->driver->vehicles->where('is_verified', true)->first()->vehicle_image_url ? asset($this->driver->vehicles->where('is_verified', true)->first()->vehicle_image_url) : null,
                ] : null
            ] : null,

            // 🚀 البيانات الجديدة والمستندات المرفوعة المطلوب اعتمادها من الأدمن
            'requested_new_data' => [
                'full_name'         => $newValues['full_name'] ?? null,
                'phone_number'      => $newValues['phone_number'] ?? null,
                'alternative_phone' => $newValues['alternative_phone'] ?? null,
                'avatar_url'        => isset($newValues['avatar_url']) ? asset($newValues['avatar_url']) : null,
                'national_id'       => $newValues['national_id'] ?? null,
                'license_number'    => $newValues['license_number'] ?? null,
                'license_expiry'    => $newValues['license_expiry'] ?? null,
                'license_image_url' => isset($newValues['license_image_url']) ? asset($newValues['license_image_url']) : (isset($newValues['doc_license_path']) ? asset($newValues['doc_license_path']) : null),
                'vehicle'           => isset($newValues['plate_number']) || isset($newValues['brand']) ? [
                    'plate_number'      => $newValues['plate_number'] ?? null,
                    'brand'             => $newValues['brand'] ?? null,
                    'model'             => $newValues['model'] ?? null,
                    'year'              => $newValues['year'] ?? null,
                    'color'             => $newValues['color'] ?? null,
                    'capacity_manual'   => $newValues['capacity_manual'] ?? null,
                    'vehicle_image_url' => isset($newValues['vehicle_image_path']) ? asset($newValues['vehicle_image_path']) : null,
                ] : null
            ]
        ];
    }
}