<?php

namespace App\Services\Parent;

use App\Models\Parent\Address;
use Illuminate\Support\Facades\DB;
use Exception;

class AddressService
{
    /**
     * جلب كافة عناوين ولي الأمر الحالي
     */
    public function getParentAddresses(int $parentId)
    {
        return Address::where('user_id', $parentId)
            ->with('zone')
            ->get();
    }

    /**
     * إنشاء عنوان جديد لولي الأمر
     */
    public function createAddress(int $parentId, array $data): Address
    {
        $address = Address::create([
            'user_id' => $parentId,
            'zone_id' => $data['zone_id'] ?? null,
            'label'   => $data['label'],
            'lat'     => $data['lat'],
            'lng'     => $data['lng'],
        ]);

        return $address->load('zone');
    }

   /**
     * تحديث عنوان موجود (يدعم التعديل الجزئي الصارم مع الحماية الكاملة)
     */
    public function updateAddress(Address $address, int $parentId, array $data): Address
    {
        // 1. فحص تكرار الاسم (Label) فقط إذا تم إرساله وكان مختلفاً عن الاسم الحالي
        if (array_key_exists('label', $data) && $data['label'] !== $address->label) {
            $labelExists = Address::where('user_id', $parentId)
                ->where('label', $data['label'])
                ->where('id', '!=', $address->id)
                ->exists();

            if ($labelExists) {
                throw new \Exception("تعذر التعديل: لديك عنوان آخر مسجل مسبقاً باسم '" . $data['label'] . "'.");
            }
        }

        // 2. فحص تكرار الموقع الجغرافي فقط إذا أُرسلت إحداثيات جديدة وكانت مختلفة عن الحالية
        $newLat = $data['lat'] ?? $address->lat;
        $newLng = $data['lng'] ?? $address->lng;

        if ((array_key_exists('lat', $data) || array_key_exists('lng', $data)) && 
            ($newLat != $address->lat || $newLng != $address->lng)) {
            
            $locationExists = Address::where('user_id', $parentId)
                ->where('lat', $newLat)
                ->where('lng', $newLng)
                ->where('id', '!=', $address->id)
                ->exists();

            if ($locationExists) {
                throw new \Exception("تعذر التعديل: هذا الموقع الجغرافي يتطابق مع موقع عنوان آخر مضاف لديك بالفعل.");
            }
        }

        $addressId = $address->id;

        // 3. تنفيذ التعديل الجزئي
        $address->update($data);
        
        return Address::with('zone')->withTrashed()->findOrFail($addressId);
    }

    /**
     * حذف العنوان ناعماً
     */
    public function deleteAddress(Address $address): void
    {
        $address->delete();
    }
}