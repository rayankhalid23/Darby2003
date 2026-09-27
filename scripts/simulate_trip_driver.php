<?php
/**
 * ============================================================================
 * محاكي حركة السائق اللحظية وإرسال الإحداثيات لـ Firebase وقاعدة البيانات
 * Driver Route GPS & Firebase Simulator
 * ============================================================================
 * 
 * المسار الافتراضي المجهز:
 * - السائق ID: 908 (المسار ID: 11 - منطقة بن عاشور، طرابلس)
 * - الطلاب: عمر (Child ID: 45) و لينا (Child ID: 46)
 * - محطة المنزل: (32.875500, 13.193500)
 * - المدرسة 1 (عمر): مدرسة بن عاشور (32.874560, 13.190230)
 * - المدرسة 2 (لينا): مدرسة النصر (32.887120, 13.191540)
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Models\Driver\Driver;
use App\Services\Trip\TripTrackingService;
use App\Services\Trip\TripLifecycleService;
use Carbon\Carbon;

// ============================================================================
// ⚙️ إعدادات التحكم بالسرعة ومعدل التنقل (Speed & Simulation Controls)
// يمكنك تغيير هذه القيم بحرية لتسريع أو إبطاء الاختبار:
// ============================================================================

// 1. سرعة السائق الافتراضية أثناء القيادة (كم/ساعة)
$DRIVING_SPEED_KMH = 40.0;

// 2. الفاصل الزمني بين كل إرسال نبضة GPS والتالية (بالثواني)
// في التطبيق الحقيقي يكون بين 3 إلى 5 ثوانٍ
$GPS_TICK_SECONDS = 2;

// 3. مدة توقف السائق عند كل محطة (صعود الأطفال / نزول المدرسة)
// في الواقع: 15 دقيقة = 900 ثانية.
// للاختبار السريع جداً: اضبطها على 5 أو 10 ثوانٍ!
$STOP_WAIT_SECONDS = 10; // ⏱️ غيرها إلى 900 للمحاكاة الكاملة (15 دقيقة) أو اتركها 10 ثوانٍ للاختبار السريع

// 4. هل يقوم السكربت بتأكيد صعود ونزول الأطفال تلقائياً عند الوصول للنطاق الجغرافي؟
$AUTO_CONFIRM_STOPS = true;

// ============================================================================

// استقبال tripId من سطر الأوامر إن وجد، أو البحث عن آخر رحلة نشطة أو معلقة
$tripId = $argv[1] ?? null;

if (!$tripId) {
    $trip = Trip::where('driver_id', 908)
        ->whereIn('status', ['pending', 'in_progress'])
        ->orderBy('id', 'desc')
        ->first();

    if (!$trip) {
        $trip = Trip::whereIn('status', ['pending', 'in_progress'])
            ->orderBy('id', 'desc')
            ->first();
    }

    if (!$trip) {
        echo "❌ لم يتم العثور على رحلة جارية أو معلقة. يرجى تمرير trip_id كمعامل:\n";
        echo "   php scripts/simulate_trip_driver.php <TRIP_ID>\n";
        exit(1);
    }
    $tripId = $trip->id;
} else {
    $trip = Trip::findOrFail($tripId);
}

echo "===================================================================\n";
echo "🚀 بدء محاكي حركة السائق للرحلة رقم: #{$trip->id}\n";
echo "📌 السائق ID: {$trip->driver_id} | تاريخ الرحلة: {$trip->trip_date} | الحالة: {$trip->status}\n";
echo "⚙️ إعدادات المحاكاة:\n";
echo "   - السرعة: {$DRIVING_SPEED_KMH} كم/ساعة\n";
echo "   - تردد إرسال GPS: كل {$GPS_TICK_SECONDS} ثوانٍ\n";
echo "   - وقت التوقف عند كل محطة: {$STOP_WAIT_SECONDS} ثانية\n";
echo "===================================================================\n\n";

$trackingService = app(TripTrackingService::class);
$lifecycleService = app(TripLifecycleService::class);

// إذا كانت الرحلة معلقة، نقوم ببدئها
if ($trip->status === 'pending') {
    echo "🚦 بدء الرحلة رسمياً الآن (Start Trip)...\n";
    $trip->update([
        'status'            => 'in_progress',
        'actual_start_time' => Carbon::now()->format('H:i:s'),
    ]);
    echo "✅ الرحلة أصبحت قيد التنفيذ (in_progress).\n\n";
}

// مسار الرحلة الواقعي (محدد بنقاط جغرافية دقيقة في بن عاشور):
// Stop 1: انطلاق السائق من موقع قريب (قرب جامع بن عاشور)
// Stop 2: الوصول لمنزل الطلاب (32.875500, 13.193500)
// Stop 3: الانتقال إلى مدرسة بن عاشور (32.874560, 13.190230)
// Stop 4: الانتقال إلى مدرسة النصر (32.887120, 13.191540)

$waypoints = [
    ['name' => 'نقطة انطلاق السائق', 'lat' => 32.872500, 'lng' => 13.196500, 'type' => 'drive'],
    ['name' => 'شارع بن عاشور الرئيسي', 'lat' => 32.873800, 'lng' => 13.195100, 'type' => 'drive'],
    ['name' => 'منزل الطلاب (عمر ولينا)', 'lat' => 32.875500, 'lng' => 13.193500, 'type' => 'home_stop'],
    ['name' => 'تقاطع بن عاشور الغربي', 'lat' => 32.875100, 'lng' => 13.191800, 'type' => 'drive'],
    ['name' => 'مدرسة بن عاشور الابتدائية (عمر)', 'lat' => 32.874560, 'lng' => 13.190230, 'type' => 'school_1_stop'],
    ['name' => 'طريق النصر باتجاه الشمال', 'lat' => 32.880500, 'lng' => 13.190800, 'type' => 'drive'],
    ['name' => 'شارع النصر الأوسط', 'lat' => 32.884500, 'lng' => 13.191200, 'type' => 'drive'],
    ['name' => 'مدرسة النصر النموذجية (لينا)', 'lat' => 32.887120, 'lng' => 13.191540, 'type' => 'school_2_stop'],
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
    $heading = calculateHeading($from['lat'], $from['lng'], $to['lat'], $to['lng']);

    // عدد الخطوات بين النقطتين اعتماداً على السرعة وتردد GPS
    $speedKmPerSec = $DRIVING_SPEED_KMH / 3600.0;
    $stepDistKm = $speedKmPerSec * $GPS_TICK_SECONDS;
    $steps = max(2, (int) ceil($distKm / $stepDistKm));

    echo "🚗 التحرك من [{$from['name']}] إلى [{$to['name']}] (المسافة: " . round($distKm * 1000) . " متر)...\n";

    for ($s = 0; $s <= $steps; $s++) {
        $fraction = $s / $steps;
        $curLat = $from['lat'] + ($to['lat'] - $from['lat']) * $fraction;
        $curLng = $from['lng'] + ($to['lng'] - $from['lng']) * $fraction;

        // إرسال الإحداثيات للخدمة (تكتب في Database + Firestore)
        $res = $trackingService->updateDriverLocation(
            $trip->id,
            $curLat,
            $curLng,
            $DRIVING_SPEED_KMH,
            $heading
        );

        $tickLog = "   📍 GPS [Tick " . (++$currentStep) . "]: Lat=" . number_format($curLat, 6) . ", Lng=" . number_format($curLng, 6) . " | سرعة: {$DRIVING_SPEED_KMH} كم/س";

        if (!empty($res['proximity']['alerts'])) {
            foreach ($res['proximity']['alerts'] as $alert) {
                if ($alert['type'] === 'approaching_home') {
                    $tickLog .= " | 🔔 [إشعار اقتراب 200م لولي الأمر: {$alert['child_name']}]";
                } elseif ($alert['type'] === 'arrived_home') {
                    $tickLog .= " | 🏠 [إشعار وصول للمنزل (ولي الأمر + السائق)]";
                } elseif ($alert['type'] === 'arrived_school') {
                    $tickLog .= " | 🏫 [إشعار وصول للمدرسة: {$alert['school_name']}]";
                }
            }
        }

        echo $tickLog . "\n";

        sleep($GPS_TICK_SECONDS);
    }

    // إذا وصلنا إلى محطة توقف (منزل أو مدرسة)
    if (in_array($to['type'], ['home_stop', 'school_1_stop', 'school_2_stop'])) {
        echo "\n🛑 وصل السائق إلى: [{$to['name']}]\n";
        echo "⏳ التوقف لمدة {$STOP_WAIT_SECONDS} ثانية (محاكاة صعود/نزول الطلاب)...\n";

        // إرسال نقطة توقف بسرعة صفر لـ Firebase
        $trackingService->updateDriverLocation(
            $trip->id,
            $to['lat'],
            $to['lng'],
            0.0,
            $heading
        );

        if ($AUTO_CONFIRM_STOPS) {
            if ($to['type'] === 'home_stop') {
                echo "   🎒 تأكيد صعود الطلاب (عمر ولينا) من المنزل...\n";
                TripStop::where('trip_id', $trip->id)
                    ->where('stop_type', TripStop::TYPE_HOME)
                    ->update([
                        'status' => TripStop::STATUS_BOARDED,
                    ]);
                echo "   ✅ تم تسجيل صعود الطلاب بنجاح.\n";
            } elseif ($to['type'] === 'school_1_stop') {
                echo "   🏫 تأكيد نزول الطالب (عمر) في مدرسة بن عاشور...\n";
                TripStop::where('trip_id', $trip->id)
                    ->where('stop_type', TripStop::TYPE_SCHOOL)
                    ->where('school_id', 2)
                    ->update([
                        'status' => TripStop::STATUS_DROPPED_OFF_SCHOOL,
                    ]);
                echo "   ✅ تم تسجيل نزول عمر بنجاح.\n";
            } elseif ($to['type'] === 'school_2_stop') {
                echo "   🏫 تأكيد نزول الطالبة (لينا) في مدرسة النصر...\n";
                TripStop::where('trip_id', $trip->id)
                    ->where('stop_type', TripStop::TYPE_SCHOOL)
                    ->where('school_id', 1)
                    ->update([
                        'status' => TripStop::STATUS_DROPPED_OFF_SCHOOL,
                    ]);
                echo "   ✅ تم تسجيل نزول لينا بنجاح.\n";
            }
        }

        sleep($STOP_WAIT_SECONDS);
        echo "▶️ استئناف الرحلة...\n\n";
    }
}

echo "===================================================================\n";
echo "🎉 اكتمل المسار ووصلت الحافلة لجميع المحطات بنجاح!\n";
echo "🏁 إغلاق الرحلة رسمياً (Complete Trip)...\n";

try {
    $completeResult = $lifecycleService->completeTrip($trip->id);
    echo "✅ " . ($completeResult['message'] ?? 'تم إنهاء الرحلة بنجاح.') . "\n";
} catch (Throwable $e) {
    // تحديث مباشر في حال وجود استثناء مالي أو إحصائي
    $trip->update([
        'status'            => 'completed',
        'actual_end_time'   => Carbon::now()->format('H:i:s'),
    ]);
    $trackingService->markTripOfflineInFirestore($trip->id);
    echo "✅ تم وسم الرحلة كمكتملة وتحديث Firebase كـ Offline.\n";
}

echo "===================================================================\n";
echo "✨ انتهت المحاكاة بنجاح تام.\n";
