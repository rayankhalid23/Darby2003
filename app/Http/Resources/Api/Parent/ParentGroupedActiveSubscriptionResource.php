<?php

namespace App\Http\Resources\Api\Parent;

use App\Http\Resources\Concerns\BuildsSubscriptionPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParentGroupedActiveSubscriptionResource extends JsonResource
{
    use BuildsSubscriptionPayload;

    public function toArray(Request $request): array
    {
        if (!$this->resource) {
            return [];
        }

        /** @var \App\Models\Shared\SubscriptionRequest $req */
        $req = $this->resource;

        $resolvedState = $req->resolveState();

        $childrenArray = [];
        if ($req->relationLoaded('children')) {
            foreach ($req->children as $child) {
                // جلب بيانات الاشتراك النشط (المسار والأوقات) الخاصة بهذا الطفل
                $matchingActiveSub = optional($req->activeSubscriptions)->firstWhere('child_id', $child->id);
                $childPayload = $this->buildChildPayload($child, forDriver: false, req: $req, activeSub: $matchingActiveSub);

                if ($matchingActiveSub) {
                    $childResolvedState = $req->resolveState($child, $matchingActiveSub);
                    
                    $childPayload['active_subscription'] = [
                        'id'           => $matchingActiveSub->id,
                        'status'       => $childResolvedState['status'],
                        'status_label' => $childResolvedState['state_label'],
                        'route_id'     => $matchingActiveSub->route_id,
                        'pickup_time'  => $matchingActiveSub->pickup_time ?? $req->pickup_time,
                        'dropoff_time' => $matchingActiveSub->dropoff_time ?? $req->dropoff_time,
                    ];

                    if ($childResolvedState['status'] === 'cancelled') {
                        $childPayload['active_subscription']['cancellation'] = [
                            'cancelled_at' => $matchingActiveSub->cancelled_at,
                            'cancelled_by' => $matchingActiveSub->cancelled_by,
                            'reason'       => $matchingActiveSub->cancellation_reason,
                        ];
                    }
                } else {
                    $childPayload['active_subscription'] = null;
                }

                $childrenArray[] = $childPayload;
            }
        }

        return [
            'id'           => $req->id,
            'status'       => $resolvedState['status'],
            'status_label' => $resolvedState['state_label'],

            'driver' => $this->buildPersonPayload($req->driver?->user, $req->driver),

            'subscription' => $this->buildSubscriptionBlock($req),

            'home_address' => $this->buildHomeAddressBlock($req),

            'children_count' => (int) ($req->relationLoaded('children') ? $req->children->count() : ($req->children_count ?? 0)),

            'pricing' => [
                'total_price'                 => (float) ($req->total_price ?? 0),
                'discount_amount'             => (float) ($req->discount_amount ?? 0),
                'total_amount_after_discount' => (float) ($req->total_amount_after_discount ?? 0),
            ],

            'children'   => $childrenArray,
            
            'notes'      => $req->notes,
            'created_at' => $req->created_at?->toIso8601String(),
            'updated_at' => $req->updated_at?->toIso8601String(),
        ];
    }
}
