<?php
/**
 * 🚌 سكربت إنشاء رحلة الإياب المسائية (Afternoon Return) - طفلان من وليَّي أمر مختلفين ومدرستين مختلفتين
 * مجدولة بعد 30 دقيقة من وقت التشغيل الحالي (تعمل 24/7 في أي وقت).
 */
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Route;
use App\Models\Shared\RouteStop;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Services\Trip\MasterRouteStopSyncService;
use App\Services\Trip\DailyTripGenerationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

$driverId = 908;
$routeId = 12; // مسار إياب بن عاشور / النوفليين
$requestId = 158;

$route = Route::find($routeId);
if (!$route) {
    echo "❌ المسار 12 غير موجود.\n";
    exit(1);
}

// 1. تحديث صلاحية الطلب
DB::table('requests')->where('id', $requestId)->update([
    'start_date' => '2025-01-01',
    'end_date'   => '2030-12-31',
]);

// 2. مزامنة محطات المسار للطفلين فقط (يوسف وطارق)
$targetSubIds = [258, 260];
$subs = ActiveSubscription::whereIn('id', $targetSubIds)->get();
RouteStop::where('route_id', $routeId)->delete();

$sync = app(MasterRouteStopSyncService::class);
foreach ($subs as $sub) {
    $sync->addChildToRoute($route, $sub);
}

// 3. توقيت الرحلة: بعد 30 دقيقة من الآن
$now = Carbon::now();
$startTimeIn30Mins = $now->copy()->addMinutes(30)->format('H:i:s');
$todayDate = $now->toDateString();

// حذف أي رحلة إياب سابقة لهذا اليوم لنفس المسار
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

// 4. توليد محطات الرحلة اليومية
$gen = app(DailyTripGenerationService::class);
$method = new ReflectionMethod(DailyTripGenerationService::class, 'buildTripStops');
$method->setAccessible(true);
$method->invoke($gen, $trip, $route, $todayDate);

// 5. تصفير الكاش والإشعارات
$childIds = [1, 4];
$schoolIds = [2, 4];
foreach ($childIds as $cid) {
    Cache::forget("proximity_alert_sent_{$trip->id}_{$cid}");
    Cache::forget("arrival_parent_home_sent_{$trip->id}_{$cid}");
    Cache::forget("arrival_driver_home_sent_{$trip->id}_{$cid}");
    Cache::forget("trip_waiting_{$trip->id}_{$cid}");
}
foreach ($schoolIds as $sid) {
    Cache::forget("arrival_driver_school_sent_{$trip->id}_{$sid}");
    Cache::forget("trip_school_waiting_{$trip->id}_{$sid}");
}

echo "===================================================================\n";
echo "✅ تم إنشاء رحلة الإياب (Afternoon Return) - طفلان من وليَّي أمر مختلفين بنجاح!\n";
echo "-------------------------------------------------------------------\n";
echo "🆔 معرّف الرحلة (Trip ID): {$trip->id}\n";
echo "👤 معرّف السائق (Driver ID): {$trip->driver_id} (ريان خالد المريمي)\n";
echo "🛣️ معرّف المسار (Route ID): {$trip->route_id} (إياب - Afternoon Return)\n";
echo "📅 تاريخ الرحلة: {$trip->trip_date}\n";
echo "⏰ وقت البدء المجدول (بعد 30 دقيقة من الآن): {$trip->scheduled_start_time}\n";
echo "📊 الحالة الحالية: {$trip->status}\n";
echo "-------------------------------------------------------------------\n";
echo "👨‍👩‍👧‍👦 بيانات الأطفال وأولياء الأمور:\n";
foreach ($subs as $s) {
    $c = $s->child;
    $pUser = $c->parent?->user;
    echo "   • الطالب: {$c->full_name} (Child ID: {$c->id} | Sub ID: {$s->id})\n";
    echo "     - ولي الأمر: {$pUser?->full_name} | هاتف: {$pUser?->phone_number} | إيميل: {$pUser?->email}\n";
    echo "     - الصعود من المدرسة: {$s->dropoff_label} (Lat: {$s->dropoff_lat}, Lng: {$s->dropoff_lng})\n";
    echo "     - النزول بالمنزل: {$s->pickup_label} (Lat: {$s->pickup_lat}, Lng: {$s->pickup_lng})\n\n";
}
echo "-------------------------------------------------------------------\n";
echo "🚏 محطات رحلة العودة المرتبة (Trip Stops):\n";
$stops = TripStop::where('trip_id', $trip->id)->orderBy('sequence_order')->get();
foreach ($stops as $s) {
    $typeIcon = $s->stop_type === 'school' ? '🏫 صعود مدرسة' : '🏠 نزول منزل';
    echo "   [ترتيب {$s->sequence_order}] {$typeIcon}: {$s->label} (Lat: {$s->lat}, Lng: {$s->lng})\n";
}
echo "===================================================================\n";
