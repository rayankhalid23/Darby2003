<?php
/**
 * سكربت تجهيز وإنشاء رحلة عودة تجريبية (Afternoon Return) لسائق 908 (المسار 12)
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Driver\Driver;
use App\Models\Shared\Route;
use App\Models\Shared\RouteStop;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Services\Trip\MasterRouteStopSyncService;
use App\Services\Trip\DailyTripGenerationService;
use Carbon\Carbon;

$driverId = 908;
$routeId = 12;

$route = Route::find($routeId);
if (!$route) {
    echo "❌ المسار 12 غير موجود.\n";
    exit(1);
}

// مزامنة محطات المسار والتأكد من وجود طلاب المسار
$syncService = app(MasterRouteStopSyncService::class);
$sub29 = ActiveSubscription::find(29);
$sub30 = ActiveSubscription::find(30);

if ($sub29 && $sub30) {
    $syncService->addChildToRoute($route, $sub29);
    $syncService->addChildToRoute($route, $sub30);
}

// تنظيف أي محطات ميتة بلا أطفال وبلا مدارس
RouteStop::where('route_id', $routeId)
    ->whereNull('child_id')
    ->whereNull('school_id')
    ->delete();

// ضبط وقت بدء الرحلة ليكون بعد 30 دقيقة من الآن
$now = Carbon::now();
$startTimeIn30Mins = $now->copy()->addMinutes(30)->format('H:i:s');
$todayDate = $now->toDateString();

// حذف أي رحلة إياب تجريبية سابقة لهذا اليوم لإعادة الاختبار بنظافة
Trip::where('route_id', $routeId)->where('trip_date', $todayDate)->delete();

$trip = Trip::create([
    'driver_id'            => $driverId,
    'route_id'             => $routeId,
    'trip_type'            => 'Afternoon',
    'shift_slot'           => 'afternoon_return',
    'status'               => 'pending',
    'scheduled_at'         => $now,
    'scheduled_start_time' => $startTimeIn30Mins,
    'trip_date'            => $todayDate,
]);

// توليد محطات الرحلة اليومية (Trip Stops)
$gen = app(DailyTripGenerationService::class);
$method = new ReflectionMethod(DailyTripGenerationService::class, 'buildTripStops');
$method->setAccessible(true);
$method->invoke($gen, $trip, $route, $todayDate);

echo "===================================================================\n";
echo "✅ تم إنشاء رحلة العودة المجدولة بنجاح!\n";
echo "-------------------------------------------------------------------\n";
echo "🆔 معرّف الرحلة (Trip ID): {$trip->id}\n";
echo "👤 معرّف السائق (Driver ID): {$trip->driver_id}\n";
echo "🛣️ معرّف المسار (Route ID): {$trip->route_id} (إياب - Return)\n";
echo "📅 تاريخ الرحلة (Trip Date): {$trip->trip_date}\n";
echo "⏰ وقت البدء المجدول (بعد 30 دقيقة): {$trip->scheduled_start_time}\n";
echo "📊 الحالة الحالية: {$trip->status}\n";
echo "-------------------------------------------------------------------\n";
echo "🚏 محطات رحلة العودة التي تم توليدها:\n";
$stops = TripStop::where('trip_id', $trip->id)->orderBy('sequence_order')->get();
foreach ($stops as $s) {
    echo "   [ترتيب {$s->sequence_order}] " . ($s->stop_type === 'school' ? '🏫 صعود مدرسة' : '🏠 نزول منزل') . ": {$s->label} (Lat: {$s->lat}, Lng: {$s->lng})\n";
}
echo "===================================================================\n";
