<?php

namespace App\Http\Resources\Api\Shared;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationChangeRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $grossFee = (float) ($this->fee_amount ?? \App\Models\Shared\PricingSetting::DEFAULT_LOCATION_CHANGE_FEE);

        // الطلبات القديمة أُنشئت قبل تجميد نسبة العمولة على السجل، فنستنتجها وقت العرض.
        $commissionRate = $this->commission_rate !== null && (float) $this->commission_rate > 0
            ? (float) $this->commission_rate
            : \App\Models\Shared\PricingSetting::commissionRatePercent();

        $commission = $this->platform_commission_amount !== null && (float) $this->platform_commission_amount > 0
            ? (float) $this->platform_commission_amount
            : round(($grossFee * $commissionRate) / 100, 2);

        // بناء قائمة الأطفال — من جدول الربط (طلب مُجمَّع)، وإلا من child_id القديم (طلب فردي قديم).
        $targets = $this->relationLoaded('targets') ? $this->targets : collect();
        if ($targets->isEmpty() && $this->child_id) {
            $childrenPayload = $this->child ? [[
                'id'   => $this->child->id,
                'name' => $this->child->full_name,
            ]] : [];
            $tripsPayload = [
                [
                    'active_subscription_id' => $this->active_subscription_id,
                    'child_id'  => $this->child_id,
                    'direction' => $this->direction ?? \App\Models\Shared\LocationChangeRequest::DIRECTION_BOTH,
                    'applied'   => null,
                    'skip_reason' => null,
                ],
            ];
        } else {
            $childrenPayload = $targets
                ->groupBy('child_id')
                ->map(function ($rows) {
                    $first = $rows->first();
                    return [
                        'id'   => (int) $first->child_id,
                        'name' => $first->child?->full_name,
                    ];
                })
                ->values()
                ->all();

            $tripsPayload = $targets->map(fn ($t) => [
                'active_subscription_id' => (int) $t->active_subscription_id,
                'child_id'  => (int) $t->child_id,
                'trip_id'   => $t->trip_id,
                'direction' => $t->direction,
                'applied'   => (bool) $t->applied,
                'skip_reason' => $t->skip_reason,
            ])->values()->all();
        }

        return [
            'id'                     => $this->id,
            'active_subscription_id' => $this->active_subscription_id, // قد يكون null للطلبات المُجمَّعة
            'point_type'             => $this->point_type,
            'direction'              => $this->direction,
            'change_date'            => $this->change_date?->format('Y-m-d'),
            'is_single_day'          => (bool) ($this->is_single_day ?? true),
            'distance_km'            => $this->distance_km !== null ? (float) $this->distance_km : null,
            'fee_tier'               => $this->fee_tier,
            'fee_tier_label'         => $this->fee_tier ? (\App\Models\Shared\PricingSetting::TIER_LABELS[$this->fee_tier] ?? null) : null,
            'fee_amount'             => $grossFee,
            'currency'               => 'د.ل',
            'fee_breakdown'          => [
                'gross_fee'           => $grossFee,
                'commission_rate'     => $commissionRate,
                'platform_commission' => $commission,
                'driver_net_fee'      => max(0, round($grossFee - $commission, 2)),
                'currency'            => 'د.ل',
            ],
            'is_settled'             => (bool) ($this->is_settled ?? false),
            'status'                 => $this->status,
            'rejection_reason'       => $this->rejection_reason,
            'new_location'           => [
                'lat'   => $this->new_lat,
                'lng'   => $this->new_lng,
                'label' => $this->new_label,
            ],
            'children' => $childrenPayload,
            'trips'    => $tripsPayload,
            'driver' => [
                'id'   => $this->driver?->id,
                'name' => $this->driver?->user?->full_name,
            ],
            'parent' => [
                'id'   => $this->parent?->id,
                'name' => $this->parent?->full_name,
            ],
            'responded_at' => $this->responded_at?->toIso8601String(),
            'created_at'   => $this->created_at?->toIso8601String(),
        ];
    }
}
