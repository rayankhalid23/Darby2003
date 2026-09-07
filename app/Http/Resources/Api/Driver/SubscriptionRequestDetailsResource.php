<?php

namespace App\Http\Resources\Api\Driver;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionRequestDetailsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (!$this->resource) {
            return [];
        }

        // دمج مرن لجلب بيانات ولي الأمر (سواء كان User مباشرة أو عبر علاقة parent)
        $parentUser = optional($this->parent)->user ?? $this->parent;

        $subReq = $this->subscriptionRequest ?? $this;

        // جلب الأطفال: سواء كانت علاقة مجموعة (children) أو طفل منفرد (child)
        $childrenList = collect();
        if ($this->relationLoaded('children') && $this->children) {
            $childrenList = $this->children;
        } elseif (isset($this->child) && $this->child) {
            $childrenList = collect([$this->child]);
        }

        // حساب السعر الإجمالي وصافي السائق
        $rawTotal = (float) ($this->total_price ?? $this->price ?? optional($this->subscriptionRequest)->total_price ?? 0);
        $totalDiscount = (float) ($this->discount_amount ?? optional($this->subscriptionRequest)->discount_amount ?? 0);
        $totalAfterDiscount = (float) ($this->total_amount_after_discount ?? optional($this->subscriptionRequest)->total_amount_after_discount ?? max(0, $rawTotal - $totalDiscount));

        $netTotalAmount = 0.0;
        if ($childrenList->isNotEmpty()) {
            $netTotalAmount = (float) $childrenList->sum(function ($child) {
                $pivot = $child->pivot ?? null;
                if (!$pivot) return 0;
                $net = (float) ($pivot->driver_net_price ?? 0);
                if ($net <= 0) {
                    $raw = (float) ($pivot->price_per_child ?? $pivot->trip_price ?? 0);
                    $disc = (float) ($pivot->discount_amount ?? 0);
                    $afterDisc = (float) ($pivot->total_amount_after_discount ?? max(0, $raw - $disc));
                    $net = round($afterDisc * (1 - \App\Models\Shared\PricingSetting::commissionRateFraction()), 2);
                }
                return $net;
            });
        }
        if ($netTotalAmount <= 0) {
            $netTotalAmount = round($totalAfterDiscount * (1 - \App\Models\Shared\PricingSetting::commissionRateFraction()), 2);
        }

        $resolvedState = $subReq instanceof \App\Models\Shared\SubscriptionRequest 
            ? $subReq->resolveState() 
            : ($this->resource instanceof \App\Models\Shared\SubscriptionRequest ? $this->resource->resolveState() : ['state' => 'active', 'status' => 'active', 'state_label' => 'ساري ومفعل', 'status_text' => 'اشتراك نشط وساري', 'is_active' => true]);

        $firstChild = $childrenList->first();
        $firstPivot = $firstChild?->pivot ?? null;

        $subType = $subReq->subscription_type ?? $this->subscription_type ?? $firstPivot?->subscription_type ?? 'multi_day';
        $subTypeLabel = match ($subType) {
            'single_day' => 'اشتراك يوم واحد',
            'multi_day'  => 'اشتراك عدة أيام',
            'monthly'    => 'اشتراك شهري',
            'term'       => 'اشتراك فصل دراسي',
            'yearly'     => 'اشتراك سنوي',
            default      => $subType,
        };

        $tripDir = $subReq->trip_direction ?? $this->trip_direction ?? $firstPivot?->trip_direction ?? $firstPivot?->direction ?? 'both';
        $tripDirLabel = match ($tripDir) {
            'go', 'one_way_morning'     => 'ذهاب صباحي فقط',
            'return', 'one_way_evening' => 'عودة مسائية فقط',
            'both', 'two_way'           => 'ذهاب وإياب',
            default                     => $tripDir,
        };

        $startDateStr = $subReq->start_date ? (is_string($subReq->start_date) ? $subReq->start_date : $subReq->start_date->format('Y-m-d')) : $firstPivot?->start_date;
        $endDateStr   = $subReq->end_date ? (is_string($subReq->end_date) ? $subReq->end_date : $subReq->end_date->format('Y-m-d')) : $firstPivot?->end_date;

        $homeLabel = $subReq->home_label ?? $this->home_label ?? $firstPivot?->home_label ?? optional($firstChild?->address)->label ?? 'منزل ولي الأمر';
        $homeLat   = (float) ($subReq->home_lat ?? $this->home_lat ?? $firstPivot?->home_lat ?? optional($firstChild?->address)->lat ?? 0);
        $homeLng   = (float) ($subReq->home_lng ?? $this->home_lng ?? $firstPivot?->home_lng ?? optional($firstChild?->address)->lng ?? 0);

        return [
            'id'                      => $this->id,
            'subscription_id'         => $this->id,
            'subscription_request_id' => $this->id,
            'state'                   => $resolvedState['state'],
            'state_label'             => $resolvedState['state_label'],
            'status'                  => [
                'value' => $resolvedState['status'],
                'label' => $resolvedState['state_label'],
            ],
            'status_value'            => $resolvedState['status'],
            'status_text'             => $resolvedState['status_text'],
            'is_active'               => $resolvedState['is_active'],
            'notes'                   => $this->notes ?? $this->general_notes ?? optional($this->subscriptionRequest)->notes ?? null, 
            'total_amount'            => round($netTotalAmount, 2), // صافي مستحقات السائق للطلب بالكامل
            'driver_net_total'        => round($netTotalAmount, 2),
            'original_total'          => round($rawTotal, 2),
            'discount_total'          => round($totalDiscount, 2),
            'total_after_discount'    => round($totalAfterDiscount, 2),
            'currency'                => 'د.ل', 
            'children_count'          => (int) ($this->children_count ?? ($childrenList->count() ?: 1)),

            // ── الحقول المشتركة لطلب الاشتراك (العائلة) ──
            'subscription' => [
                'type'               => $subType,
                'type_label'         => $subTypeLabel,
                'direction'          => $tripDir,
                'direction_label'    => $tripDirLabel,
                'start_date'         => $startDateStr,
                'end_date'           => $endDateStr,
                'working_days_count' => (int) ($subReq->working_days_count ?? 0),
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

            'parent' => [
                'id'     => optional($parentUser)->id,
                'name'   => optional($parentUser)->full_name ?? optional($parentUser)->name ?? 'غير محدد',
                'phone'  => optional($parentUser)->phone_number ?? optional($parentUser)->phone ?? null,
                'email'  => optional($parentUser)->email ?? null,
                'avatar' => optional($parentUser)->avatar_url ?? optional($parentUser)->photo_url ?? null,
            ],

            'children' => $childrenList->map(function ($child) use ($subReq, $homeLabel, $homeLat, $homeLng, $subType, $tripDir, $startDateStr, $endDateStr) {
                $pivot   = $child->pivot ?? null;
                $school  = optional($child->school ?? $this->school);

                $rawChildPrice = (float) ($pivot?->price_per_child ?? $pivot?->trip_price ?? 0);
                $tripPrice     = (float) ($pivot?->trip_price ?? $rawChildPrice);
                $discountAmt   = (float) ($pivot?->discount_amount ?? 0);
                $afterDiscount = (float) ($pivot?->total_amount_after_discount ?? max(0, $rawChildPrice - $discountAmt));
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

                $schoolName = $pivot?->school_label ?? $school->name ?? 'المدرسة';
                $schoolLat  = (float) ($pivot?->school_lat ?? $school->lat ?? $school->latitude ?? 0);
                $schoolLng  = (float) ($pivot?->school_lng ?? $school->lng ?? $school->longitude ?? 0);

                return [
                    'id'                 => $child->id,
                    'child_id'           => $child->id,
                    'name'               => $child->full_name ?? $child->name,
                    'gender'             => $child->gender,
                    'age'                => $child->age,
                    'birth_date'         => $child->birth_date?->toDateString(),
                    'grade'              => $child->grade ?? $child->class_name ?? 'غير محدد',
                    'grade_label'        => $child->grade ? 'الصف ' . $child->grade : 'غير محدد',
                    'school_stage'       => $child->school_stage,
                    'school_stage_label' => $child->school_stage_label,
                    'photo_url'          => $child->photo_url ? asset($child->photo_url) : null,
                    'medical_notes'      => $child->medical_notes,

                    'notes' => [
                        'child_notes' => $child->medical_notes ?? $pivot?->child_notes ?? null,
                    ],

                    'school' => [
                        'id'        => $school->id,
                        'name'      => $schoolName,
                        'address'   => $school->address_line ?? $school->address ?? 'عنوان غير متوفر',
                        'lat'       => $schoolLat,
                        'lng'       => $schoolLng,
                        'latitude'  => $schoolLat,
                        'longitude' => $schoolLng,
                    ],

                    'pricing' => [
                        'trip_price'                  => $tripPrice,          // سعر الرحلة الواحدة
                        'original_price'              => $rawChildPrice,      // إجمالي المبلغ للطفل قبل التخفيض
                        'price_per_child'             => $rawChildPrice,      // إجمالي المبلغ للطفل
                        'discount_percentage'         => $discountPercent,    // نسبة التخفيض %
                        'discount_amount'             => $discountAmt,        // قيمة التخفيض
                        'total_amount_after_discount' => $afterDiscount,      // السعر بعد التخفيض
                        'platform_commission_rate'    => $platformFeePercent, // نسبة عمولة المنصة %
                        'platform_commission_amount'  => $platformFeeAmount,  // قيمة عمولة المنصة
                        'platform_commission'         => $platformFeeAmount,
                        'driver_net_price'            => $driverNetPrice,     // صافي السائق للطفل
                        'total_price'                 => $driverNetPrice,
                    ],

                    'subscription_period' => [
                        'start_date'         => $pivot?->start_date ?? $startDateStr,
                        'end_date'           => $pivot?->end_date ?? $endDateStr,
                        'working_days_count' => (int) ($subReq->working_days_count ?? 20),
                    ],

                    'trip_details' => [
                        'subscription_type' => $pivot?->subscription_type ?? $subType,
                        'trip_direction'    => $pivot?->trip_direction ?? $tripDir,
                        'timing'            => $pivot?->timing ?? 'BOTH',
                    ],

                    'home' => [
                        'address'   => $homeLabel,
                        'lat'       => $homeLat,
                        'lng'       => $homeLng,
                        'latitude'  => $homeLat,
                        'longitude' => $homeLng,
                    ],
                ];
            })->values(),

            'created_at'           => $this->created_at?->toIso8601String(),
            'created_at_formatted' => $this->created_at?->format('Y-m-d H:i'),
        ];
    }
}