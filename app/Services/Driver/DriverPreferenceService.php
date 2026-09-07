<?php

namespace App\Services\Driver;

use App\Models\Driver\Driver;
use App\Models\Driver\DriverSeatSlot;
use App\Enums\driver\DriverShift;
use App\Enums\Shared\SchoolStage;
use App\Enums\Shared\SubscriptionDuration;
use App\Models\Shared\Zone;
use App\Models\Shared\Municipality;
use Illuminate\Support\Facades\DB;
use Exception;

class DriverPreferenceService
{
    public const MAX_ZONES = 5;

    /**
     * جلب التفضيلات مع العلاقات الهرمية (المنطقة -> البلدية الفرعية -> البلدية الكبرى)
     */
    public function getPreferences(Driver $driver): Driver
    {
        return $driver->load(['zones.subMunicipality.municipality', 'seatSlots']);
    }

    /**
     * تحديث التفضيلات الشاملة مع فحص شرط "البلدية الفرعية الموحدة"
     */
    public function updatePreferences(Driver $driver, array $data): Driver
    {
        return DB::transaction(function () use ($driver, $data) {
            $driverUpdate = [];
            foreach (['morning_go', 'morning_return', 'afternoon_go', 'afternoon_return'] as $slot) {
                if (array_key_exists($slot, $data)) {
                    $driverUpdate[$slot] = (bool) $data[$slot];
                }
            }
            if (array_key_exists('subscription_type', $data)) {
                $driverUpdate['subscription_type'] = $data['subscription_type'];
            }
            if (array_key_exists('school_stages', $data)) {
                $driverUpdate['school_stages'] = $data['school_stages'];
            }

            // فحص: لا يمكن تعطيل فترة بها حجوزات مستقبلية
            foreach (['morning_go', 'morning_return', 'afternoon_go', 'afternoon_return'] as $slotKey) {
                if (array_key_exists($slotKey, $driverUpdate) && $driverUpdate[$slotKey] === false) {
                    $hasFutureBookings = \App\Models\Driver\DriverSeatSlot::where('driver_id', $driver->id)
                        ->where('slot', $slotKey)
                        ->where('date', '>', now()->toDateString())
                        ->where('booked', '>', 0)
                        ->exists();

                    if ($hasFutureBookings) {
                        throw new Exception("لا يمكن تعطيل فترة [{$slotKey}] لأن بها حجوزات مستقبلية.");
                    }
                }
            }

            if (!empty($driverUpdate)) {
                $driver->update($driverUpdate);
            }

            if (array_key_exists('zones', $data)) {
                $zoneIds = array_values(array_unique($data['zones'] ?? []));

                if (count($zoneIds) > self::MAX_ZONES) {
                    throw new Exception('عذراً، الحد الأقصى للمناطق التي يمكن للسائق تغطيتها هو ' . self::MAX_ZONES . ' مناطق.');
                }

                $driver->zones()->sync($zoneIds);
            }

            return $driver->fresh(['zones.subMunicipality.municipality', 'seatSlots']);
        });
    }

    /**
     * إضافة منطقة واحدة مع التحقق من سقف المناطق وعدم التكرار
     */
    public function addZoneToDriver(Driver $driver, int $zoneId): Driver
    {
        Zone::findOrFail($zoneId);

        if ($driver->zones()->where('zones.id', $zoneId)->exists()) {
            throw new Exception('هذه المنطقة مضافة بالفعل لتفضيلات التغطية الخاصة بك.');
        }

        if ($driver->zones()->count() >= self::MAX_ZONES) {
            throw new Exception('عذراً، لا يمكن إضافة المزيد من المناطق؛ الحد الأقصى المسموح به هو ' . self::MAX_ZONES . ' مناطق.');
        }

        $driver->zones()->syncWithoutDetaching([$zoneId]);

        return $driver->load(['zones.subMunicipality.municipality', 'seatSlots']);
    }

    public function removeZoneFromDriver(Driver $driver, int $zoneId): Driver
    {
        $driver->zones()->detach($zoneId);
        return $driver->load(['zones.subMunicipality.municipality', 'seatSlots']);
    }

    /**
     * جلب هيكل البيانات الجغرافي لبناء القوائم المنسدلة
     */
    public function getSystemDefaults(): array
    {
        return [
            'available_shift_slots' => DriverShift::detailedSlots(),
            'available_subscription_types' => SubscriptionDuration::driverOptions(),
            'available_school_stages'      => SchoolStage::gradeRanges(),
            'geography_tree' => Municipality::with('subMunicipalities.zones')->get()->map(fn($municipality) => [
                'id'   => $municipality->id,
                'name' => $municipality->name,
                'sub_municipalities' => $municipality->subMunicipalities->map(fn($sub) => [
                    'id'    => $sub->id,
                    'name'  => $sub->name,
                    'zones' => $sub->zones->map(fn($zone) => [
                        'id' => $zone->id,
                        'name' => $zone->name
                    ])
                ])
            ])
        ];
    }
}