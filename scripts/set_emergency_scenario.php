<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Models\Driver\Driver;
use Carbon\Carbon;

$scenario = (int) ($argv[1] ?? 2); // 1: Before pickups, 2: Midway (2 boarded), 3: All boarded

$trip = Trip::find(1414);
if (!$trip) {
    echo "الرحلة 1414 غير موجودة!\n";
    exit(1);
}

// إعادة حالة الرحلة إلى قيد التشغيل (in_progress)
$trip->update([
    'status' => 'in_progress',
    'completed_at' => null,
]);

// حذف أي بلاغات طوارئ سابقة للرحلة لإعادة الاختبار نظيفاً
\App\Models\Shared\TripBreakdownDispatch::where('trip_id', $trip->id)->delete();

$stops = TripStop::where('trip_id', $trip->id)->orderBy('sequence_order')->get();

echo "========================================================================\n";
echo "🔄 تهيئة الرحلة رقم #{$trip->id} للسيناريو رقم: {$scenario}\n";
echo "========================================================================\n";

if ($scenario === 1) {
    echo "📍 السيناريو 1: تعطل الحافلة قبل أخذ أي طفل (0 أطفال بالحافلة)\n";
    foreach ($stops as $stop) {
        $stop->update(['status' => TripStop::STATUS_PENDING]);
    }
    Driver::where('id', $trip->driver_id)->update([
        'current_lat' => 32.884500,
        'current_lng' => 13.184500,
    ]);
    echo "👉 إحداثيات موقع العطل المقترحة للفرونت:\n";
    echo "   Latitude: 32.884500\n";
    echo "   Longitude: 13.184500\n";
    echo "   الأطفال بالحافلة: 0 | الأطفال بالانتظار: 4\n";

} elseif ($scenario === 2) {
    echo "📍 السيناريو 2: تعطل الحافلة بعد صعود طفلين (2 أطفال بالحافلة و 2 في الانتظار)\n";
    foreach ($stops as $stop) {
        if ($stop->sequence_order <= 2) {
            $stop->update(['status' => TripStop::STATUS_BOARDED]);
        } else {
            $stop->update(['status' => TripStop::STATUS_PENDING]);
        }
    }
    Driver::where('id', $trip->driver_id)->update([
        'current_lat' => 32.876500,
        'current_lng' => 13.176500,
    ]);
    echo "👉 إحداثيات موقع العطل المقترحة للفرونت:\n";
    echo "   Latitude: 32.876500\n";
    echo "   Longitude: 13.176500\n";
    echo "   الأطفال بالحافلة: 2 | الأطفال بالانتظار: 2\n";

} elseif ($scenario === 3) {
    echo "📍 السيناريو 3: تعطل الحافلة بعد استلام جميع الأطفال (4 أطفال بالحافلة)\n";
    foreach ($stops as $stop) {
        if ($stop->stop_type === TripStop::TYPE_HOME) {
            $stop->update(['status' => TripStop::STATUS_BOARDED]);
        } else {
            $stop->update(['status' => TripStop::STATUS_PENDING]);
        }
    }
    Driver::where('id', $trip->driver_id)->update([
        'current_lat' => 32.890000,
        'current_lng' => 13.190000,
    ]);
    echo "👉 إحداثيات موقع العطل المقترحة للفرونت:\n";
    echo "   Latitude: 32.890000\n";
    echo "   Longitude: 13.190000\n";
    echo "   الأطفال بالحافلة: 4 | الأطفال بالانتظار: 0\n";
}

echo "✅ تم ضبط حالة الرحلة ومحطاتها بنجاح! جاهزة للاختبار من الفرونت.\n";
