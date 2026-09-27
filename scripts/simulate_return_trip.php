<?php
/**
 * سكربت محاكاة حركة السائق وإرسال GPS لرحلة العودة (Afternoon Return)
 * من المدارس إلى منازل الطلاب مع إشعارات الاقتراب والوصول التلقائية.
 * 
 * الاستخدام:
 * php scripts/simulate_return_trip.php [TRIP_ID] [--auto]
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Services\Trip\TripTrackingService;
use App\Services\Trip\TripLifecycleService;
use Carbon\Carbon;

$tripId = $argv[1] ?? null;
$autoFlag = in_array('--auto', $argv);

if (!$tripId || !is_numeric($tripId)) {
    // البحث عن أحدث رحلة إياب للسائق 908
    $trip = Trip::where('driver_id', 908)
        ->where(function ($q) {
            $q->where('shift_slot', 'afternoon_return')
              ->orWhere('trip_type', 'Afternoon');
        })
        ->orderBy('id', 'desc')
        ->first();

    if (!$trip) {
        echo "❌ لم يتم العثور على رحلة عودة للسائق 908. يرجى إنشاء واحدة أولاً عبر:\n";
        echo "   php scripts/create_test_return_trip.php\n";
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

// إعدادات المحاكاة القابلة للتحكم
$DRIVING_SPEED_KMH  = 40.0; // سرعة السير (كم/ساعة)
$GPS_TICK_SECONDS   = 2;    // تردد إرسال GPS كل ثانيتين
$STOP_WAIT_SECONDS  = 15;   // مدة الانتظار عند كل محطة (ثانية)
$AUTO_CONFIRM_STOPS = $autoFlag; // تأكيد الصعود/النزول تلقائياً أم انتظار التجربة اليدوية

echo "===================================================================\n";
echo "🚀 بدء محاكي حركة السائق لرحلة العودة (Return Trip) رقم: #{$trip->id}\n";
echo "📌 السائق ID: {$trip->driver_id} | التاريخ: {$trip->trip_date} | الحالة: {$trip->status}\n";
echo "⚙️ إعدادات المحاكاة:\n";
echo "   - السرعة: {$DRIVING_SPEED_KMH} كم/ساعة\n";
echo "   - تردد إرسال GPS: كل {$GPS_TICK_SECONDS} ثوانٍ\n";
echo "   - مدة التوقف عند المحطة: {$STOP_WAIT_SECONDS} ثانية\n";
echo "   - وضع التأكيد: " . ($AUTO_CONFIRM_STOPS ? "تلقائي (--auto)" : "يدوي عبر الـ API لتمكين الاختبار") . "\n";
echo "===================================================================\n\n";

$trackingService = app(TripTrackingService::class);
$lifecycleService = app(TripLifecycleService::class);

// بدء الرحلة إن كانت معلقة
if ($trip->status === 'pending') {
    echo "🚦 بدء الرحلة رسمياً الآن (Start Trip)...\n";
    $trip->update([
        'status'            => 'in_progress',
        'actual_start_time' => Carbon::now()->format('H:i:s'),
    ]);
    echo "✅ الرحلة أصبحت قيد التنفيذ (in_progress).\n\n";
}

// مسار رحلة العودة الواقعي:
// 1. التوجه إلى مدرسة بن عاشور الابتدائية (عمر) - 32.874560, 13.190230
// 2. التحرك نحو مدرسة النصر النموذجية (لينا) - 32.887120, 13.191540
// 3. الرجوع جنوباً باتجاه منطقة بن عاشور
// 4. المرور بنقطة الاقتراب (أقل من 200م من المنزل) - 32.876500, 13.193000
// 5. الوصول لمنزل الطلاب - 32.875500, 13.193500

$waypoints = [
    ['name' => 'نقطة انطلاق السائق بعد الظهر', 'lat' => 32.872500, 'lng' => 13.193000, 'type' => 'drive'],
    ['name' => 'مدرسة بن عاشور الابتدائية (صعود عمر)', 'lat' => 32.874560, 'lng' => 13.190230, 'type' => 'school_1_stop', 'child' => 'عمر', 'sub_id' => 29],
    ['name' => 'طريق النصر باتجاه الشمال', 'lat' => 32.880500, 'lng' => 13.190800, 'type' => 'drive'],
    ['name' => 'شارع النصر الأوسط', 'lat' => 32.884500, 'lng' => 13.191200, 'type' => 'drive'],
    ['name' => 'مدرسة النصر النموذجية (صعود لينا)', 'lat' => 32.887120, 'lng' => 13.191540, 'type' => 'school_2_stop', 'child' => 'لينا', 'sub_id' => 30],
    ['name' => 'شارع النصر جنوباً في طريق الرجوع', 'lat' => 32.882000, 'lng' => 13.191000, 'type' => 'drive'],
    ['name' => 'تقاطع بن عاشور (اقتراب 200م من المنزل)', 'lat' => 32.876500, 'lng' => 13.193000, 'type' => 'approach_home'],
    ['name' => 'منزل الطلاب (إنزال عمر ولينا)', 'lat' => 32.875500, 'lng' => 13.193500, 'type' => 'home_stop'],
];

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

    $travelTimeHours = $distKm / $DRIVING_SPEED_KMH;
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
            $DRIVING_SPEED_KMH,
            $heading
        );

        $tickMsg = "   📍 GPS [Tick " . (++$currentStep) . "]: Lat=" . number_format($curLat, 6) . ", Lng=" . number_format($curLng, 6) . " | سرعة: {$DRIVING_SPEED_KMH} كم/س";

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

    // التوقف عند محطات الصعود (المدارس) ومحطات النزول (المنزل)
    if (in_array($to['type'], ['school_1_stop', 'school_2_stop', 'home_stop'])) {
        echo "\n🛑 وصل السائق إلى: [{$to['name']}]\n";

        // إرسال نقطة التوقف بسرعة صفر
        $stopRes = $trackingService->updateDriverLocation(
            $trip->id,
            $to['lat'],
            $to['lng'],
            0.0,
            $heading
        );

        if ($to['type'] === 'school_1_stop') {
            echo "📢 إشعار السائق: لقد وصلت إلى مدرسة بن عاشور الابتدائية (الحد الأقصى للانتظار 15 دقيقة).\n";
            echo "💡 للاختبار اليدوي: يمكنك تأكيد صعود عمر عبر:\n";
            echo "   POST /api/driver/trips/{$trip->id}/pickup\n";
            echo "   Body: {\"trip_child_id\": 29, \"latitude\": 32.874560, \"longitude\": 13.190230}\n";
            echo "   (أو تخطيه: POST /api/driver/trips/{$trip->id}/skip/29)\n";

            if ($AUTO_CONFIRM_STOPS) {
                echo "   🎒 [وضع تلقائي] تم تسجيل صعود عمر بنجاح.\n";
                TripStop::where('trip_id', $trip->id)->where('child_id', 45)->where('stop_type', 'home')->update(['status' => 'boarded']);
            }
        } elseif ($to['type'] === 'school_2_stop') {
            echo "📢 إشعار السائق: لقد وصلت إلى مدرسة النصر النموذجية (الحد الأقصى للانتظار 15 دقيقة).\n";
            echo "💡 للاختبار اليدوي: يمكنك تأكيد صعود لينا عبر:\n";
            echo "   POST /api/driver/trips/{$trip->id}/pickup\n";
            echo "   Body: {\"trip_child_id\": 30, \"latitude\": 32.887120, \"longitude\": 13.191540}\n";
            echo "   (أو تخطيها: POST /api/driver/trips/{$trip->id}/skip/30)\n";

            if ($AUTO_CONFIRM_STOPS) {
                echo "   🎒 [وضع تلقائي] تم تسجيل صعود لينا بنجاح.\n";
                TripStop::where('trip_id', $trip->id)->where('child_id', 46)->where('stop_type', 'home')->update(['status' => 'boarded']);
            }
        } elseif ($to['type'] === 'home_stop') {
            echo "📢 إشعار ولي الأمر: وصل السائق إلى موقع منزلكم لإيصال ونزول الطلاب بسلام.\n";
            echo "📢 إشعار السائق: لقد وصلت إلى منزل الطلاب، يرجى تأكيد تسليمهم.\n";
            echo "💡 للاختبار اليدوي: يمكنك تأكيد النزول عبر:\n";
            echo "   POST /api/driver/trips/{$trip->id}/dropoff (لعمر: trip_child_id: 29 | لينا: trip_child_id: 30)\n";

            if ($AUTO_CONFIRM_STOPS) {
                echo "   🏡 [وضع تلقائي] تم تسجيل نزول عمر ولينا بنجاح.\n";
                TripStop::where('trip_id', $trip->id)->where('stop_type', 'home')->update(['status' => 'delivered_home']);
            }
        }

        echo "⏳ التوقف مؤقتاً لمدة {$STOP_WAIT_SECONDS} ثانية...\n";
        sleep($STOP_WAIT_SECONDS);
        echo "▶️ استئناف السير...\n\n";
    }
}

echo "===================================================================\n";
echo "🎉 انتهى مسار رحلة العودة بنجاح!\n";
echo "💡 يمكنك الآن إنهاء الرحلة عبر:\n";
echo "   POST /api/driver/trips/{$trip->id}/complete\n";
echo "===================================================================\n";
