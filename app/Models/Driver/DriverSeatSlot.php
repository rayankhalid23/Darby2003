<?php

namespace App\Models\Driver;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverSeatSlot extends Model
{
    use HasFactory;

    protected $table = 'driver_seat_slots';

    const MORNING_GO       = 'morning_go';
    const MORNING_RETURN   = 'morning_return';
    const AFTERNOON_GO     = 'afternoon_go';
    const AFTERNOON_RETURN = 'afternoon_return';

    const ALL_SLOTS = [
        self::MORNING_GO,
        self::MORNING_RETURN,
        self::AFTERNOON_GO,
        self::AFTERNOON_RETURN,
    ];

    protected $fillable = [
        'driver_id',
        'slot',
        'date',
        'booked',
    ];

    protected $casts = [
        'date'   => 'date',
        'booked' => 'integer',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    // =====================================================================
    // resolveSlots / slotLabels / isGoSlot — تبقى كما هي
    // =====================================================================

    public static function resolveSlots(string $timing, string $direction): array
    {
        $timingUp = strtoupper($timing);
        $dirLow   = strtolower($direction);

        $requiredSlots = [];

        if (in_array($timingUp, ['MORNING', 'BOTH'])) {
            if (in_array($dirLow, ['go', 'both']))     $requiredSlots[] = self::MORNING_GO;
            if (in_array($dirLow, ['return', 'both']))  $requiredSlots[] = self::MORNING_RETURN;
        }
        if (in_array($timingUp, ['EVENING', 'AFTERNOON', 'BOTH'])) {
            if (in_array($dirLow, ['go', 'both']))     $requiredSlots[] = self::AFTERNOON_GO;
            if (in_array($dirLow, ['return', 'both']))  $requiredSlots[] = self::AFTERNOON_RETURN;
        }

        return $requiredSlots;
    }

    /**
     * تحويل تفضيل الطفل المخزَّن (children.preferred_time_slot) إلى مفردات الفترة
     * التي تفهمها resolveSlots.
     *
     * ⚠️ هذه هي نقطة التحويل الوحيدة المسموح بها: أي تخمين للفترة خارجها ينتهي
     * بفلترة على رحلة وحجز في أخرى. القيمة غير المعروفة تُعيد null ليتعامل معها
     * المستدعي صراحةً بدل الوقوع على فترة افتراضية صامتة.
     */
    public static function timingFromPreferredSlot(?string $preferredTimeSlot): ?string
    {
        return match (strtolower(trim((string) $preferredTimeSlot))) {
            'morning'            => 'MORNING',
            'evening', 'afternoon' => 'EVENING',
            'both'               => 'BOTH',
            default              => null,
        };
    }

    public static function slotLabels(): array
    {
        return [
            self::MORNING_GO       => 'صباحي - ذهاب',
            self::MORNING_RETURN   => 'صباحي - إياب',
            self::AFTERNOON_GO     => 'مسائي - ذهاب',
            self::AFTERNOON_RETURN => 'مسائي - إياب',
        ];
    }

    public static function isGoSlot(string $slot): bool
    {
        return in_array($slot, [self::MORNING_GO, self::AFTERNOON_GO], true);
    }

    // =====================================================================
    // منطق الخانة الذرية (driver × slot × date)
    // =====================================================================

    /**
     * المقاعد المتاحة لخانة واحدة (driver, slot, date).
     *
     * القواعد:
     *  1. يوم غياب السائق → 0 (طاقة = 0)
     *  2. لا يوجد صف (لم يحجز أحد) → capacity كاملة
     *  3. يوجد صف → max(0, capacity - booked)
     */
    public static function available(
        int    $driverId,
        string $slot,
        string $date,
        int    $capacity
    ): int {
        // القاعدة 1: غياب السائق
        $isAbsent = DriverAbsence::where('driver_id', $driverId)
            ->whereDate('absence_date', $date)
            ->exists();

        if ($isAbsent) {
            return 0;
        }

        // القاعدة 2 و 3
        $booked = self::where('driver_id', $driverId)
            ->where('slot', $slot)
            ->where('date', $date)
            ->value('booked') ?? 0;

        return max(0, $capacity - $booked);
    }

    /**
     * أدنى مقعد متاح عبر كل الخانات (slots × dates) في فترة الاشتراك.
     *
     * يُستخدم في قاعدة القبول:
     *   مقبول ⟺ minAvailable ≥ childrenCount
     *
     * @param int    $driverId
     * @param array  $slots      ['morning_go', 'morning_return', ...]
     * @param string $startDate  'Y-m-d'
     * @param string $endDate    'Y-m-d'
     * @param int    $capacity   capacity_manual للمركبة
     * @return int
     */
    public static function minAvailableOverPeriod(
        int    $driverId,
        array  $slots,
        string $startDate,
        string $endDate,
        int    $capacity
    ): int {
        if (empty($slots) || $capacity <= 0) {
            return 0;
        }

        // جلب أيام الغياب في الفترة دفعة واحدة
        $absenceDates = DriverAbsence::where('driver_id', $driverId)
            ->whereBetween('absence_date', [$startDate, $endDate])
            ->pluck('absence_date')
            ->map(fn($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : (string) $d)
            ->flip()
            ->all(); // ['2026-09-09' => 0, ...]

        // جلب الصفوف الموجودة في الفترة دفعة واحدة
        $bookedRows = self::where('driver_id', $driverId)
            ->whereIn('slot', $slots)
            ->whereBetween('date', [$startDate, $endDate])
            ->get(['slot', 'date', 'booked'])
            ->groupBy(fn($r) => $r->slot . '|' . ($r->date instanceof \DateTimeInterface ? $r->date->format('Y-m-d') : substr((string)$r->date, 0, 10)));
        // key: 'morning_go|2026-09-09'

        $min = $capacity; // ابدأ من الأعلى وانزل

        $start = Carbon::parse($startDate)->startOfDay();
        $end   = Carbon::parse($endDate)->startOfDay();
        $cur   = $start->copy();

        while ($cur->lte($end)) {
            // تجاهل الجمعة والسبت (أيام إجازة)
            if (!$cur->isFriday() && !$cur->isSaturday()) {
                $dayStr = $cur->toDateString();

                foreach ($slots as $slot) {
                    if (isset($absenceDates[$dayStr])) {
                        // غياب → 0
                        $min = 0;
                        // لا فائدة من الاستمرار
                        return 0;
                    }

                    $key    = $slot . '|' . $dayStr;
                    $booked = isset($bookedRows[$key]) ? (int) $bookedRows[$key]->first()->booked : 0;
                    $avail  = max(0, $capacity - $booked);

                    if ($avail < $min) {
                        $min = $avail;
                    }
                }
            }
            $cur->addDay();
        }

        return $min;
    }

    /**
     * يزيد booked لخانة (driver, slot, date) بمقدار 1.
     * ينشئ الصف إذا لم يكن موجوداً (booked = 1).
     */
    public static function incrementBooked(int $driverId, string $slot, string $date): void
    {
        $seatSlot = self::firstOrCreate(
            ['driver_id' => $driverId, 'slot' => $slot, 'date' => $date],
            ['booked' => 0]
        );
        $seatSlot->increment('booked');
    }

    /**
     * يخفض booked لخانة (driver, slot, date) بمقدار 1.
     * إذا أصبح booked = 0 يحذف الصف (تنظيف — خانة فارغة = لا صف).
     */
    public static function decrementBooked(int $driverId, string $slot, string $date): void
    {
        $seatSlot = self::where('driver_id', $driverId)
            ->where('slot', $slot)
            ->where('date', $date)
            ->first();

        if (!$seatSlot) {
            return;
        }

        if ($seatSlot->booked <= 1) {
            $seatSlot->delete(); // خانة فارغة = لا صف
        } else {
            $seatSlot->decrement('booked');
        }
    }
}
