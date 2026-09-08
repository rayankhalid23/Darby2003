<?php

namespace App\Http\Resources\Concerns;

use App\Models\Driver\Driver;
use App\Models\Parent\Child;
use App\Models\Shared\PricingSetting;
use App\Models\Shared\SubscriptionRequest;
use App\Models\User;

/**
 * منطق موحّد لبناء كتل الطفل/الشخص (سائق أو ولي أمر)/بيانات الاشتراك، يُستخدم بكل
 * الـ Resources الخاصة بطلب الاشتراك والاشتراك المفعّل بدل تكرار نفس الحسابات
 * بخمس نسخ مختلفة.
 */
trait BuildsSubscriptionPayload
{
    /**
     * بيانات طفل واحد موحّدة (اسم، صورة، مدرسة، سعره). لو $forDriver=true تُضاف
     * عمولة المنصة وصافي السائق لهذا الطفل.
     */
    protected function buildChildPayload(?Child $child, bool $forDriver = false): ?array
    {
        if (!$child) {
            return null;
        }

        $pivot = $child->pivot ?? null;
        $school = $child->school;

        $rawPrice      = (float) ($pivot?->price_per_child ?? 0);
        $discountAmt   = (float) ($pivot?->discount_amount ?? 0);
        $afterDiscount = (float) ($pivot?->total_amount_after_discount ?? max(0, $rawPrice - $discountAmt));
        $discountPct   = $rawPrice > 0 ? round(($discountAmt / $rawPrice) * 100, 2) : 0.0;

        $pricing = [
            'price_before_discount' => $rawPrice,
            'discount_percentage'   => $discountPct,
            'discount_amount'       => $discountAmt,
            'price_after_discount'  => $afterDiscount,
        ];

        if ($forDriver) {
            $commissionFraction = PricingSetting::commissionRateFraction();
            $driverNet = (float) ($pivot?->driver_net_price ?? 0);
            if ($driverNet <= 0 && $afterDiscount > 0) {
                $driverNet = round($afterDiscount * (1 - $commissionFraction), 2);
            }
            $pricing['platform_commission_amount'] = max(0, round($afterDiscount - $driverNet, 2));
            $pricing['driver_net_price'] = $driverNet;
        }

        return [
            'child_id'      => $child->id,
            'name'          => $child->full_name,
            'photo_url'     => $child->photo_url ? asset($child->photo_url) : null,
            'age'           => $child->age,
            'gender'        => $child->gender,
            'grade'         => $child->grade,
            'grade_label'   => $child->grade !== null ? 'الصف ' . $child->grade : 'غير محدد',
            'school' => [
                'id'   => $school?->id,
                'name' => $pivot?->school_label ?? $school?->name,
                'lat'  => (float) ($pivot?->school_lat ?? $school?->lat ?? 0),
                'lng'  => (float) ($pivot?->school_lng ?? $school?->lng ?? 0),
            ],
            'timing'        => $pivot?->timing,
            'distance_km'   => (float) ($pivot?->distance_km ?? 0),
            'medical_notes' => $child->medical_notes,
            'pricing'       => $pricing,
        ];
    }

    /**
     * بيانات شخص (سائق أو ولي أمر) — نفس الشكل للاثنين. $driver يُمرَّر فقط
     * لإضافة كتلة المركبة عند عرض بيانات السائق.
     */
    protected function buildPersonPayload(?User $user, ?Driver $driver = null): array
    {
        $payload = [
            // لو الشخص سائق نستخدم drivers.id (نفس القيمة المستخدمة بباقي الـ API
            // كـ driver_id)، لا users.id — الاثنان مختلفان.
            'id'                => $driver ? $driver->id : $user?->id,
            'name'              => $user?->full_name,
            'phone'             => $user?->phone_number,
            'alternative_phone' => $user?->alternative_phone,
            'gender'            => $user?->gender,
            'photo_url'         => $user?->avatar_url,
        ];

        if ($driver) {
            $vehicle = $driver->vehicle;
            $payload['vehicle'] = $vehicle ? [
                'has_ac'       => (bool) $vehicle->has_ac,
                'capacity'     => (int) $vehicle->capacity_manual,
                'plate_number' => $vehicle->plate_number,
            ] : null;
        }

        return $payload;
    }

    protected function buildSubscriptionBlock(SubscriptionRequest $req): array
    {
        $type = $req->subscription_type;
        $direction = $req->trip_direction;

        return [
            'type'       => $type,
            'type_label' => match ($type) {
                'single_day' => 'اشتراك يوم واحد',
                'multi_day'  => 'اشتراك عدة أيام',
                'monthly'    => 'اشتراك شهري',
                'term'       => 'اشتراك فصل دراسي',
                'yearly'     => 'اشتراك سنوي',
                default      => $type,
            },
            'direction'       => $direction,
            'direction_label' => match ($direction) {
                'go'                         => 'ذهاب صباحي فقط',
                'return'                     => 'عودة مسائية فقط',
                'both'                       => 'ذهاب وإياب',
                default                      => $direction,
            },
            'start_date'         => $req->start_date?->format('Y-m-d') ?? $req->start_date,
            'end_date'           => $req->end_date?->format('Y-m-d') ?? $req->end_date,
            'working_days_count' => (int) ($req->working_days_count ?? 0),
        ];
    }

    protected function buildHomeAddressBlock(SubscriptionRequest $req): array
    {
        return [
            'id'    => $req->home_address_id,
            'label' => $req->home_label,
            'lat'   => (float) ($req->home_lat ?? 0),
            'lng'   => (float) ($req->home_lng ?? 0),
        ];
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'pending'          => 'قيد الانتظار',
            'acquired'         => 'قيد الانتظار',
            'accepted'         => 'مقبول',
            'rejected'         => 'مرفوض',
            'cancelled'        => 'ملغي',
            'contract_offered' => 'بانتظار الموافقة',
            'active'           => 'ساري ومفعل',
            'paused'           => 'متوقف مؤقتاً',
            'completed'        => 'مكتمل',
            'suspended_unpaid' => 'موقوف (غير مسدد)',
            'terminated'       => 'منتهي',
            'pending_start'    => 'قادم (لم يبدأ بعد)',
            default            => $status,
        };
    }
}
