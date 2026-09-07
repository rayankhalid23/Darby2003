<?php

namespace App\Http\Resources\Api\Parent;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ChildResource extends JsonResource
{
    /**
     * تحويل كائن الطفل إلى مصفوفة JSON منسقة واحترافية.
     */
    public function toArray(Request $request): array
    {
        $rawPhoto = (!empty($this->photo_url) && !empty(trim($this->photo_url))) ? trim($this->photo_url) : null;
        return [
            'id'                  => $this->id,
            'school_id'           => $this->school_id,
            'address_id'          => $this->address_id,
            
            'full_name'           => $this->full_name,
            'gender'              => $this->gender,
            'notification_radius' => $this->notification_radius,
            
            'birth_date'          => $this->birth_date ? $this->birth_date->format('Y-m-d') : null,
            'age'                 => $this->age,
            'grade'               => $this->grade,
            'school_stage'        => $this->school_stage,
            'school_stage_label'  => $this->school_stage_label,

           // الحل الجذري والآمن للصورة الافتراضية أو المرفوعة
    'photo_url'           => $rawPhoto ? Storage::url($rawPhoto) : asset('assets/images/default-child.png'),
            'medical_notes'       => $this->medical_notes ?? 'لا توجد ملاحظات طبية',
            'qr_code_token'       => $this->qr_code_token,
            
            // =======================================================
            // تضمين العلاقات بشكل ذكي وآمن (Conditional Loading)
            // =======================================================
            
            'school' => $this->whenLoaded('school', function() {
                return [
                    'id'           => $this->school->id,
                    'name'         => $this->school->name,
                    'address_text' => $this->school->address_text ?? $this->school->address,
                ];
            }),

            'address' => $this->whenLoaded('address', function() {
                return [
                    'id'  => $this->address->id,
                    'label' => $this->address->label,
                    'lat' => $this->address->lat,
                    'lng' => $this->address->lng,
                ];
            }),

            // ابحث عن كتلة الـ 'logistics' وقم بتحديثها لتصبح هكذا:

'logistics' => $this->whenLoaded('logistics', function() {
    return [
        'preferred_time_slot' => $this->logistics->preferred_time_slot,
        'pickup_time'         => $this->logistics->pickup_time,   // مضاف سابقاً
        'dropoff_time'        => $this->logistics->dropoff_time,  // مضاف سابقاً
        'is_active'           => (bool) $this->logistics->is_active,
    ];
}),
        ];
    }
}