<?php

namespace App\Services\Driver;

use App\Models\Driver\Address;
use Illuminate\Support\Facades\DB;
use Exception;

class AddressService
{
    public function getDriverAddresses(int $driverId)
    {
        return Address::where('user_id', $driverId)
            ->with('zone')
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->get();
    }

    public function createAddress(int $driverId, array $data): Address
    {
        return DB::transaction(function () use ($driverId, $data) {
            $hasAddress = Address::where('user_id', $driverId)->exists();

            $isDefault = !empty($data['is_default']) || !$hasAddress;

            if ($isDefault) {
                Address::where('user_id', $driverId)->update(['is_default' => false]);
            }

            $address = Address::create([
                'user_id'    => $driverId,
                'zone_id'    => $data['zone_id'] ?? null,
                'label'      => $data['label'],
                'lat'        => $data['lat'],
                'lng'        => $data['lng'],
                'is_default' => $isDefault,
            ]);

            return $address->load('zone');
        });
    }

    public function updateAddress(Address $address, int $driverId, array $data): Address
    {
        return DB::transaction(function () use ($address, $driverId, $data) {
            if (array_key_exists('label', $data) && $data['label'] !== $address->label) {
                $labelExists = Address::where('user_id', $driverId)
                    ->where('label', $data['label'])
                    ->where('id', '!=', $address->id)
                    ->exists();

                if ($labelExists) {
                    throw new \Exception("تعذر التعديل: لديك عنوان آخر مسجل مسبقاً باسم '" . $data['label'] . "'.");
                }
            }

            $newLat = $data['lat'] ?? $address->lat;
            $newLng = $data['lng'] ?? $address->lng;

            if ((array_key_exists('lat', $data) || array_key_exists('lng', $data)) && 
                ($newLat != $address->lat || $newLng != $address->lng)) {
                
                $locationExists = Address::where('user_id', $driverId)
                    ->where('lat', $newLat)
                    ->where('lng', $newLng)
                    ->where('id', '!=', $address->id)
                    ->exists();

                if ($locationExists) {
                    throw new \Exception("تعذر التعديل: هذا الموقع الجغرافي يتطابق مع موقع عنوان آخر مضاف لديك بالفعل.");
                }
            }

            if (!empty($data['is_default'])) {
                Address::where('user_id', $driverId)
                    ->where('id', '!=', $address->id)
                    ->update(['is_default' => false]);
            }

            $address->update($data);
            
            return Address::with('zone')->withTrashed()->findOrFail($address->id);
        });
    }

    public function deleteAddress(Address $address): void
    {
        DB::transaction(function () use ($address) {
            $userId = $address->user_id;
            $wasDefault = (bool) $address->is_default;

            $address->delete();

            // إذا كان العنوان المحذوف هو الافتراضي وهناك عناوين أخرى، نرقي أقدم عنوان متبقٍ
            if ($wasDefault) {
                $next = Address::where('user_id', $userId)->oldest()->first();
                if ($next) {
                    $next->update(['is_default' => true]);
                }
            }
        });
    }
}
