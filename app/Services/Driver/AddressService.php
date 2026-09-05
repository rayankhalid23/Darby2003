<?php

namespace App\Services\Driver;

use App\Models\Driver\Address; // 🚀 الاستيراد من مجلد السائق الجديد
use Illuminate\Support\Facades\DB;
use Exception;

class AddressService
{
    public function getDriverAddresses(int $driverId)
    {
        return Address::where('user_id', $driverId)
            ->with('zone')
            ->get();
    }

    public function createAddress(int $driverId, array $data): Address
    {
        $address = Address::create([
            'user_id' => $driverId,
            'zone_id' => $data['zone_id'] ?? null,
            'label'   => $data['label'],
            'lat'     => $data['lat'],
            'lng'     => $data['lng'],
        ]);

        return $address->load('zone');
    }

    public function updateAddress(Address $address, int $driverId, array $data): Address
    {
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

        $addressId = $address->id;

        $address->update($data);
        
        return Address::with('zone')->withTrashed()->findOrFail($addressId);
    }

    public function deleteAddress(Address $address): void
    {
        $address->delete();
    }
}