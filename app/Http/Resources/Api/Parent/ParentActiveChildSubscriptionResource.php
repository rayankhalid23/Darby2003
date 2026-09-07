<?php

namespace App\Http\Resources\Api\Parent;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParentActiveChildSubscriptionResource extends JsonResource
{
    /**
     * تحويل بيانات اشتراك كل طفل على حدة لولي الأمر
     */
    public function toArray(Request $request): array
    {
        $subscriptionRequest = $this->resource['subscriptionRequest'] ?? $this->resource;
        $child = $this->resource['child'] ?? $this->resource;

        $pivot = $child->pivot ?? null;
        $homeAddr = $child->address ?? null;
        $school = $child->school ?? null;

        $activeSubId = $this->resource['activeSubId'] ?? optional($subscriptionRequest->activeSubscriptions?->firstWhere('child_id', $child?->id))->id ?? $subscriptionRequest->id;

        // النسبة من pricing_settings لا مثبتة: كانت 0.92/8.0 هنا تعني أن صافي
        // السائق والعمولة المعروضين لولي الأمر لا يتغيران أبداً مهما عدّلت الإدارة.
        $commissionFraction = \App\Models\Shared\PricingSetting::commissionRateFraction();

        $childDetails = [
            'id'                      => $child->id,
            'child_id'                => $child->id,
            'subscription_id'         => $subscriptionRequest->id,
            'subscription_request_id' => $subscriptionRequest->id,
            'active_subscription_id'  => $activeSubId,
            'name'                    => $child->full_name ?? $child->name,
            'photo'                   => $child->photo_url ? asset($child->photo_url) : null,
            'gender'                  => $child->gender ?? null,
            'age'                     => $child->age ?? null,
            'grade'                   => $child->grade ?? $child->class_name ?? 'غير محدد',
            'notes'                   => $child->medical_notes ?? null,
            'details' => [
                'subscription_type'           => $pivot?->subscription_type ?? $subscriptionRequest->subscription_type,
                'trip_direction'              => $subscriptionRequest->trip_direction ?? 'both',
                'timing'                      => $pivot?->timing ?? 'BOTH',
                'start_date'                  => $pivot?->start_date ? (string) $pivot->start_date : ($subscriptionRequest->start_date ? (string) $subscriptionRequest->start_date : null),
                'end_date'                    => $pivot?->end_date ? (string) $pivot->end_date : ($subscriptionRequest->end_date ? (string) $subscriptionRequest->end_date : null),
                'working_days_count'          => (int) ($subscriptionRequest->working_days_count ?? 0),
                'distance_km'                 => (float) ($pivot?->distance_km ?? $subscriptionRequest->distance_km ?? 0),
                'trip_price'                  => (float) ($pivot?->trip_price ?? $subscriptionRequest->trip_price ?? 0),
                'price_per_child'             => (float) ($pivot?->price_per_child ?? $pivot?->trip_price ?? $subscriptionRequest->total_price ?? 0),
                'discount_percentage'         => (float) ($pivot?->price_per_child > 0 ? round((($pivot->discount_amount ?? 0) / $pivot->price_per_child) * 100, 2) : 0),
                'discount_amount'             => (float) ($pivot?->discount_amount ?? 0),
                'total_amount_after_discount' => (float) ($pivot?->total_amount_after_discount ?? $pivot?->price_per_child ?? $subscriptionRequest->total_amount_after_discount ?? $subscriptionRequest->total_price ?? 0),
                'platform_commission_rate'    => round($commissionFraction * 100, 2),
                'platform_commission_amount'  => (float) max(0, round(($pivot?->total_amount_after_discount ?? $pivot?->price_per_child ?? 0) - ($pivot?->driver_net_price ?? (($pivot?->total_amount_after_discount ?? $pivot?->price_per_child ?? 0) * (1 - $commissionFraction))), 2)),
                'driver_net_price'            => (float) ($pivot?->driver_net_price ?? round(($pivot?->total_amount_after_discount ?? $pivot?->price_per_child ?? 0) * (1 - $commissionFraction), 2)),
                'total_price'                 => (float) ($pivot?->total_amount_after_discount ?? $pivot?->price_per_child ?? $subscriptionRequest->total_amount_after_discount ?? $subscriptionRequest->total_price ?? 0),
            ],
            'Home' => [
                'id'        => $homeAddr?->id,
                'name'      => $homeAddr?->label ?? 'منزل ولي الأمر',
                'address'   => $homeAddr?->address_line ?? $homeAddr?->label ?? 'عنوان غير متوفر',
                'latitude'  => (float) ($homeAddr?->lat ?? $homeAddr?->latitude ?? 32.8872),
                'longitude' => (float) ($homeAddr?->lng ?? $homeAddr?->longitude ?? 13.1913),
            ],
            'School' => [
                'id'        => $school?->id,
                'name'      => $school?->name ?? 'المدرسة',
                'address'   => $school?->address_line ?? $school?->address ?? 'عنوان غير متوفر',
                'latitude'  => (float) ($school?->lat ?? $school?->latitude ?? 32.8700),
                'longitude' => (float) ($school?->lng ?? $school?->longitude ?? 13.1800),
            ],
        ];

        $matchingActiveSub = optional($subscriptionRequest->activeSubscriptions)->firstWhere('child_id', $child?->id);
        $resolvedState = $subscriptionRequest instanceof \App\Models\Shared\SubscriptionRequest 
            ? $subscriptionRequest->resolveState($child, $matchingActiveSub) 
            : ['state' => 'active', 'status' => 'active', 'state_label' => 'ساري ومفعل', 'status_text' => 'اشتراك نشط وساري', 'is_active' => true];

        return [
            'id'                      => (int) $activeSubId,
            'subscription_id'         => (int) $subscriptionRequest->id,
            'subscription_request_id' => (int) $subscriptionRequest->id,
            'active_subscription_id'  => (int) $activeSubId,
            'state'                   => $resolvedState['state'],
            'state_label'             => $resolvedState['state_label'],
            'status'                  => $resolvedState['status'],
            'status_text'             => $resolvedState['status_text'],
            'is_active'               => $resolvedState['is_active'],
            'subscription_number'     => $subscriptionRequest->subscription_number ?? null,
            'total_price'             => (float) ($pivot?->total_amount_after_discount ?? $pivot?->price_per_child ?? $subscriptionRequest->total_price ?? 0),
            'notes'                   => $subscriptionRequest->notes,

            // بيانات السائق
            'driver' => [
                'id'    => $subscriptionRequest->driver_id,
                'name'  => $subscriptionRequest->driver?->user?->full_name ?? $subscriptionRequest->driver?->user?->name ?? 'غير محدد',
                'phone' => $subscriptionRequest->driver?->user?->phone_number ?? $subscriptionRequest->driver?->user?->phone ?? null,
                'photo' => $subscriptionRequest->driver?->user?->profile_photo_url ?? null,
            ],

            // بيانات الطفل الفردي
            'child'      => $childDetails,

            'created_at' => $subscriptionRequest->created_at ? $subscriptionRequest->created_at->toIso8601String() : null,
            'updated_at' => $subscriptionRequest->updated_at ? $subscriptionRequest->updated_at->toIso8601String() : null,
        ];
    }
}
