<?php

namespace App\Http\Resources\Api\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminDriverDetailResource extends JsonResource
{
    /**
     * معالجة مسار الملف: فك Base64 إن وُجد وحفظه كملف على القرص لضمان قصر الروابط
     */
    private function resolveMediaPath(?string $value, string $folder = 'drivers/avatars'): ?string
    {
        if (empty($value)) {
            return null;
        }

        // إذا تم إدخال نص Base64 طويل، نقوم بفكه وحفظه كملف لتقليل الحجم وإرجاع مساره النظيف
        if (preg_match('/^data:image\/(\w+);base64,/', $value, $type)) {
            $data = substr($value, strpos($value, ',') + 1);
            $extension = strtolower($type[1]);
            $decoded = base64_decode($data);

            if ($decoded !== false) {
                $fileName = "{$folder}/" . Str::random(30) . ".{$extension}";
                Storage::disk('public')->put($fileName, $decoded);
                return $fileName;
            }
        }

        return $value;
    }

    /**
     * تحويل كائن السائق إلى ملف تفصيلي عميق يشمل الوثائق، المركبات، والإحصائيات
     */
    public function toArray(Request $request): array
    {
        $rawLicense = $this->resolveMediaPath($this->license_image_url ?? $this->license_image, 'drivers/documents');
        $licenseUrl = \App\Http\Controllers\Api\Shared\MediaController::urlFor($rawLicense);
        $licenseDataUrl = \App\Http\Controllers\Api\Shared\MediaController::dataUrlFor($rawLicense);

        $rawAvatar = $this->resolveMediaPath($this->user?->avatar_url ?? $this->avatar_url, 'drivers/avatars');
        $avatarUrl = \App\Http\Controllers\Api\Shared\MediaController::urlFor($rawAvatar);
        $avatarDataUrl = \App\Http\Controllers\Api\Shared\MediaController::dataUrlFor($rawAvatar);

        return [
            'id'             => $this->id,
            'status'         => $this->status,
            'gender'         => $this->gender,
            'national_id'    => $this->national_id,
            'license_number' => $this->license_number,
            'license_expiry' => $this->license_expiry ? (\Carbon\Carbon::parse($this->license_expiry)->format('Y-m-d')) : null,

            // روابط نظيفة وقصيرة تمر عبر api/media مع ترويسات CORS
            'license_image_url'      => $licenseUrl,
            'license_image_data_url' => $licenseDataUrl,

            'avatar_url'      => $avatarUrl,
            'avatar_data_url' => $avatarDataUrl,

            // بيانات الموقع الجغرافي اللحظي
            'location' => [
                'lat'          => $this->current_lat,
                'lng'          => $this->current_lng,
                'last_ping_at' => $this->last_ping_at ? (\Carbon\Carbon::parse($this->last_ping_at)->format('Y-m-d H:i:s')) : null,
            ],

            // الإحصائيات
            'statistics' => [
                'rating_avg'            => (float) ($this->rating_avg ?? 5.0),
                'completed_trips_count' => (int) ($this->completed_trips_count ?? 0),
                'retention_rate'        => (float) ($this->retention_rate ?? 100.0),
            ],

            // حالة الذكاء الاصطناعي
            'ai_status' => [
                'rating_avg'            => round((float) ($this->rating_avg ?? 5.0), 2),
                'is_suspended'          => (bool) ($this->is_suspended ?? false),
                'suspended_until'       => $this->suspended_until ? $this->suspended_until->toDateTimeString() : null,
                'active_warnings_count' => (int) ($this->active_warnings_count ?? 0),
                'suspension_count'      => (int) ($this->suspension_count ?? 0),
                'last_incident_at'      => $this->last_incident_at ? $this->last_incident_at->toDateTimeString() : null,
                'ai_last_reset_at'      => $this->ai_last_reset_at ? $this->ai_last_reset_at->toDateTimeString() : null,
            ],

            // بيانات الحساب والمستخدم الأساسية
            'user_account' => [
                'user_id'           => $this->user->id ?? null,
                'full_name'         => $this->user->full_name ?? null,
                'email'             => $this->user->email ?? null,
                'phone_number'      => $this->user->phone_number ?? null,
                'alternative_phone' => $this->user->alternative_phone ?? null,
                'avatar_url'        => $avatarUrl,
                'avatar_data_url'   => $avatarDataUrl,
                'is_active'         => (bool) ($this->user->is_active ?? false),
            ],

            // مصفوفة المركبات المسجلة للسائق
            'vehicles' => $this->vehicles ? $this->vehicles->map(function ($vehicle) {
                $rawVeh = $this->resolveMediaPath($vehicle->vehicle_image_url ?? $vehicle->vehicle_image, 'drivers/vehicles');
                $vehUrl = \App\Http\Controllers\Api\Shared\MediaController::urlFor($rawVeh);
                $vehDataUrl = \App\Http\Controllers\Api\Shared\MediaController::dataUrlFor($rawVeh);

                return [
                    'id'                     => $vehicle->id,
                    'brand'                  => $vehicle->brand,
                    'model'                  => $vehicle->model,
                    'year'                   => $vehicle->year,
                    'plate_number'           => $vehicle->plate_number,
                    'color'                  => $vehicle->color,
                    'type'                   => $vehicle->type,
                    'capacity_manual'        => $vehicle->capacity_manual,
                    'has_ac'                 => (bool) $vehicle->has_ac,
                    'vehicle_image_url'      => $vehUrl,
                    'vehicle_image_data_url' => $vehDataUrl,
                    'status'                 => $vehicle->status,
                    'is_verified'            => (bool) $vehicle->is_verified,
                ];
            }) : [],

            // مصفوفة الوثائق والمستندات الرسمية المرفوعة
            'documents' => (function () use ($rawLicense, $licenseUrl, $licenseDataUrl) {
                $docs = collect();

                if (!empty($rawLicense)) {
                    $docs->push([
                        'id'                               => null,
                        'document_type'                    => 'LICENSE',
                        'type'                             => 'license',
                        'document_url'                     => $licenseUrl,
                        'document_data_url'                => $licenseDataUrl,
                        'file_url'                         => $licenseUrl,
                        'expiry_date'                      => $this->license_expiry ? (\Carbon\Carbon::parse($this->license_expiry)->format('Y-m-d')) : null,
                        'is_verified'                      => $this->status === 'Approved',
                        'state'                            => $this->status === 'Approved' ? 'active' : ($this->status === 'Rejected' ? 'rejected' : 'pending'),
                        'state_label'                      => $this->status === 'Approved' ? 'معتمدة' : ($this->status === 'Rejected' ? 'مرفوضة' : 'معلقة'),
                        'insurance_expiry_date'            => null,
                        'stamp_expiry_date'                => null,
                        'technical_inspection_expiry_date' => null,
                        'status'                           => $this->status === 'Approved' ? 'approved' : ($this->status === 'Rejected' ? 'rejected' : 'pending'),
                        'feedback'                         => $this->rejection_reason ?? null,
                    ]);
                }

                if ($this->documents) {
                    foreach ($this->documents as $doc) {
                        $rawDoc = $this->resolveMediaPath($doc->file_url ?? $doc->document_url, 'drivers/documents');
                        $docUrl = \App\Http\Controllers\Api\Shared\MediaController::urlFor($rawDoc);
                        $docDataUrl = \App\Http\Controllers\Api\Shared\MediaController::dataUrlFor($rawDoc);

                        $docs->push([
                            'id'                               => $doc->id,
                            'document_type'                    => $doc->doc_type ?? $doc->document_type,
                            'type'                             => strtolower($doc->doc_type ?? $doc->document_type ?? ''),
                            'document_url'                     => $docUrl,
                            'document_data_url'                => $docDataUrl,
                            'file_url'                         => $docUrl,
                            'expiry_date'                      => $doc->expiry_date ? \Carbon\Carbon::parse($doc->expiry_date)->format('Y-m-d') : null,
                            'is_verified'                      => (bool) $doc->is_verified,
                            'state'                            => $doc->state ?? 'pending',
                            'state_label'                      => $doc->state_label ?? 'معلقة',
                            'insurance_expiry_date'            => $doc->insurance_expiry_date ?? ($doc->expiry_date ? \Carbon\Carbon::parse($doc->expiry_date)->format('Y-m-d') : null),
                            'stamp_expiry_date'                => $doc->stamp_expiry_date,
                            'technical_inspection_expiry_date' => $doc->technical_inspection_expiry_date,
                            'status'                           => $doc->state ?? ($doc->status ?? 'pending'),
                            'feedback'                         => $doc->feedback,
                        ]);
                    }
                }

                return $docs;
            })(),

            // سجل العمليات التاريخي والمراجعات السابقة (Audit Trail)
            'approval_history' => $this->approvals ? $this->approvals
                ->filter(function ($approval) {
                    return !empty($approval->admin_id) || $approval->status !== 'Pending';
                })
                ->map(function ($approval) {
                    return [
                        'id'               => $approval->id,
                        'admin_name'       => $approval->admin?->full_name ?? 'مشرف سابق',
                        'status'           => $approval->status,
                        'rejection_reason' => $approval->rejection_reason,
                        'action_at'        => ($approval->reviewed_at ?? $approval->created_at)
                            ? \Carbon\Carbon::parse($approval->reviewed_at ?? $approval->created_at)->format('Y-m-d H:i:s')
                            : null,
                    ];
                })
                ->values() : [],
        ];
    }
}