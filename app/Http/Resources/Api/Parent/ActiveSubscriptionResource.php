<?php

namespace App\Http\Resources\Api\Parent;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActiveSubscriptionResource extends JsonResource
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

        // subscription_type/trip_direction/start_date/end_date موجودة فقط على SubscriptionRequest
        // (requests)، فلو المورد active_subscription نمر عبر علاقته للطلب الأصلي.
        $reqForSharedFields = $this->resource instanceof \App\Models\Shared\ActiveSubscription
            ? $this->resource->subscriptionRequest
            : $this->resource;

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

                    return [
                        'id'                      => $child->id,
                        'child_id'                => $child->id,
                        'subscription_id'         => $this->id,
                        'subscription_request_id' => $this->id,
                        'active_subscription_id'  => optional($this->activeSubscriptions?->firstWhere('child_id', $child->id))->id ?? $this->id,
                        'name'                    => $child->full_name ?? $child->name,
                        'photo'                   => $child->photo_url ? asset($child->photo_url) : null,
                        'details' => [
                            'subscription_type'           => $reqForSharedFields?->subscription_type,
                            'trip_direction'              => $reqForSharedFields?->trip_direction ?? 'both',
                            'timing'                      => $pivot?->timing ?? 'BOTH',
                            'start_date'                  => $reqForSharedFields?->start_date,
                            'end_date'                    => $reqForSharedFields?->end_date,
                            'working_days_count'          => (int) ($reqForSharedFields?->working_days_count ?? 0),
                            'distance_km'                 => (float) ($pivot?->distance_km ?? 0),
                            'trip_price'                  => $tripPrice,          // 1. سعر الرحلة الواحدة
                            'price_per_child'             => $rawChildPrice,      // 2. إجمالي المبلغ للطفل قبل التخفيض
                            'discount_percentage'         => $discountPercent,    // 3. نسبة التخفيض %
                            'discount_amount'             => $discountAmt,        // 4. قيمة التخفيض
                            'total_amount_after_discount' => $afterDiscount,      // 5. السعر بعد التخفيض (المطلوب دفعه للطفل)
                            'platform_commission_rate'    => $platformFeePercent, // 6. نسبة عمولة المنصة %
                            'platform_commission_amount'  => $platformFeeAmount,  // 7. قيمة عمولة المنصة
                            'driver_net_price'            => $driverNetPrice,     // 8. إجمالي السعر للسائق بعد التخفيض وخصم نسبة المنصة
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