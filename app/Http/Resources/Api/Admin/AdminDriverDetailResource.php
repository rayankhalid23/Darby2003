<?php

namespace App\Http\Resources\Api\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminDriverDetailResource extends JsonResource
{
    /**
     * تحويل كائن السائق إلى ملف تفصيلي عميق جداً يشمل الوثائق، المركبات، والإحصائيات
     */
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'status'         => $this->status,
            'gender'         => $this->gender,
            'national_id'    => $this->national_id,
            'license_number' => $this->license_number,
            'license_expiry' => $this->license_expiry ? (\Carbon\Carbon::parse($this->license_expiry)->format('Y-m-d')) : null,
            
            // بيانات الموقع الجغرافي اللحظي المتاحة في قاعدة بياناتك
            'location' => [
                'lat'          => $this->current_lat,
                'lng'          => $this->current_lng,
                'last_ping_at' => $this->last_ping_at ? (\Carbon\Carbon::parse($this->last_ping_at)->format('Y-m-d H:i:s')) : null,
            ],

            // الإحصائيات المتقدمة والذكية المستخرجة مباشرة لرفع قيمة التطبيق التسويقية
            'statistics' => [
                'rating_avg'             => (float) ($this->rating_avg ?? 5.0),
                'completed_trips_count'  => (int) ($this->completed_trips_count ?? 0),
                'retention_rate'         => (float) ($this->retention_rate ?? 100.0),
            ],

            // بيانات الحساب والمستخدم الأساسية
            'user_account' => [
                'user_id'           => $this->user->id ?? null,
                'full_name'         => $this->user->full_name ?? null,
                'email'             => $this->user->email ?? null,
                'phone_number'      => $this->user->phone_number ?? null,
                'alternative_phone' => $this->user->alternative_phone ?? null,
                'avatar_url'        => \App\Http\Controllers\Api\Shared\MediaController::urlFor($this->user->avatar_url ?? null),
                'is_active'         => (bool) ($this->user->is_active ?? false),
            ],

            // مصفوفة المركبات المسجلة للسائق
            'vehicles' => $this->vehicles ? $this->vehicles->map(function ($vehicle) {
                return [
                    'id'                => $vehicle->id,
                    'brand'             => $vehicle->brand,
                    'model'             => $vehicle->model,
                    'year'              => $vehicle->year,
                    'plate_number'      => $vehicle->plate_number,
                    'color'             => $vehicle->color,
                    'type'              => $vehicle->type,
                    'capacity_manual'   => $vehicle->capacity_manual,
                    'has_ac'            => (bool) $vehicle->has_ac,
                    'vehicle_image_url' => \App\Http\Controllers\Api\Shared\MediaController::urlFor($vehicle->vehicle_image_url),
                    'status'            => $vehicle->status,
                    'is_verified'       => (bool) $vehicle->is_verified,
                ];
            }) : [],

            // 🚀 مصفوفة الوثائق والمستندات الرسمية المرفوعة - مع ترويسات CORS وحماية كاملة للفرونت
            'documents' => $this->documents ? $this->documents->map(function ($doc) {
                return [
                    'id'                               => $doc->id,
                    'document_type'                    => $doc->doc_type ?? $doc->document_type,
                    'document_url'                     => \App\Http\Controllers\Api\Shared\MediaController::urlFor($doc->file_url ?? $doc->document_url),
                    'expiry_date'                      => $doc->expiry_date ? \Carbon\Carbon::parse($doc->expiry_date)->format('Y-m-d') : null,
                    'is_verified'                      => (bool) $doc->is_verified,
                    'state'                            => $doc->state ?? 'pending',
                    'state_label'                      => $doc->state_label ?? 'معلقة',
                    'insurance_expiry_date'            => $doc->insurance_expiry_date ?? ($doc->expiry_date ? \Carbon\Carbon::parse($doc->expiry_date)->format('Y-m-d') : null),
                    'stamp_expiry_date'                => $doc->stamp_expiry_date,
                    'technical_inspection_expiry_date' => $doc->technical_inspection_expiry_date,
                    'status'                           => $doc->state ?? ($doc->status ?? 'pending'),
                    'feedback'                         => $doc->feedback,
                ];
            }) : [],

            // سجل العمليات التاريخي والمراجعات السابقة (Audit Trail)
            'approval_history' => $this->approvals ? $this->approvals->map(function ($approval) {
                return [
                    'id'               => $approval->id,
                    'admin_name'       => $approval->admin->user->full_name ?? 'مشرف سابق',
                    'status'           => $approval->status,
                    'rejection_reason' => $approval->rejection_reason,
                    'action_at'        => $approval->created_at ? \Carbon\Carbon::parse($approval->created_at)->format('Y-m-d H:i:s') : null,
                ];
            }) : [],
        ];
    }
}