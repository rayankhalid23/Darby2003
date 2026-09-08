<?php

namespace App\Http\Resources\Api\Parent;

use App\Http\Resources\Concerns\BuildsSubscriptionPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionRequestDetailsResource extends JsonResource
{
    use BuildsSubscriptionPayload;

    public function toArray(Request $request): array
    {
        /** @var \App\Models\Shared\SubscriptionRequest $req */
        $req = $this->resource;

        $resolvedState = $req->resolveState();

        return [
            'id'           => $req->id,
            'status'       => $resolvedState['status'],
            'status_label' => $resolvedState['state_label'],

            'driver' => $this->buildPersonPayload($req->driver?->user, $req->driver),

            'subscription' => $this->buildSubscriptionBlock($req),

            'home_address' => $this->buildHomeAddressBlock($req),

            'children_count' => (int) ($req->children_count ?? ($req->children?->count() ?: 0)),

            'pricing' => [
                'total_price'                 => (float) ($req->total_price ?? 0),
                'discount_amount'             => (float) ($req->discount_amount ?? 0),
                'total_amount_after_discount' => (float) ($req->total_amount_after_discount ?? 0),
            ],

            'children' => $this->whenLoaded('children', fn () => $req->children->map(
                fn ($child) => $this->buildChildPayload($child, forDriver: false)
            )->values()),

            'notes'      => $req->notes,
            'created_at' => $req->created_at?->toIso8601String(),
        ];
    }
}
