<?php

namespace App\Http\Resources\Api\Driver;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverActiveChildSubscriptionResource extends JsonResource
{
    /**
     * تحويل بيانات اشتراك كل طفل على حدة للسائق
     */
    public function toArray(Request $request): array
    {
        $subscriptionRequest = $this->resource['subscriptionRequest'] ?? $this->resource;
        $child = $this->resource['child'] ?? $this->resource;

        $parentUser = optional(optional($subscriptionRequest->parent)->user);
        $pivot = $child->pivot ?? null;
        $school = optional($child->school);
        $address = optional($child->address);

        $rawChildPrice = (float) ($pivot?->price_per_child ?? $pivot?->trip_price ?? $subscriptionRequest->total_price ?? 0);
        $tripPrice = (float) ($pivot?->trip_price ?? $rawChildPrice);
        $discountAmt = (float) ($pivot?->discount_amount ?? 0);
        $afterDiscount = (float) ($pivot?->total_amount_after_discount ?? max(0, $rawChildPrice - $discountAmt));
        if ($afterDiscount <= 0 && $rawChildPrice > 0) {
            $afterDiscount = max(0, $rawChildPrice - $discountAmt);
        }

        // النسبة من pricing_settings لا مثبتة بـ 0.92، وإلا لم يتغير صافي السائق
        // المعروض عند تعديل الإدارة لنسبة العمولة.
        $netFactor = 1 - \App\Models\Shared\PricingSetting::commissionRateFraction();

        $driverNetPrice = (float) ($pivot?->driver_net_price ?? 0);
        if ($driverNetPrice <= 0 && $afterDiscount > 0) {
            $driverNetPrice = round($afterDiscount * $netFactor, 2);
        }
        $platformFee = max(0, round($afterDiscount - $driverNetPrice, 2));

        // إجماليات على مستوى الطلب كله (كل الأطفال) — الحقول المسماة *_total
        // كانت تعرض قيم طفل واحد فقط، فيظهر للسائق نصف مستحقه في طلب بطفلين.
        $allChildren = $subscriptionRequest->relationLoaded('children')
            ? $subscriptionRequest->children
            : collect();

        if ($allChildren->isNotEmpty()) {
            $requestRawTotal      = (float) $allChildren->sum(fn($c) => (float) ($c->pivot->price_per_child ?? $c->pivot->trip_price ?? 0));
            $requestDiscountTotal = (float) $allChildren->sum(fn($c) => (float) ($c->pivot->discount_amount ?? 0));
            $requestAfterDiscount = (float) $allChildren->sum(fn($c) => (float) ($c->pivot->total_amount_after_discount ?? 0));
            $requestNetTotal      = (float) $allChildren->sum(function ($c) use ($netFactor) {
                $net = (float) ($c->pivot->driver_net_price ?? 0);
                if ($net <= 0) {
                    $net = round(((float) ($c->pivot->total_amount_after_discount ?? 0)) * $netFactor, 2);
                }
                return $net;
            });
            $requestChildrenCount = $allChildren->count();
        } else {
            $requestRawTotal      = $rawChildPrice;
            $requestDiscountTotal = $discountAmt;
            $requestAfterDiscount = $afterDiscount;
            $requestNetTotal      = $driverNetPrice;
            $requestChildrenCount = 1;
        }

        $activeSubId = $this->resource['activeSubId'] ?? optional($subscriptionRequest->activeSubscriptions?->firstWhere('child_id', $child?->id))->id ?? $subscriptionRequest->id;

        $childDetails = [
            'id'                      => $child->id,
            'child_id'                => $child->id,
            'subscription_id'         => $subscriptionRequest->id,
            'subscription_request_id' => $subscriptionRequest->id,
            'active_subscription_id'  => $activeSubId,
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
                'discount_percentage'         => $rawChildPrice > 0 ? round(($discountAmt / $rawChildPrice) * 100, 2) : 0.0, // 3. نسبة التخفيض %
                'discount_amount'             => $discountAmt,        // 4. قيمة التخفيض
                'total_amount_after_discount' => $afterDiscount,      // 5. السعر بعد التخفيض
                'platform_commission_rate'    => $afterDiscount > 0 ? round(($platformFee / $afterDiscount) * 100, 2) : 8.0,  // 6. نسبة عمولة المنصة %
                'platform_commission_amount'  => $platformFee,        // 7. قيمة عمولة المنصة
                'platform_commission'         => $platformFee,
                'driver_net_price'            => $driverNetPrice,     // 8. إجمالي السعر للسائق بعد التخفيض وعمولة المنصة
                'total_price'                 => $driverNetPrice,
            ],

            'subscription_period' => [
                'start_date'         => $pivot?->start_date ? (string) $pivot->start_date : ($subscriptionRequest->start_date ? (string) $subscriptionRequest->start_date : null),
                'end_date'           => $pivot?->end_date ? (string) $pivot->end_date : ($subscriptionRequest->end_date ? (string) $subscriptionRequest->end_date : null),
                'working_days_count' => (int) ($subscriptionRequest->working_days_count ?? 0),
            ],

            'trip_details' => [
                'subscription_type' => $pivot?->subscription_type ?? $subscriptionRequest->subscription_type ?? 'monthly',
                'trip_direction'    => $subscriptionRequest->trip_direction ?? 'two_way',
                'timing'            => $pivot?->timing ?? null,
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
            'status' => [
                'value' => $resolvedState['status'],
                'label' => $resolvedState['state_label'],
            ],
            'status_value'            => $resolvedState['status'],
            'status_text'             => $resolvedState['status_text'],
            'is_active'               => $resolvedState['is_active'],
            'notes'                   => $subscriptionRequest->notes ?? $subscriptionRequest->general_notes ?? null,
            // 📌 تمييز مقصود: total_amount يخص الطفل المعروض في هذه الشاشة تحديداً،
            // بينما الحقول المسماة *_total تخص طلب الاشتراك كاملاً بكل أطفاله.
            'total_amount'            => round($driverNetPrice, 2),
            'driver_net_total'        => round($requestNetTotal, 2),
            'original_total'          => round($requestRawTotal, 2),
            'discount_total'          => round($requestDiscountTotal, 2),
            'total_after_discount'    => round($requestAfterDiscount, 2),
            'currency'                => 'د.ل',
            'children_count'          => $requestChildrenCount,

            'parent' => [
                'id'       => optional($subscriptionRequest->parent)->id,
                'name'     => $parentUser->full_name ?? $parentUser->name ?? 'غير محدد',
                'phone'    => $parentUser->phone_number ?? $parentUser->phone ?? null,
                'email'    => $parentUser->email ?? null,
                'avatar'   => $parentUser->avatar_url ?? null,
            ],

            // بيانات الطفل الفردي
            'child'                => $childDetails,

            // مصفوفة children تُبقي الشكل موحّداً مع بقية نقاط الاشتراكات في الـ API،
            // فيتعامل الفرونت مع اشتراك طفل واحد أو عدة أطفال بنفس الكود.
            'children'             => [$childDetails],

            'created_at'           => $subscriptionRequest->created_at?->toIso8601String(),
            'created_at_formatted' => $subscriptionRequest->created_at?->format('Y-m-d H:i'),
        ];
    }
}
