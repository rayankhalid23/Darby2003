<?php
/**
 * 🚌 سكربت إنشاء رحلة الذهاب الصباحية (Morning Go) - 5 أطفال
 * مجدولة بعد 30 دقيقة من وقت التشغيل الحالي (تعمل في أي وقت وأي تاريخ 24/7).
 *
 * توزيع الأطفال والمحطات:
 * - الطفل 1: عمر خالد   (منزل 1 - مدرسة بن عاشور)
 * - الطفل 2: لينا خالد  (منزل 1 - مدرسة النصر النموذجية) [شقيقان في نفس المنزل ومدرستان مختلفتان]
 * - الطفل 3: يوسف       (منزل 2 - مدرسة بن عاشور) [منزل منفصل ومدرسته متطابقة مع عمر]
 * - الطفل 4: أحمد       (منزل 3 - مدرسة زاوية الدهماني) [منزل منفصل ومدرسة منفصلة]
 * - الطفل 5: طارق       (منزل 4 - مدرسة النوفليين الأهلية) [منزل منفصل ومدرسة منفصلة]
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
$routeId = 11;

$route = Route::find($routeId);
if (!$route) {
    echo "❌ المسار 11 غير موجود.\n";
    exit(1);
}

// 1. تحديث صلاحية الطلب لتغطي أي تاريخ يتم التشغيل فيه (حتى سنة 2030)
DB::table('requests')->where('id', 158)->update([
    'start_date' => '2025-01-01',
    'end_date'   => '2030-12-31',
]);

// 2. تحديث وتثبيت بيانات الأطفال الخمسة بدقة
$childrenData = [
    ['id' => 45, 'name' => 'عمر خالد إبراهيم الككلي', 'school_id' => 2, 'h_lat' => 32.875500, 'h_lng' => 13.193500],
    ['id' => 46, 'name' => 'لينا خالد إبراهيم الككلي', 'school_id' => 1, 'h_lat' => 32.875500, 'h_lng' => 13.193500],
    ['id' => 1,  'name' => 'يوسف محمد علي الترهوني', 'school_id' => 2, 'h_lat' => 32.878000, 'h_lng' => 13.196500],
    ['id' => 3,  'name' => 'أحمد محمد علي الترهوني', 'school_id' => 3, 'h_lat' => 32.881500, 'h_lng' => 13.193000],
    ['id' => 4,  'name' => 'طارق خالد الفيتوري',       'school_id' => 4, 'h_lat' => 32.885000, 'h_lng' => 13.198500],
];

foreach ($childrenData as $cd) {
    Child::where('id', $cd['id'])->update([
        'full_name' => $cd['name'],
        'school_id' => $cd['school_id'],
    ]);
}

// 3. مزامنة محطات المسار الرئيسي (Route 11)
$subs = ActiveSubscription::where('subscription_request_id', 158)->get();
RouteStop::where('route_id', $routeId)->delete();

$sync = app(MasterRouteStopSyncService::class);
foreach ($subs as $sub) {
    $sync->addChildToRoute($route, $sub);
}

// 4. توقيت الرحلة: دائماً بعد 30 دقيقة من الوقت الحالي
$now = Carbon::now();
$startTimeIn30Mins = $now->copy()->addMinutes(30)->format('H:i:s');
$todayDate = $now->toDateString();

// حذف أي رحلة ذهاب سابقة لهذا اليوم لإعادة الاختبار بنظافة
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

// 5. توليد محطات الرحلة اليومية (trip_stops)
$gen = app(DailyTripGenerationService::class);
$method = new ReflectionMethod(DailyTripGenerationService::class, 'buildTripStops');
$method->setAccessible(true);
$method->invoke($gen, $trip, $route, $todayDate);

// 6. تصفير الكاش والإشعارات السابقة للرحلة
$childIds = [45, 46, 1, 3, 4];
$schoolIds = [1, 2, 3, 4];
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
echo "✅ تم إنشاء رحلة الذهاب الصباحية (Morning Go) بنجاح!\n";
echo "-------------------------------------------------------------------\n";
echo "🆔 معرّف الرحلة (Trip ID): {$trip->id}\n";
echo "👤 معرّف السائق (Driver ID): {$trip->driver_id}\n";
echo "🛣️ معرّف المسار (Route ID): {$trip->route_id} (ذهاب - Morning Go)\n";
echo "📅 تاريخ الرحلة: {$trip->trip_date}\n";
echo "⏰ وقت البدء المجدول (بعد 30 دقيقة من الآن): {$trip->scheduled_start_time}\n";
echo "📊 الحالة الحالية: {$trip->status}\n";
echo "-------------------------------------------------------------------\n";
echo "👨‍👩‍👧‍👦 بيانات الأطفال والاشتراكات (لاستخدامها في تأكيد الصعود/النزول):\n";
foreach ($subs as $s) {
    $c = $s->child;
    echo "   • الطالب: {$c->full_name}\n";
    echo "     - Child ID: {$c->id} | Trip Child ID (Active Sub): {$s->id}\n";
    echo "     - المنزل: {$s->pickup_label} (Lat: {$s->pickup_lat}, Lng: {$s->pickup_lng})\n";
    echo "     - المدرسة: {$s->dropoff_label}\n\n";
}
echo "-------------------------------------------------------------------\n";
echo "🚏 محطات الرحلة المرتبة (Trip Stops):\n";
$stops = TripStop::where('trip_id', $trip->id)->orderBy('sequence_order')->get();
foreach ($stops as $s) {
    $typeIcon = $s->stop_type === 'home' ? '🏠 صعود منزل' : '🏫 نزول مدرسة';
    echo "   [ترتيب {$s->sequence_order}] {$typeIcon}: {$s->label} (Lat: {$s->lat}, Lng: {$s->lng})\n";
}
echo "===================================================================\n";
echo "💡 لبدء محاكاة حركة السائق (انطلاق من 500 متر قبل المحطة الأولى):\n";
echo "   php scripts/simulate_5children_trip.php {$trip->id}\n";
echo "===================================================================\n";
