<?php

namespace App\Http\Resources\Api\Parent;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class DriverMatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->user;
        $activeVehicle = $this->vehicles?->where('status', 'Active')->first() ?? $this->vehicles?->first();

        $pricingBreakdown = $this->pricing_breakdown ?? [];
        $totalPrice       = $this->estimated_total_price ?? 0;

        return [
            'id'                => $this->id,
            'full_name'         => $user?->full_name ?? 'غير متوفر',
            'phone_number'      => $user?->phone_number ?? 'غير متوفر',
            'alternative_phone' => $user?->alternative_phone,
            'avatar_url'        => ($user?->avatar_url) ? asset(Storage::url($user->avatar_url)) : null,
            'gender'            => $user?->gender ?? $this->gender,
            'accepted_gender'   => $this->accepted_gender,
            'subscription_type' => $this->subscription_type,
            'rating'            => round((float)($this->rating_avg ?? 5.0), 1),
            'completed_trips'   => $this->completed_trips_count ?? 0,
            'status'            => $this->status,

            'vehicle' => $activeVehicle ? [
                'brand'           => $activeVehicle->brand,
                'model'           => $activeVehicle->model,
                'year'            => $activeVehicle->year,
                'color'           => $activeVehicle->color,
                'type'            => $activeVehicle->type,
                'has_ac'          => (bool) $activeVehicle->has_ac,
                'capacity_manual' => $activeVehicle->capacity_manual,
                'plate_number'    => $activeVehicle->plate_number,
            ] : null,

            'working_zones' => $this->zones ? $this->zones->map(fn($z) => [
                'id'   => $z->id,
                'name' => $z->name,
            ])->values() : [],

            'pricing' => [
                'total_price'       => number_format($totalPrice, 2) . ' د.ل',
                'total_price_raw'   => $totalPrice,
                'platform_fee'      => $this->platform_fee ?? 0,
                'driver_net_amount' => $this->driver_net_amount ?? 0,
                'price_per_km'      => $pricingBreakdown[0]['price_per_km'] ?? $this->price_per_km ?? null,
                'children_count'    => count($pricingBreakdown),
                'breakdown'         => collect($pricingBreakdown)->map(function ($item) {
                    $subType = $item['subscription_type'] ?? 'multi_day';
                    $subLabel = ($subType === 'single_day') ? 'يوم واحد' : 'عدة أيام';
                    return array_merge($item, [
                        'subscription_type_label' => $subLabel,
                        'child_price'             => isset($item['final_total']) ? number_format($item['final_total'], 2) . ' د.ل' : null,
                        'child_price_raw'         => $item['final_total'] ?? ($item['subtotal'] ?? 0),
                    ]);
                })->values(),
            ],
        ];
    }
}