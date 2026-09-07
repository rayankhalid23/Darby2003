<?php

namespace App\Http\Resources\Api\Parent;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionRequestDetailsResource extends JsonResource
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
            : ['state' => 'active', 'status' => 'active', 'state_label' => 'ساري ومفعل', 'status_text' => 'اشتراك نشط وساري', 'is_active' => true];

        $firstChild = $this->relationLoaded('children') ? $this->children?->first() : null;
        $firstPivot = $firstChild?->pivot ?? null;

        $subType = $this->subscription_type ?? $firstPivot?->subscription_type ?? 'multi_day';
        $subTypeLabel = match ($subType) {
            'single_day' => 'اشتراك يوم واحد',
            'multi_day'  => 'اشتراك عدة أيام',
            'monthly'    => 'اشتراك شهري',
            'term'       => 'اشتراك فصل دراسي',
            'yearly'     => 'اشتراك سنوي',
            default      => $subType,
        };

        $tripDir = $this->trip_direction ?? $firstPivot?->trip_direction ?? $firstPivot?->direction ?? 'both';
        $tripDirLabel = match ($tripDir) {
            'go', 'one_way_morning'     => 'ذهاب صباحي فقط',
            'return', 'one_way_evening' => 'عودة مسائية فقط',
            'both', 'two_way'           => 'ذهاب وإياب',
            default                     => $tripDir,
        };

        $startDateStr = $this->start_date ? (is_string($this->start_date) ? $this->start_date : $this->start_date->format('Y-m-d')) : $firstPivot?->start_date;
        $endDateStr   = $this->end_date ? (is_string($this->end_date) ? $this->end_date : $this->end_date->format('Y-m-d')) : $firstPivot?->end_date;

        $homeLabel = $this->home_label ?? $firstPivot?->home_label ?? optional($firstChild?->address)->label ?? 'منزل ولي الأمر';
        $homeLat   = (float) ($this->home_lat ?? $firstPivot?->home_lat ?? optional($firstChild?->address)->lat ?? 32.8872);
        $homeLng   = (float) ($this->home_lng ?? $firstPivot?->home_lng ?? optional($firstChild?->address)->lng ?? 13.1913);

        return [
            'id'                          => $this->id,
            'subscription_id'             => $this->id,
            'subscription_request_id'     => $this->id,
            'state'                       => $resolvedState['state'],
            'state_label'                 => $resolvedState['state_label'],
            'status'                      => $resolvedState['status'],
            'status_text'                 => $resolvedState['status_text'],
            'is_active'                   => $resolvedState['is_active'],
            'total_price'                 => (float) ($this->total_price ?? 0),
            'discount_amount'             => (float) ($this->discount_amount ?? 0),
            'total_amount_after_discount' => (float) ($this->total_amount_after_discount ?? max(0, (float)($this->total_price ?? 0) - (float)($this->discount_amount ?? 0))),
            'notes'                       => $this->notes,

            // ── الحقول المشتركة لطلب الاشتراك (العائلة) ──
            'subscription' => [
                'type'               => $subType,
                'type_label'         => $subTypeLabel,
                'direction'          => $tripDir,
                'direction_label'    => $tripDirLabel,
                'start_date'         => $startDateStr,
                'end_date'           => $endDateStr,
                'working_days_count' => (int) ($this->working_days_count ?? 0),
            ],

            // ── عنوان المنزل المشترك ──
            'home_address' => [
                'label'     => $homeLabel,
                'address'   => $homeLabel,
                'latitude'  => $homeLat,
                'longitude' => $homeLng,
                'lat'       => $homeLat,
                'lng'       => $homeLng,
            ],

            // بيانات السائق
            'driver' => [
                'id'    => $this->driver_id,
                'name'  => $this->driver?->user?->full_name ?? $this->driver?->user?->name ?? 'غير محدد',
                'phone' => $this->driver?->user?->phone_number ?? $this->driver?->user?->phone ?? null,
                'photo' => $this->driver?->user?->profile_photo_url ?? null,
            ],

            // عدد الأطفال
            'children_count' => (int) ($this->children_count ?? ($this->children?->count() ?: 1)),

            // تفاصيل الأطفال
            'children' => $this->whenLoaded('children', function () use ($homeLabel, $homeLat, $homeLng, $subType, $tripDir, $startDateStr, $endDateStr) {
                return $this->children->map(function ($child) use ($homeLabel, $homeLat, $homeLng, $subType, $tripDir, $startDateStr, $endDateStr) {
                    $pivot = $child->pivot;
                    $school   = $child->school;  

                    $rawChildPrice  = (float) ($pivot?->price_per_child ?? $pivot?->trip_price ?? 0);
                    $tripPrice      = (float) ($pivot?->trip_price ?? $rawChildPrice);
                    $discountAmt    = (float) ($pivot?->discount_amount ?? 0);
                    $afterDiscount  = (float) ($pivot?->total_amount_after_discount ?? max(0, $rawChildPrice - $discountAmt));
                    if ($afterDiscount <= 0 && $rawChildPrice > 0) {
                        $afterDiscount = max(0, $rawChildPrice - $discountAmt);
                    }
                    $discountPercent = $rawChildPrice > 0 ? round(($discountAmt / $rawChildPrice) * 100, 2) : 0.0;

                    $driverNetPrice = (float) ($pivot?->driver_net_price ?? 0);
                    if ($driverNetPrice <= 0 && $afterDiscount > 0) {
                        $driverNetPrice = round($afterDiscount * (1 - \App\Models\Shared\PricingSetting::commissionRateFraction()), 2);
                    }
                    $platformFeeAmount  = max(0, round($afterDiscount - $driverNetPrice, 2));
                    $platformFeePercent = $afterDiscount > 0 ? round(($platformFeeAmount / $afterDiscount) * 100, 2) : round(\App\Models\Shared\PricingSetting::commissionRateFraction() * 100, 2);

                    $schoolName = $pivot?->school_label ?? $school?->name ?? 'المدرسة';
                    $schoolLat  = (float) ($pivot?->school_lat ?? $school?->lat ?? $school?->latitude ?? 32.8700);
                    $schoolLng  = (float) ($pivot?->school_lng ?? $school?->lng ?? $school?->longitude ?? 13.1800);

                    return [
                        'id'                      => $child->id,
                        'child_id'                => $child->id,
                        'subscription_id'         => $this->id,
                        'subscription_request_id' => $this->id,
                        'active_subscription_id'  => optional($this->activeSubscriptions?->firstWhere('child_id', $child->id))->id ?? $this->id,
                        'name'                    => $child->full_name ?? $child->name,
                        'photo'                   => $child->photo_url ? asset($child->photo_url) : null,
                        'photo_url'               => $child->photo_url ? asset($child->photo_url) : null,
                        'age'                     => $child->age,
                        'birth_date'              => $child->birth_date?->toDateString(),
                        'gender'                  => $child->gender,
                        'grade'                   => $child->grade,
                        'grade_label'             => $child->grade ? 'الصف ' . $child->grade : 'غير محدد',
                        'school_stage'            => $child->school_stage,
                        'school_stage_label'      => $child->school_stage_label,
                        'medical_notes'           => $child->medical_notes,

                        // مدرسة الطفل
                        'school' => [
                            'id'        => $school?->id,
                            'name'      => $schoolName,
                            'address'   => $school?->address_line ?? $school?->address ?? 'عنوان غير متوفر',
                            'latitude'  => $schoolLat,
                            'longitude' => $schoolLng,
                            'lat'       => $schoolLat,
                            'lng'       => $schoolLng,
                        ],

                        'pricing' => [
                            'trip_price'                  => $tripPrice,
                            'original_price'              => $rawChildPrice,
                            'price_per_child'             => $rawChildPrice,
                            'discount_percentage'         => $discountPercent,
                            'discount_amount'             => $discountAmt,
                            'total_amount_after_discount' => $afterDiscount,
                            'platform_commission_rate'    => $platformFeePercent,
                            'platform_commission_amount'  => $platformFeeAmount,
                            'driver_net_price'            => $driverNetPrice,
                        ],

                        'details' => [
                            'subscription_type'           => $pivot?->subscription_type ?? $subType,
                            'trip_direction'              => $pivot?->trip_direction ?? $pivot?->direction ?? $tripDir,
                            'timing'                      => $pivot?->timing ?? 'BOTH',
                            'start_date'                  => $pivot?->start_date ?? $startDateStr,
                            'end_date'                    => $pivot?->end_date ?? $endDateStr,
                            'working_days_count'          => (int) ($subReq->working_days_count ?? 0),
                            'distance_km'                 => (float) ($pivot?->distance_km ?? 0),
                            'trip_price'                  => $tripPrice,
                            'price_per_child'             => $rawChildPrice,
                            'discount_percentage'         => $discountPercent,
                            'discount_amount'             => $discountAmt,
                            'total_amount_after_discount' => $afterDiscount,
                            'platform_commission_rate'    => $platformFeePercent,
                            'platform_commission_amount'  => $platformFeeAmount,
                            'driver_net_price'            => $driverNetPrice,
                        ],

                        // دعم التوافق العكسي
                        'Home' => [
                            'name'      => $homeLabel,
                            'address'   => $homeLabel,
                            'latitude'  => $homeLat,
                            'longitude' => $homeLng,
                            'lat'       => $homeLat,
                            'lng'       => $homeLng,
                        ],
                        'School' => [
                            'id'        => $school?->id,
                            'name'      => $schoolName,
                            'address'   => $school?->address_line ?? $school?->address ?? 'عنوان غير متوفر',
                            'latitude'  => $schoolLat,
                            'longitude' => $schoolLng,
                            'lat'       => $schoolLat,
                            'lng'       => $schoolLng,
                        ],
                    ];
                });
            }),

            'created_at' => $this->created_at ? $this->created_at->toIso8601String() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toIso8601String() : null,
        ];
    }
}