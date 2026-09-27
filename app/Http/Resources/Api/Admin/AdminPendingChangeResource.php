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

        // 🚗 فحص وتحديد حقول المركبة التي تم تعديلها فعلياً فقط (عرض القديم والجديد للحقول المعدلة فقط)
        $vehicleKeys = ['plate_number', 'brand', 'model', 'year', 'color', 'type', 'capacity_manual', 'has_ac'];
        $modifiedVehicleOld = [];
        $modifiedVehicleNew = [];

        foreach ($vehicleKeys as $key) {
            if (array_key_exists($key, $newValues)) {
                if ($key === 'has_ac') {
                    $modifiedVehicleOld[$key] = isset($oldValues[$key]) ? (bool)$oldValues[$key] : null;
                    $modifiedVehicleNew[$key] = isset($newValues[$key]) ? (bool)$newValues[$key] : null;
                } else {
                    $modifiedVehicleOld[$key] = $oldValues[$key] ?? null;
                    $modifiedVehicleNew[$key] = $newValues[$key] ?? null;
                }
            }
        }

        $hasVehicleImageModified = array_key_exists('vehicle_image_path', $newValues)
            || array_key_exists('vehicle_image_url', $newValues)
            || array_key_exists('vehicle_image', $newValues)
            || array_key_exists('vehicle_photo', $newValues);

        if ($hasVehicleImageModified) {
            $oldImg = $oldValues['vehicle_image_url'] ?? ($oldValues['vehicle_image_path'] ?? null);
            $newImg = $newValues['vehicle_image_path'] ?? ($newValues['vehicle_image_url'] ?? ($newValues['vehicle_image'] ?? ($newValues['vehicle_photo'] ?? null)));

            $modifiedVehicleOld['vehicle_image_url'] = MediaController::urlFor($oldImg);
            $modifiedVehicleNew['vehicle_image_url'] = MediaController::urlFor($newImg);
        }

        // تصفية المصفوفات المباشرة old_values و new_values بحيث تعرض حصراً الحقول المعدلة
        $formattedOld = $formatMediaMap($oldValues);
        $formattedNew = $formatMediaMap($newValues);

        // إزالة أي حقول للمركبة لم تكن ضمن التعديل المطلوب من old_values
        foreach ($vehicleKeys as $vKey) {
            if (!array_key_exists($vKey, $newValues)) {
                unset($formattedOld[$vKey]);
            }
        }
        if (!$hasVehicleImageModified) {
            unset($formattedOld['vehicle_image_url'], $formattedOld['vehicle_image_path']);
        } else {
            if (!empty($modifiedVehicleOld['vehicle_image_url'])) {
                $formattedOld['vehicle_image_url'] = $modifiedVehicleOld['vehicle_image_url'];
            }
            if (!empty($modifiedVehicleNew['vehicle_image_url'])) {
                $formattedNew['vehicle_image_url'] = $modifiedVehicleNew['vehicle_image_url'];
            }
        }

        // إزالة المعرف الداخلي vehicle_id من عرض التغييرات حتى لا يظهر كحقل معدل
        unset($formattedOld['vehicle_id'], $formattedNew['vehicle_id']);

        $driverModel = isset($this->driver) && is_object($this->driver) ? $this->driver : null;
        $driverName  = $this->driver_name ?? ($driverModel?->user?->full_name);
        $driverPhone = $this->driver_phone ?? ($driverModel?->user?->phone_number);
        $vehicleId   = $newValues['vehicle_id'] ?? ($driverModel?->vehicles?->first()?->id ?? null);

        return [
            'request_id'        => $this->id ?? $this->request_id,
            'change_id'         => $this->id ?? $this->request_id,
            'id'                => $this->id ?? $this->request_id,
            'driver_id'         => $this->driver_id,
            'vehicle_id'        => $vehicleId,
            'driver_name'       => $driverName,
            'driver_phone'      => $driverPhone,
            'status'            => $this->status,
            'rejection_reason'  => $this->rejection_reason ?? null,
            'submitted_at'      => $this->created_at ? date('Y-m-d H:i:s', strtotime((string)$this->created_at)) : null,
            'created_at'        => $this->created_at ? date('Y-m-d H:i:s', strtotime((string)$this->created_at)) : null,

            // 1. الكائنات المباشرة للمقارنة (Direct Key-Value Diff)
            'old_values'        => $formattedOld,
            'new_values'        => $formattedNew,
            'old_data'          => $formattedOld,
            'new_data'          => $formattedNew,

            // 2. التنسيق الهيكلي المصنف (Structured View for Admin Dashboard)
            'driver_info' => [
                'full_name'    => $driverName,
                'phone_number' => $driverPhone,
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
                'vehicle'           => !empty($modifiedVehicleOld) ? $modifiedVehicleOld : null,
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
                'vehicle'           => !empty($modifiedVehicleNew) ? $modifiedVehicleNew : null,
            ]
        ];
    }
}