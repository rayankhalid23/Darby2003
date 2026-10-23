<?php
/**
 * 🚌 سكربت إنشاء رحلة الذهاب الصباحية (Morning Go) - طفلان من وليَّي أمر مختلفين ومدرستين مختلفتين
 * مجدولة بعد 30 دقيقة من وقت التشغيل الحالي (تعمل 24/7 في أي وقت).
 *
 * 👨‍👧‍👦 الطفل 1: يوسف محمد علي الترهوني (Child ID: 1 | Sub ID: 258)
 *   - ولي الأمر: محمد علي الترهوني (parent1@darby.ly - 0911111111)
 *   - عنوان المنزل: منزل 2 - بن عاشور (Lat: 32.878000, Lng: 13.196500)
 *   - المدرسة: مدرسة بن عاشور الابتدائية والإعدادية (Lat: 32.874560, Lng: 13.190230)
 *
 * 👨‍👧‍👦 الطفل 2: طارق خالد الفيتوري (Child ID: 4 | Sub ID: 260)
 *   - ولي الأمر: خالد أحمد الفيتوري (parent2@darby.ly - 0912222222)
 *   - عنوان المنزل: منزل 4 - النوفليين (Lat: 32.885000, Lng: 13.198500)
 *   - المدرسة: مدرسة النوفليين الأهلية النموذجية (Lat: 32.889340, Lng: 13.205120)
 *
 * 👤 السائق الأساسي: ريان خالد المريمي (driver13@darby.ly - 0916466628 | Driver ID: 908)
 * 👤 السائق البديل المؤهل: حسام إبراهيم القذافي (driver3@darby.ly - 0918111011 | Driver ID: 121)
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

$driverId = 908; // ريان المريمي
$routeId = 11;  // مسار ذهاب بن عاشور / النوفليين
$requestId = 158;

$route = Route::find($routeId);
if (!$route) {
    echo "❌ المسار 11 غير موجود.\n";
    exit(1);
}

// 1. تحديث صلاحية الطلب لتغطي أي تاريخ
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

// حذف أي رحلة سابقة لهذا اليوم لنفس المسار لإعادة الاختبار بنظافة
Trip::where('route_id', $routeId)->where('trip_date', $todayDate)->delete();

$trip = Trip::create([
    'driver_id'            => $driverId,
    'route_id'             => $routeId,
    'trip_type'            => 'Morning',
    'shift_slot'           => 'morning_go',
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
echo "✅ تم إنشاء رحلة الذهاب (Morning Go) - طفلان من وليَّي أمر مختلفين بنجاح!\n";
echo "-------------------------------------------------------------------\n";
echo "🆔 معرّف الرحلة (Trip ID): {$trip->id}\n";
echo "👤 معرّف السائق (Driver ID): {$trip->driver_id} (ريان خالد المريمي)\n";
echo "🛣️ معرّف المسار (Route ID): {$trip->route_id} (ذهاب - Morning Go)\n";
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
    echo "     - المنزل: {$s->pickup_label} (Lat: {$s->pickup_lat}, Lng: {$s->pickup_lng})\n";
    echo "     - المدرسة: {$s->dropoff_label} (Lat: {$s->dropoff_lat}, Lng: {$s->dropoff_lng})\n\n";
}
echo "-------------------------------------------------------------------\n";
echo "🚏 محطات الرحلة المرتبة (Trip Stops):\n";
$stops = TripStop::where('trip_id', $trip->id)->orderBy('sequence_order')->get();
foreach ($stops as $s) {
    $typeIcon = $s->stop_type === 'home' ? '🏠 صعود منزل' : '🏫 نزول مدرسة';
    echo "   [ترتيب {$s->sequence_order}] {$typeIcon}: {$s->label} (Lat: {$s->lat}, Lng: {$s->lng})\n";
}
echo "===================================================================\n";
