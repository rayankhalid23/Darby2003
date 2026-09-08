<?php

namespace App\Http\Resources\Api\Driver;

use App\Http\Resources\Concerns\BuildsSubscriptionPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverSubscriptionResource extends JsonResource
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

        $commissionTotal = 0.0;
        $driverNetTotal  = 0.0;
        if ($req->relationLoaded('children')) {
            foreach ($req->children as $child) {
                $childPayload = $this->buildChildPayload($child, forDriver: true);
                $commissionTotal += $childPayload['pricing']['platform_commission_amount'];
                $driverNetTotal  += $childPayload['pricing']['driver_net_price'];
            }
        }

        return [
            'id'           => $req->id,
            'status'       => $resolvedState['status'],
            'status_label' => $resolvedState['state_label'],

            'parent' => $this->buildPersonPayload($req->parent),

            'subscription' => $this->buildSubscriptionBlock($req),

            'home_address' => $this->buildHomeAddressBlock($req),

            'children_count' => (int) ($req->children_count ?? ($req->children?->count() ?: 0)),

            'pricing' => [
                'total_price'                 => (float) ($req->total_price ?? 0),
                'discount_amount'             => (float) ($req->discount_amount ?? 0),
                'total_amount_after_discount' => (float) ($req->total_amount_after_discount ?? 0),
                'platform_commission_total'   => round($commissionTotal, 2),
                'driver_net_total'            => round($driverNetTotal, 2),
            ],

            'children' => $this->whenLoaded('children', fn () => $req->children->map(
                fn ($child) => $this->buildChildPayload($child, forDriver: true)
            )->values()),

            'notes'      => $req->notes,
            'created_at' => $req->created_at?->toIso8601String(),
        ];
    }
}
