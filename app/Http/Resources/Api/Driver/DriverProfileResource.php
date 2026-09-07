<?php

namespace App\Http\Resources\Api\Driver;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class DriverProfileResource extends JsonResource
{
    /**
     * تحويل كائن السائق إلى مصفوفة JSON احترافية وموحدة
     */
    public function toArray(Request $request): array
    {
        $user = $this->user;

        // جلب آخر طلب تعديل معلق للسائق (إن وجد) لإعلام التطبيق بالبيانات التي تحت المراجعة
        $pendingChange = DB::table('driver_profile_changes')
            ->where('driver_id', $this->id)
            ->where('status', 'Pending')
            ->latest()
            ->first();

        return [
            'driver_id'         => $this->id,
            'user_id'           => $this->user_id,
            'role_id'           => $user->role_id ?? null,
            'full_name'         => $user->full_name ?? '',
            'email'             => $user->email ?? '',
            'phone_number'      => $user->phone_number ?? '',
            'alternative_phone' => $user->alternative_phone,
            'avatar_url'        => \App\Http\Controllers\Api\Shared\MediaController::urlFor($user->avatar_url ?? null),
            'gender'            => $this->gender,
            'account_status'    => $this->status, // Pending, Active, Incomplete, Rejected
            'is_active'         => $user->is_active ?? 0,
            
            // البيانات القانونية الحالية في السيرفر
            'legal_data' => [
                'national_id'    => $this->national_id,
                'license_number' => $this->license_number,
                'license_expiry' => $this->license_expiry,
            ],

            // جلب المركبات المرتبطة بالسائق
            'vehicles' => $this->vehicles->map(function ($vehicle) {
                return [
                    'id'                => $vehicle->id,
                    'plate_number'      => $vehicle->plate_number,
                    'brand'             => $vehicle->brand,
                    'model'             => $vehicle->model,
                    'year'              => $vehicle->year,
                    'color'             => $vehicle->color,
                    'type'              => $vehicle->type,
                    'capacity_manual'   => $vehicle->capacity_manual,
                    'vehicle_image_url' => \App\Http\Controllers\Api\Shared\MediaController::urlFor($vehicle->vehicle_image_url),
                    'has_ac'            => (bool) $vehicle->has_ac,
                    'status'            => $vehicle->status, // Pending, Active, Rejected
                    'is_verified'       => (bool) $vehicle->is_verified,
                ];
            }),

            'documents' => $this->whenLoaded('documents', function () {
                return $this->documents->map(fn ($doc) => [
                    'id'                               => $doc->id,
                    'doc_type'                         => $doc->doc_type,
                    'file_url'                         => \App\Http\Controllers\Api\Shared\MediaController::urlFor($doc->file_url),
                    'expiry_date'                      => $doc->expiry_date ? \Carbon\Carbon::parse($doc->expiry_date)->format('Y-m-d') : null,
                    'is_verified'                      => (bool) $doc->is_verified,
                    'state'                            => $doc->state ?? 'pending',
                    'state_label'                      => $doc->state_label ?? 'معلقة',
                    'insurance_expiry_date'            => $doc->insurance_expiry_date ?? ($doc->expiry_date ? \Carbon\Carbon::parse($doc->expiry_date)->format('Y-m-d') : null),
                    'stamp_expiry_date'                => $doc->stamp_expiry_date,
                    'technical_inspection_expiry_date' => $doc->technical_inspection_expiry_date,
                    'status'                           => $doc->state ?? ($doc->status ?? 'pending'),
                    'feedback'                         => $doc->feedback,
                    'uploaded_at'                      => $doc->uploaded_at ?? ($doc->created_at ? $doc->created_at->toDateTimeString() : null),
                ]);
            }),

            // هندسة ذكية لواجهة التطبيق: إعلام التطبيق بوجود تحديثات بانتظار موافقة الأدمن
            'meta_sync' => [
                'has_pending_changes' => !empty($pendingChange),
                'email_change_pending' => (bool) ($user->email_change_pending ?? false),
                'pending_data'        => $pendingChange ? json_decode($pendingChange->new_values, true) : null,
            ],
            
            'created_at'        => $this->created_at ? $this->created_at->toDateTimeString() : null,
        ];
    }
}