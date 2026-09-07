<?php

namespace App\Http\Resources\Api\Parent;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActiveSubscribedChildResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $resolvedState = $this->resource instanceof \App\Models\Shared\SubscriptionRequest 
            ? $this->resource->resolveState() 
            : ($this->resource instanceof \App\Models\Shared\ActiveSubscription ? $this->resource->resolveState() : ['state' => 'active', 'status' => 'active', 'state_label' => 'ساري ومفعل', 'status_text' => 'اشتراك نشط وساري', 'is_active' => true]);

        $reqForSharedFields = $this->resource instanceof \App\Models\Shared\ActiveSubscription
            ? $this->resource->subscriptionRequest
            : $this->resource;

        return [
            'id'          => $this->id,
            'state'       => $resolvedState['state'],
            'state_label' => $resolvedState['state_label'],
            'status'      => $resolvedState['status'],
            'status_text' => $resolvedState['status_text'],
            'is_active'   => $resolvedState['is_active'],
            'total_price' => (float) ($this->total_price ?? 0),
            'notes'       => $this->notes,

            // بيانات السائق
            'driver' => [
                'id'    => $this->driver_id,
                'name'  => $this->driver?->user?->full_name ?? $this->driver?->user?->name ?? 'غير محدد',
                'phone' => $this->driver?->user?->phone_number ?? $this->driver?->user?->phone ?? null,
                'photo' => $this->driver?->user?->profile_photo_url ?? null,
            ],

            // تفاصيل الأطفال
            'children' => $this->whenLoaded('children', function () use ($reqForSharedFields) {
                return $this->children->map(function ($child) use ($reqForSharedFields) {
                    $pivot = $child->pivot;

                    // الاعتماد على العلاقات المباشرة (Eager Loading) لتسريع الأداء وتجنب استعلامات N+1
                    $homeAddr = $child->address;
                    $school   = $child->school;

                    return [
                        'id'      => $child->id,
                        'name'    => $child->full_name ?? $child->name,
                        'photo'   => $child->photo_url ? asset($child->photo_url) : null,
                        'details' => [
                            'subscription_type'  => $reqForSharedFields?->subscription_type,
                            'trip_direction'     => $reqForSharedFields?->trip_direction ?? 'both',
                            'timing'             => $pivot?->timing ?? 'BOTH',
                            'start_date'         => $reqForSharedFields?->start_date,
                            'end_date'           => $reqForSharedFields?->end_date,
                            'working_days_count' => (int) ($reqForSharedFields?->working_days_count ?? 0),
                            'distance_km'        => (float) ($pivot?->distance_km ?? 0),
                            'trip_price'         => (float) ($pivot?->trip_price ?? 0),      // ✅ سعر الرحلة الواحدة
                            'price_per_child'    => (float) ($pivot?->price_per_child ?? 0), // الإجمالي الخاص بالطفل
                        ],
                        'Home' => [
                            'id'        => $homeAddr?->id,
                            'name'      => $homeAddr?->label ?? 'منزل ولي الأمر', // ✅ اسم الحوش (المنزل)
                            'address'   => $homeAddr?->address_line ?? 'عنوان غير متوفر',
                            'latitude'  => (float) ($homeAddr?->lat ?? $homeAddr?->latitude ?? 32.8872),
                            'longitude' => (float) ($homeAddr?->lng ?? $homeAddr?->longitude ?? 13.1913),
                        ],
                        'School' => [
                            'id'        => $school?->id,
                            'name'      => $school?->name ?? 'المدرسة', // ✅ اسم المدرسة
                            'address'   => $school?->address_line ?? $school?->address ?? 'عنوان غير متوفر',
                            'latitude'  => (float) ($school?->lat ?? $school?->latitude ?? 32.8700),
                            'longitude' => (float) ($school?->lng ?? $school?->longitude ?? 13.1800),
                        ],
                    ];
                });
            }),

            'created_at' => $this->created_at ? $this->created_at->toIso8601String() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toIso8601String() : null,
        ];
    }
}