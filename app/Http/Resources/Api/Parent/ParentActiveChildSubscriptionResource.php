<?php

namespace App\Http\Resources\Api\Parent;

use App\Http\Resources\Concerns\BuildsSubscriptionPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParentActiveChildSubscriptionResource extends JsonResource
{
    use BuildsSubscriptionPayload;

    public function toArray(Request $request): array
    {
        /** @var \App\Models\Shared\SubscriptionRequest $req */
        $req = $this->resource['subscriptionRequest'] ?? $this->resource;
        $child = $this->resource['child'] ?? $this->resource;

        $activeSubId = $this->resource['activeSubId']
            ?? optional($req->activeSubscriptions?->firstWhere('child_id', $child?->id))->id;

        $matchingActiveSub = optional($req->activeSubscriptions)->firstWhere('child_id', $child?->id);
        $resolvedState = $req->resolveState($child, $matchingActiveSub);

        $payload = [
            'active_subscription_id' => (int) $activeSubId,
            'status'                 => $resolvedState['status'],
            'status_label'           => $resolvedState['state_label'],

            'driver' => $this->buildPersonPayload($req->driver?->user, $req->driver),

            'subscription' => $this->buildSubscriptionBlock($req),

            'home_address' => $this->buildHomeAddressBlock($req),

            'child' => $this->buildChildPayload($child, forDriver: false),

            'notes'      => $req->notes,
            'created_at' => $req->created_at?->toIso8601String(),
            'updated_at' => $req->updated_at?->toIso8601String(),
        ];

        if ($matchingActiveSub) {
            $payload['route_id']     = $matchingActiveSub->route_id;
            $payload['pickup_time']  = $matchingActiveSub->pickup_time;
            $payload['dropoff_time'] = $matchingActiveSub->dropoff_time;

            if ($resolvedState['status'] === 'cancelled') {
                $payload['cancellation'] = [
                    'cancelled_at' => $matchingActiveSub->cancelled_at,
                    'cancelled_by' => $matchingActiveSub->cancelled_by,
                    'reason'       => $matchingActiveSub->cancellation_reason,
                ];
            }
        }

        return $payload;
    }
}
