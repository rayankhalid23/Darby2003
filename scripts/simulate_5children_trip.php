<?php
/**
 * 🚗 محاكي حركة السائق الشامل للرحلات الخماسية (Morning Go & Afternoon Return)
 * 
 * الميزات:
 * 1. في رحلة الذهاب (Go): ينطلق منزل السائق من بعد 500 متر بالضبط عن المحطة الأولى.
 * 2. في رحلة الإياب (Return): تنطلق نقطة السائق من بعد 200 متر بالضبط عن المحطة الأولى.
 * 3. يرسل إحداثيات GPS حية لقاعدة البيانات و Firebase Firestore.
 * 4. يطلق إشعار اقتراب 200م لولي الأمر، وإشعار وصول 50م للمنزل، وإشعار وصول 80م للمدرسة مع عداد الانتظار.
 * 5. يعمل في أي وقت وأي تاريخ 24/7 دون أي قيود زمنية.
 * 
 * الاستخدام:
 * php scripts/simulate_5children_trip.php [TRIP_ID] [--speed=40] [--wait=10] [--auto]
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Services\Trip\TripTrackingService;
use Carbon\Carbon;

// قراءة معطيات سطر الأوامر
$tripId = null;
$speed = 40.0;
$waitTime = 10;
$autoMode = false;

foreach ($argv as $index => $arg) {
    if ($index === 0) continue;
    if (is_numeric($arg)) {
        $tripId = (int) $arg;
    } elseif (str_starts_with($arg, '--speed=')) {
        $speed = (float) substr($arg, 8);
    } elseif (str_starts_with($arg, '--wait=')) {
        $waitTime = (int) substr($arg, 7);
    } elseif ($arg === '--auto') {
        $autoMode = true;
    }
}

if (!$tripId) {
    $trip = Trip::where('driver_id', 908)->orderBy('id', 'desc')->first();
    if (!$trip) {
        echo "❌ لم يتم العثور على رحلة للسائق 908. يرجى إنشاء رحلة أولاً عبر:\n";
        echo "   php scripts/create_5children_trip_go.php\n";
        echo "   أو php scripts/create_5children_trip_return.php\n";
        exit(1);
    }
    $tripId = $trip->id;
} else {
    $trip = Trip::find($tripId);
    if (!$trip) {
        echo "❌ الرحلة رقم #{$tripId} غير موجودة.\n";
        exit(1);
    }
}

$isGoTrip = \App\Models\Driver\DriverSeatSlot::isGoSlot($trip->shift_slot ?? '')
    || (!$trip->shift_slot && ($trip->trip_type === 'Morning' || $trip->trip_type === 'ذهاب'));

$GPS_TICK_SECONDS = 2;

echo "===================================================================\n";
echo "🚀 بدء محاكي حركة السائق للرحلة رقم: #{$trip->id} (" . ($isGoTrip ? 'ذهاب صباحي - Go' : 'إياب مسائي - Return') . ")\n";
echo "📌 السائق ID: {$trip->driver_id} | تاريخ الرحلة: {$trip->trip_date} | الحالة: {$trip->status}\n";
echo "⚙️ إعدادات المحاكاة:\n";
echo "   - السرعة: {$speed} كم/ساعة\n";
echo "   - تردد إرسال GPS: كل {$GPS_TICK_SECONDS} ثوانٍ\n";
echo "   - وقت التوقف عند كل محطة: {$waitTime} ثانية\n";
echo "   - وضع التأكيد: " . ($autoMode ? "تلقائي (--auto)" : "يدوي عبر الـ API لاختبار النظام") . "\n";
if ($isGoTrip) {
    echo "   📍 نقطة انطلاق السائق: تبعد 500 متر عن أول محطة منزل (المطلوب: 500م)\n";
} else {
    echo "   📍 نقطة انطلاق السائق: تبعد 200 متر عن أول محطة مدرسة (المطلوب: 200م)\n";
}
echo "===================================================================\n\n";

$trackingService = app(TripTrackingService::class);

// بدء الرحلة تلقائياً إن كانت معلقة
if ($trip->status === 'pending') {
    echo "🚦 بدء الرحلة رسمياً الآن (Start Trip)...\n";
    $trip->update([
        'status'            => 'in_progress',
        'actual_start_time' => Carbon::now()->format('H:i:s'),
    ]);
    echo "✅ الرحلة أصبحت قيد التنفيذ (in_progress).\n\n";
}

// بناء المسار الجغرافي الواقعي حسب نوع الرحلة
if ($isGoTrip) {
    // 🚌 رحلة الذهاب الصباحية:
    // نقطة الانطلاق تبعد 500م بالضبط عن أول منزل (منزل 1: 32.875500, 13.193500)
    // الإحداثية 32.871000 تبعد 500.4 متر
    $waypoints = [
        ['name' => 'نقطة انطلاق السائق (500م قبل أول منزل)', 'lat' => 32.871000, 'lng' => 13.193500, 'type' => 'start'],
        ['name' => 'منزل 1 (صعود عمر ولينا)', 'lat' => 32.875500, 'lng' => 13.193500, 'type' => 'home_stop', 'child_ids' => [45, 46], 'sub_ids' => [29, 30]],
        ['name' => 'منزل 2 (صعود يوسف)', 'lat' => 32.878000, 'lng' => 13.196500, 'type' => 'home_stop', 'child_ids' => [1], 'sub_ids' => [258]],
        ['name' => 'منزل 3 (صعود أحمد)', 'lat' => 32.881500, 'lng' => 13.193000, 'type' => 'home_stop', 'child_ids' => [3], 'sub_ids' => [259]],
        ['name' => 'منزل 4 (صعود طارق)', 'lat' => 32.885000, 'lng' => 13.198500, 'type' => 'home_stop', 'child_ids' => [4], 'sub_ids' => [260]],
        ['name' => 'مدرسة النصر النموذجية (نزول لينا)', 'lat' => 32.887120, 'lng' => 13.191540, 'type' => 'school_stop', 'school_id' => 1, 'school_name' => 'مدرسة النصر'],
        ['name' => 'مدرسة النوفليين الأهلية (نزول طارق)', 'lat' => 32.889340, 'lng' => 13.205120, 'type' => 'school_stop', 'school_id' => 4, 'school_name' => 'مدرسة النوفليين'],
        ['name' => 'مدرسة زاوية الدهماني (نزول أحمد)', 'lat' => 32.878210, 'lng' => 13.184350, 'type' => 'school_stop', 'school_id' => 3, 'school_name' => 'مدرسة زاوية الدهماني'],
        ['name' => 'مدرسة بن عاشور الابتدائية (نزول عمر ويوسف)', 'lat' => 32.874560, 'lng' => 13.190230, 'type' => 'school_stop', 'school_id' => 2, 'school_name' => 'مدرسة بن عاشور'],
    ];
} else {
    // 🎒 رحلة العودة المسائية:
    // نقطة الانطلاق تبعد 200م بالضبط عن أول مدرسة (مدرسة النوفليين: 32.889340, 13.205120)
    // الإحداثية 32.887540 تبعد 200.1 متر
    $waypoints = [
        ['name' => 'نقطة انطلاق السائق بعد الظهر (200م قبل أول مدرسة)', 'lat' => 32.887540, 'lng' => 13.205120, 'type' => 'start'],
        ['name' => 'مدرسة النوفليين الأهلية (صعود طارق)', 'lat' => 32.889340, 'lng' => 13.205120, 'type' => 'school_stop', 'child_ids' => [4], 'sub_ids' => [260], 'school_name' => 'مدرسة النوفليين'],
        ['name' => 'مدرسة النصر النموذجية (صعود لينا)', 'lat' => 32.887120, 'lng' => 13.191540, 'type' => 'school_stop', 'child_ids' => [46], 'sub_ids' => [30], 'school_name' => 'مدرسة النصر'],
        ['name' => 'مدرسة زاوية الدهماني (صعود أحمد)', 'lat' => 32.878210, 'lng' => 13.184350, 'type' => 'school_stop', 'child_ids' => [3], 'sub_ids' => [259], 'school_name' => 'مدرسة زاوية الدهماني'],
        ['name' => 'مدرسة بن عاشور الابتدائية (صعود عمر ويوسف)', 'lat' => 32.874560, 'lng' => 13.190230, 'type' => 'school_stop', 'child_ids' => [45, 1], 'sub_ids' => [29, 258], 'school_name' => 'مدرسة بن عاشور'],
        ['name' => 'منزل 4 (نزول طارق)', 'lat' => 32.885000, 'lng' => 13.198500, 'type' => 'home_stop', 'child_ids' => [4], 'sub_ids' => [260]],
        ['name' => 'منزل 3 (نزول أحمد)', 'lat' => 32.881500, 'lng' => 13.193000, 'type' => 'home_stop', 'child_ids' => [3], 'sub_ids' => [259]],
        ['name' => 'منزل 2 (نزول يوسف)', 'lat' => 32.878000, 'lng' => 13.196500, 'type' => 'home_stop', 'child_ids' => [1], 'sub_ids' => [258]],
        ['name' => 'منزل 1 (نزول عمر ولينا)', 'lat' => 32.875500, 'lng' => 13.193500, 'type' => 'home_stop', 'child_ids' => [45, 46], 'sub_ids' => [29, 30]],
    ];
}

function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371; // km
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadius * $c;
}

function calculateHeading($lat1, $lon1, $lat2, $lon2) {
    $dLon = deg2rad($lon2 - $lon1);
    $y = sin($dLon) * cos(deg2rad($lat2));
    $x = cos(deg2rad($lat1)) * sin(deg2rad($lat2)) - sin(deg2rad($lat1)) * cos(deg2rad($lat2)) * cos($dLon);
    $brng = rad2deg(atan2($y, $x));
    return ($brng + 360) % 360;
}

$currentStep = 0;
$totalWaypoints = count($waypoints);

for ($i = 0; $i < $totalWaypoints - 1; $i++) {
    $from = $waypoints[$i];
    $to = $waypoints[$i + 1];

    $distKm = calculateDistance($from['lat'], $from['lng'], $to['lat'], $to['lng']);
    $distMeters = $distKm * 1000;

    $travelTimeHours = $distKm / $speed;
    $travelTimeSeconds = max(1, $travelTimeHours * 3600);
    $steps = max(1, (int) round($travelTimeSeconds / $GPS_TICK_SECONDS));

    $heading = calculateHeading($from['lat'], $from['lng'], $to['lat'], $to['lng']);

    echo "🚗 التحرك من [{$from['name']}] إلى [{$to['name']}] (المسافة: " . round($distMeters) . " متر)...\n";

    for ($step = 1; $step <= $steps; $step++) {
        $fraction = $step / $steps;
        $curLat = $from['lat'] + ($to['lat'] - $from['lat']) * $fraction;
        $curLng = $from['lng'] + ($to['lng'] - $from['lng']) * $fraction;

        $trackingRes = $trackingService->updateDriverLocation(
            $trip->id,
            $curLat,
            $curLng,
            $speed,
            $heading
        );

        $tickMsg = "   📍 GPS [Tick " . (++$currentStep) . "]: Lat=" . number_format($curLat, 6) . ", Lng=" . number_format($curLng, 6) . " | سرعة: {$speed} كم/س";

        if (!empty($trackingRes['proximity']['alerts'])) {
            foreach ($trackingRes['proximity']['alerts'] as $alert) {
                if ($alert['type'] === 'approaching_home') {
                    $tickMsg .= " | 🔔 [إشعار اقتراب 200م لولي الأمر: {$alert['child_name']}]";
                } elseif ($alert['type'] === 'arrived_home') {
                    $tickMsg .= " | 🏠 [إشعار وصول للمنزل (ولي الأمر + السائق)]";
                } elseif ($alert['type'] === 'arrived_school') {
                    $tickMsg .= " | 🏫 [إشعار وصول للمدرسة: {$alert['school_name']}]";
                }
            }
        }

        echo $tickMsg . "\n";
        sleep($GPS_TICK_SECONDS);
    }

    // إذا وصلنا إلى محطة توقف (منزل أو مدرسة)
    if (in_array($to['type'], ['home_stop', 'school_stop'])) {
        echo "\n🛑 وصل السائق إلى: [{$to['name']}]\n";

        // إرسال نبضة توقف بسرعة صفر
        $trackingService->updateDriverLocation($trip->id, $to['lat'], $to['lng'], 0.0, $heading);

        if ($to['type'] === 'home_stop') {
            if ($isGoTrip) {
                echo "📢 إشعار وصول للمنزل: تم إخطار ولي الأمر والسائق (عداد الانتظار: 10 دقائق).\n";
                echo "💡 للاختبار اليدوي عبر الـ API (تأكيد الصعود):\n";
                foreach ($to['sub_ids'] as $idx => $sId) {
                    echo "   POST /api/driver/trips/{$trip->id}/pickup\n";
                    echo "   Body: {\"trip_child_id\": {$sId}, \"latitude\": {$to['lat']}, \"longitude\": {$to['lng']}}\n";
                }
                if ($autoMode) {
                    TripStop::where('trip_id', $trip->id)->whereIn('child_id', $to['child_ids'])->where('stop_type', 'home')->update(['status' => 'boarded']);
                    echo "   🎒 [وضع تلقائي] تم تسجيل صعود الطلاب.\n";
                }
            } else {
                echo "📢 إشعار وصول للمنزل: تم إخطار ولي الأمر والسائق لتسليم ونزول الطلاب.\n";
                echo "💡 للاختبار اليدوي عبر الـ API (تأكيد النزول):\n";
                foreach ($to['sub_ids'] as $idx => $sId) {
                    echo "   POST /api/driver/trips/{$trip->id}/dropoff\n";
                    echo "   Body: {\"trip_child_id\": {$sId}, \"latitude\": {$to['lat']}, \"longitude\": {$to['lng']}}\n";
                }
                if ($autoMode) {
                    TripStop::where('trip_id', $trip->id)->whereIn('child_id', $to['child_ids'])->where('stop_type', 'home')->update(['status' => 'delivered_home']);
                    echo "   🏡 [وضع تلقائي] تم تسجيل تسليم ونزول الطلاب.\n";
                }
            }
        } elseif ($to['type'] === 'school_stop') {
            if ($isGoTrip) {
                echo "📢 إشعار وصول للمدرسة ({$to['school_name']}): يرجى تأكيد نزول الطلاب.\n";
                echo "💡 للاختبار اليدوي: POST /api/driver/trips/{$trip->id}/dropoff (مع إحداثيات المدرسة)\n";
                if ($autoMode) {
                    TripStop::where('trip_id', $trip->id)->where('school_id', $to['school_id'] ?? null)->update(['status' => 'dropped_off_school']);
                    echo "   🏫 [وضع تلقائي] تم تسجيل نزول الطلاب بالمدرسة.\n";
                }
            } else {
                echo "📢 إشعار وصول للمدرسة ({$to['school_name']}): عداد الانتظار 15 دقيقة لصعود الطلاب.\n";
                echo "💡 للاختبار اليدوي عبر الـ API (صعود من المدرسة):\n";
                if (!empty($to['sub_ids'])) {
                    foreach ($to['sub_ids'] as $sId) {
                        echo "   POST /api/driver/trips/{$trip->id}/pickup  |  trip_child_id: {$sId}\n";
                        echo "   (أو للتخطي إذا غاب: POST /api/driver/trips/{$trip->id}/skip/{$sId})\n";
                    }
                }
                if ($autoMode && !empty($to['child_ids'])) {
                    TripStop::where('trip_id', $trip->id)->whereIn('child_id', $to['child_ids'])->where('stop_type', 'home')->update(['status' => 'boarded']);
                    echo "   🎒 [وضع تلقائي] تم تسجيل صعود الطلاب من المدرسة.\n";
                }
            }
        }

        echo "⏳ التوقف مؤقتاً لمدة {$waitTime} ثانية...\n";
        sleep($waitTime);
        echo "▶️ استئناف السير...\n\n";
    }
}

echo "===================================================================\n";
echo "🎉 اكتمل مسار الرحلة بالكامل بنجاح!\n";
echo "💡 يمكنك الآن إنهاء الرحلة عبر الـ API:\n";
echo "   POST /api/driver/trips/{$trip->id}/complete\n";
echo "===================================================================\n";
