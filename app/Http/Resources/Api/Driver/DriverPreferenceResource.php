<?php

namespace App\Http\Resources\Api\Driver;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverPreferenceResource extends JsonResource
{
    // app/Http/Resources/Api/Driver/DriverPreferenceResource.php

    public function toArray($request): array
    {
        $zones = $this->zones;

        $groupedZones = $zones
            ->filter(fn($z) => $z->subMunicipality !== null)
            ->groupBy('subMunicipality.name')
            ->map(function ($zonesGroup) {
                $subMuni = $zonesGroup->first()->subMunicipality;
                return [
                    'municipality_name'     => $subMuni->municipality?->name ?? '',
                    'sub_municipality_name' => $subMuni->name ?? '',
                    'zones' => $zonesGroup->map(fn($z) => ['id' => $z->id, 'name' => $z->name])->values(),
                ];
            })->values();

        // ملخص الحجوزات المستقبلية لكل slot (اليوم فما بعد)
        $seatSlotsData = [];
        if ($this->relationLoaded('seatSlots')) {
            $today = now()->toDateString();
            $grouped = $this->seatSlots
                ->where('date', '>=', $today)
                ->groupBy('slot');

            foreach (\App\Models\Driver\DriverSeatSlot::ALL_SLOTS as $slotKey) {
                $slotRows = $grouped->get($slotKey, collect());
                $maxBooked = $slotRows->max('booked') ?? 0;
                $seatSlotsData[$slotKey] = [
                    'upcoming_bookings' => (int) $maxBooked,
                ];
            }
        }

        return [
            'driver_id'         => $this->id,
            'shift_slots'       => [
                'morning_go'      => (bool) $this->morning_go,
                'morning_return'  => (bool) $this->morning_return,
                'afternoon_go'    => (bool) $this->afternoon_go,
                'afternoon_return'=> (bool) $this->afternoon_return,
            ],
            'subscription_type' => $this->subscription_type,
            'school_stages'     => $this->school_stages ?? [],
            'seat_slots'        => $seatSlotsData,
            'coverage'          => $groupedZones,
        ];
    }
}