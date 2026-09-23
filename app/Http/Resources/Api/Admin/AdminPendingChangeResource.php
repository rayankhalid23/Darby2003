<?php

namespace App\Http\Resources\Api\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Controllers\Api\Shared\MediaController;

class AdminPendingChangeResource extends JsonResource
{
    /**
     * تنسيق مخرجات طلب التعديل المعلق ليعرض للأدمن البيانات الحالية مقارنة بالبيانات المطلوبة بدقة تامة
     */
    public function toArray(Request $request): array
    {
        $oldValues = is_string($this->old_values) ? json_decode($this->old_values, true) : ($this->old_values ?? []);
        $newValues = is_string($this->new_values) ? json_decode($this->new_values, true) : ($this->new_values ?? []);

        // تحويل روابط الصور والوثائق القديمة والجديدة لروابط آمنة عبر MediaController
        $formatMediaMap = function (array $values) {
            $formatted = $values;
            foreach (['avatar_url', 'vehicle_image_url', 'vehicle_image_path', 'doc_license_path', 'license_image_url', 'doc_logbook_path', 'doc_insurance_path', 'doc_booklet_page_path', 'doc_stamp_path', 'doc_technical_inspection_path'] as $key) {
                if (!empty($formatted[$key])) {
                    $formatted[$key] = MediaController::urlFor($formatted[$key]);
                }
            }
            return $formatted;
        };

        $formattedOld = $formatMediaMap($oldValues);
        $formattedNew = $formatMediaMap($newValues);

        return [
            'request_id'        => $this->id ?? $this->request_id,
            'change_id'         => $this->id ?? $this->request_id,
            'driver_id'         => $this->driver_id,
            'driver_name'       => $this->driver_name ?? ($this->driver?->user?->full_name),
            'driver_phone'      => $this->driver_phone ?? ($this->driver?->user?->phone_number),
            'status'            => $this->status,
            'rejection_reason'  => $this->rejection_reason ?? null,
            'submitted_at'      => $this->created_at ? date('Y-m-d H:i:s', strtotime((string)$this->created_at)) : null,
            'created_at'        => $this->created_at ? date('Y-m-d H:i:s', strtotime((string)$this->created_at)) : null,

            // 1. الكائنات المباشرة للمقارنة (Direct Key-Value Diff)
            'old_values'        => $formattedOld,
            'new_values'        => $formattedNew,

            // 2. التنسيق الهيكلي المصنف (Structured View for Admin Dashboard)
            'driver_info' => [
                'full_name'    => $this->driver_name ?? ($this->driver?->user?->full_name),
                'phone_number' => $this->driver_phone ?? ($this->driver?->user?->phone_number),
            ],

            'current_system_data' => [
                'full_name'         => $oldValues['full_name'] ?? null,
                'phone_number'      => $oldValues['phone_number'] ?? null,
                'alternative_phone' => $oldValues['alternative_phone'] ?? null,
                'avatar_url'        => MediaController::urlFor($oldValues['avatar_url'] ?? null),
                'national_id'       => $oldValues['national_id'] ?? null,
                'license_number'    => $oldValues['license_number'] ?? null,
                'license_expiry'    => $oldValues['license_expiry'] ?? null,
                'license_image_url' => MediaController::urlFor($oldValues['license_image_url'] ?? $oldValues['doc_license_path'] ?? null),
                'documents' => [
                    'doc_license_path'              => MediaController::urlFor($oldValues['doc_license_path'] ?? $oldValues['license_image_url'] ?? null),
                    'license_image_url'             => MediaController::urlFor($oldValues['license_image_url'] ?? $oldValues['doc_license_path'] ?? null),
                    'doc_logbook_path'              => MediaController::urlFor($oldValues['doc_logbook_path'] ?? null),
                    'doc_insurance_path'            => MediaController::urlFor($oldValues['doc_insurance_path'] ?? null),
                    'doc_booklet_page_path'         => MediaController::urlFor($oldValues['doc_booklet_page_path'] ?? null),
                    'doc_stamp_path'                => MediaController::urlFor($oldValues['doc_stamp_path'] ?? null),
                    'doc_technical_inspection_path' => MediaController::urlFor($oldValues['doc_technical_inspection_path'] ?? null),
                    'insurance_expiry'              => $oldValues['insurance_expiry'] ?? null,
                    'stamp_expiry'                  => $oldValues['stamp_expiry'] ?? null,
                    'technical_inspection_expiry'   => $oldValues['technical_inspection_expiry'] ?? null,
                ],
                'vehicle' => (isset($oldValues['plate_number']) || isset($oldValues['brand']) || isset($oldValues['vehicle_image_url'])) ? [
                    'plate_number'      => $oldValues['plate_number'] ?? null,
                    'brand'             => $oldValues['brand'] ?? null,
                    'model'             => $oldValues['model'] ?? null,
                    'year'              => $oldValues['year'] ?? null,
                    'color'             => $oldValues['color'] ?? null,
                    'type'              => $oldValues['type'] ?? null,
                    'capacity_manual'   => $oldValues['capacity_manual'] ?? null,
                    'has_ac'            => isset($oldValues['has_ac']) ? (bool)$oldValues['has_ac'] : null,
                    'vehicle_image_url' => MediaController::urlFor($oldValues['vehicle_image_url'] ?? null),
                ] : null
            ],

            'requested_new_data' => [
                'full_name'         => $newValues['full_name'] ?? null,
                'phone_number'      => $newValues['phone_number'] ?? null,
                'alternative_phone' => $newValues['alternative_phone'] ?? null,
                'avatar_url'        => MediaController::urlFor($newValues['avatar_url'] ?? null),
                'national_id'       => $newValues['national_id'] ?? null,
                'license_number'    => $newValues['license_number'] ?? null,
                'license_expiry'    => $newValues['license_expiry'] ?? null,
                'license_image_url' => MediaController::urlFor($newValues['license_image_url'] ?? $newValues['doc_license_path'] ?? null),
                'documents' => [
                    'doc_license_path'              => MediaController::urlFor($newValues['doc_license_path'] ?? $newValues['license_image_url'] ?? null),
                    'license_image_url'             => MediaController::urlFor($newValues['license_image_url'] ?? $newValues['doc_license_path'] ?? null),
                    'doc_logbook_path'              => MediaController::urlFor($newValues['doc_logbook_path'] ?? null),
                    'doc_insurance_path'            => MediaController::urlFor($newValues['doc_insurance_path'] ?? null),
                    'doc_booklet_page_path'         => MediaController::urlFor($newValues['doc_booklet_page_path'] ?? null),
                    'doc_stamp_path'                => MediaController::urlFor($newValues['doc_stamp_path'] ?? null),
                    'doc_technical_inspection_path' => MediaController::urlFor($newValues['doc_technical_inspection_path'] ?? null),
                    'insurance_expiry'              => $newValues['insurance_expiry'] ?? null,
                    'stamp_expiry'                  => $newValues['stamp_expiry'] ?? null,
                    'technical_inspection_expiry'   => $newValues['technical_inspection_expiry'] ?? null,
                ],
                'vehicle' => (isset($newValues['plate_number']) || isset($newValues['brand']) || isset($newValues['vehicle_image_path']) || isset($newValues['vehicle_image_url'])) ? [
                    'vehicle_id'        => $newValues['vehicle_id'] ?? null,
                    'plate_number'      => $newValues['plate_number'] ?? null,
                    'brand'             => $newValues['brand'] ?? null,
                    'model'             => $newValues['model'] ?? null,
                    'year'              => $newValues['year'] ?? null,
                    'color'             => $newValues['color'] ?? null,
                    'type'              => $newValues['type'] ?? null,
                    'capacity_manual'   => $newValues['capacity_manual'] ?? null,
                    'has_ac'            => isset($newValues['has_ac']) ? (bool)$newValues['has_ac'] : null,
                    'vehicle_image_url' => MediaController::urlFor($newValues['vehicle_image_path'] ?? ($newValues['vehicle_image_url'] ?? null)),
                ] : null
            ]
        ];
    }
}