<?php

namespace App\Http\Resources\Api\Driver;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        if (!$this->resource) {
            return [];
        }

        $parentUser = optional(optional($this->parent)->user);

        // حساب أسعار الأطفال وصافي السائق بدقة مع Fallback للسجلات القديمة
        $totalRawPrice = (float) ($this->total_price ?? 0);
        $totalDiscount = (float) ($this->discount_amount ?? 0);
        $totalAfterDiscount = (float) ($this->total_amount_after_discount ?? max(0, $totalRawPrice - $totalDiscount));
        
        $commissionFraction = \App\Models\Shared\PricingSetting::commissionRateFraction();

        $netTotalAmount = 0;
        if ($this->relationLoaded('children') && $this->children) {
            $netTotalAmount = (float) $this->children->sum(function ($child) use ($commissionFraction) {
                $pivot = $child->pivot ?? null;
                if (!$pivot) return 0;
                $net = (float) ($pivot->driver_net_price ?? 0);
                if ($net <= 0) {
                    $raw = (float) ($pivot->price_per_child ?? $pivot->trip_price ?? 0);
                    $disc = (float) ($pivot->discount_amount ?? 0);
                    $afterDisc = (float) ($pivot->total_amount_after_discount ?? max(0, $raw - $disc));
                    $net = round($afterDisc * (1 - $commissionFraction), 2);
                }
                return $net;
            });
        } else {
            // النسبة تُقرأ من pricing_settings لا تُثبَّت هنا: تثبيت 0.92 كان يعني
            // أن صافي السائق المعروض لا يتغير لو عدّلت الإدارة نسبة العمولة.
            $netTotalAmount = round(
                $totalAfterDiscount * (1 - \App\Models\Shared\PricingSetting::commissionRateFraction()),
                2
            );
        }

        $resolvedState = $this->resource instanceof \App\Models\Shared\SubscriptionRequest
            ? $this->resource->resolveState()
            : ['state' => 'active', 'status' => 'active', 'state_label' => 'ساري ومفعل', 'status_text' => 'اشتراك نشط وساري', 'is_active' => true];

        $firstPivot = $this->relationLoaded('children') && $this->children
            ? $this->children->first()?->pivot
            : null;
        $summaryStartDate = $this->start_date ?? null;
        $summaryWorkingDays = (int) ($this->working_days_count ?? 0);

        return [
            'id'                      => $this->id,
            'subscription_id'         => $this->id,
            'subscription_request_id' => $this->id,
            'state'                   => $resolvedState['state'],
            'state_label'             => $resolvedState['state_label'],
            // status نص خالص (مثل 'pending') وفق العقد الموثّق في FRONTEND_DRIVER_API_GUIDE؛
            // التفاصيل الموسّعة متاحة عبر state/state_label/status_text بلا الحاجة لكائن متداخل.
            'status'                  => $resolvedState['status'],
            'status_value'            => $resolvedState['status'],
            'status_text'             => $resolvedState['status_text'],
            'is_active'               => $resolvedState['is_active'],
            'notes'                   => $this->notes ?? $this->general_notes ?? null,
            // ملخص على مستوى الطلب كله (كل الأطفال) — راجع أيضاً subscription_period
            // داخل كل طفل بمصفوفة children لتفاصيله الدقيقة الفردية.
            'start_date'              => $summaryStartDate,
            'working_days_count'      => $summaryWorkingDays,
            'total_amount'            => round($netTotalAmount, 2), // صافي مستحقات السائق للطلب
            'driver_net_total'       => round($netTotalAmount, 2),
            'original_total'         => round($totalRawPrice, 2),
            'discount_total'         => round($totalDiscount, 2),
            'total_after_discount'   => round($totalAfterDiscount, 2),
            'currency'               => 'د.ل', 
            'children_count'         => (int) ($this->children_count ?? ($this->relationLoaded('children') ? $this->children->count() : 1)),

            'parent' => [
                'id'       => optional($this->parent)->id,
                'name'     => $parentUser->full_name ?? $parentUser->name ?? 'غير محدد',
                'phone'    => $parentUser->phone_number ?? $parentUser->phone ?? null,
                'email'    => $parentUser->email ?? null,
                'avatar'   => $parentUser->avatar_url ?? null,
            ],

            'driver' => (function () {
                $driverUser = optional(optional($this->driver)->user);
                return [
                    'id'     => optional($this->driver)->id,
                    'name'   => $driverUser->full_name ?? $driverUser->name ?? 'غير محدد',
                    'phone'  => $driverUser->phone_number ?? $driverUser->phone ?? null,
                    'avatar' => $driverUser->avatar_url ?? null,
                ];
            })(),

            'children' => $this->whenLoaded('children', function () {
                $reqStartDate = $this->start_date;
                $reqEndDate = $this->end_date;
                $reqSubType = $this->subscription_type;
                $reqTripDirection = $this->trip_direction;
                $reqWorkingDays = $this->working_days_count;

                return $this->children->map(function ($child) use ($reqStartDate, $reqEndDate, $reqSubType, $reqTripDirection, $reqWorkingDays) {
                    $pivot   = $child->pivot ?? null;
                    $school  = optional($child->school);
                    $address = optional($child->address);

                    $rawChildPrice = (float) ($pivot->price_per_child ?? $pivot->trip_price ?? 0);
                    $tripPrice      = (float) ($pivot->trip_price ?? $rawChildPrice);
                    $discountAmt    = (float) ($pivot->discount_amount ?? 0);
                    $afterDiscount  = (float) ($pivot->total_amount_after_discount ?? max(0, $rawChildPrice - $discountAmt));
                    if ($afterDiscount <= 0 && $rawChildPrice > 0) {
                        $afterDiscount = max(0, $rawChildPrice - $discountAmt);
                    }
                    $discountPercent = $rawChildPrice > 0 ? round(($discountAmt / $rawChildPrice) * 100, 2) : 0.0;

                    // النسبة من pricing_settings لا مثبتة، كي يتبع صافي السائق المعروض
                    // أي تعديل تجريه الإدارة على نسبة العمولة.
                    $commissionFraction = \App\Models\Shared\PricingSetting::commissionRateFraction();
                    $driverNetPrice = (float) ($pivot->driver_net_price ?? 0);
                    if ($driverNetPrice <= 0 && $afterDiscount > 0) {
                        $driverNetPrice = round($afterDiscount * (1 - $commissionFraction), 2);
                    }
                    $platformFeeAmount  = max(0, round($afterDiscount - $driverNetPrice, 2));
                    $platformFeePercent = $afterDiscount > 0
                        ? round(($platformFeeAmount / $afterDiscount) * 100, 2)
                        : round($commissionFraction * 100, 2);

                    $activeSubId = optional($this->activeSubscriptions?->firstWhere('child_id', $child->id))->id;

                    return [
                        'id'                      => $child->id,
                        'child_id'                => $child->id,
                        'subscription_id'         => $this->id,
                        'subscription_request_id' => $this->id,
                        'active_subscription_id'  => $activeSubId ?? $this->id,
                        // كائن متداخل يطابق شكل نقاط الاشتراك النشط الأخرى في الـ API
                        // (id هو معرّف صف active_subscriptions الفعلي لهذا الطفل، لا الطلب).
                        'subscription'            => ['id' => $activeSubId],
                        'name'                    => $child->full_name ?? $child->name,
                        'gender'                  => $child->gender,
                        'age'                     => $child->age,
                        'grade'                   => $child->grade ?? $child->class_name ?? 'غير محدد',
                        'photo_url'               => $child->photo_url,

                        'notes' => [
                            'child_notes' => $child->medical_notes ?? null,
                        ],

                        'pricing' => [
                            'trip_price'                  => $tripPrice,          // 1. سعر الرحلة الواحدة
                            'original_price'              => $rawChildPrice,      // 2. إجمالي المبلغ للطفل قبل التخفيض
                            'price_per_child'             => $rawChildPrice,      // 2. إجمالي المبلغ للطفل
                            'discount_percentage'         => $discountPercent,    // 3. نسبة التخفيض %
                            'discount_amount'             => $discountAmt,        // 4. قيمة التخفيض
                            'total_amount_after_discount' => $afterDiscount,      // 5. السعر بعد التخفيض
                            'platform_commission_rate'    => $platformFeePercent, // 6. نسبة عمولة المنصة %
                            'platform_commission_amount'  => $platformFeeAmount,  // 7. قيمة عمولة المنصة
                            'platform_commission'         => $platformFeeAmount,
                            'driver_net_price'            => $driverNetPrice,     // 8. إجمالي السعر للسائق بعد التخفيض وعمولة المنصة
                            'total_price'                 => $driverNetPrice,     // صافي السائق
                        ],

                        'subscription_period' => [
                            'start_date'         => $reqStartDate,
                            'end_date'           => $reqEndDate,
                            'working_days_count' => (int) ($reqWorkingDays ?? 0),
                        ],

                        'trip_details' => [
                            'subscription_type' => $reqSubType ?? 'monthly',
                            'trip_direction'    => $reqTripDirection ?? 'two_way',
                            'timing'            => $pivot->timing ?? null,
                        ],

                        'school' => [
                            'id'      => $school->id,
                            'name'    => $school->name,
                            'address' => $school->address,
                            'lat'     => (float) ($school->lat ?? 0),
                            'lng'     => (float) ($school->lng ?? 0),
                        ],

                        'home' => [
                            'address' => $address->label ?? 'منزل ولي الأمر',
                            'lat'     => (float) ($address->lat ?? 0),
                            'lng'     => (float) ($address->lng ?? 0),
                        ],
                    ];
                });
            }),

            'created_at'           => $this->created_at?->toIso8601String(),
            'created_at_formatted' => $this->created_at?->format('Y-m-d H:i'),
        ];
    }
}