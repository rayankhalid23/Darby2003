<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Models\Shared\TripEvent;
use Illuminate\Support\Facades\DB;

use Illuminate\Support\Facades\Cache;

$tripId = $argv[1] ?? null;

if (!$tripId) {
    $trip = Trip::where('driver_id', 908)->orderBy('id', 'desc')->first();
    $tripId = $trip?->id ?? 800;
}

echo "🔄 إعادة تصفير حالة الرحلة رقم: #{$tripId}...\n";

// 1. إعادة حالة المحطات إلى pending
$stopsCount = TripStop::where('trip_id', $tripId)
    ->update(['status' => 'pending']);

// 2. حذف أحداث الصعود والنزول والتخطي المسجلة للرحلة
$eventsCount = TripEvent::where('trip_id', $tripId)->delete();

// 3. مسح الكاش المتعلق بالاقتراب والوصول والانتظار
$childIds = [45, 46];
$schoolIds = [1, 2];
foreach ($childIds as $cid) {
    Cache::forget("proximity_alert_sent_{$tripId}_{$cid}");
    Cache::forget("arrival_parent_home_sent_{$tripId}_{$cid}");
    Cache::forget("arrival_driver_home_sent_{$tripId}_{$cid}");
    Cache::forget("trip_waiting_{$tripId}_{$cid}");
    Cache::forget("automatic_arrival_logged_{$tripId}_{$cid}");
}
foreach ($schoolIds as $sid) {
    Cache::forget("arrival_driver_school_sent_{$tripId}_{$sid}");
    Cache::forget("trip_school_waiting_{$tripId}_{$sid}");
}

// 4. حذف إشعارات الرحلة القديمة لتمكين إعادة إرسالها دون حظر الـ dedupe
$notifsCount = DB::table('notifications')
    ->where('data', 'like', "%{$tripId}%")
    ->delete();

echo "✅ تم تصفير المحطات بنجاح (عدد المحطات المحدثة: {$stopsCount})\n";
echo "✅ تم حذف أحداث الرحلة السابقة (عدد الأحداث المحذوفة: {$eventsCount})\n";
echo "✅ تم تصفير كاش الاقتراب والوصول والانتظار للرحلة\n";
echo "✅ تم حذف إشعارات الرحلة السابقة بالكامل لتمكين إعادة استلامها (عدد الإشعارات: {$notifsCount})\n";
echo "\n جاهز الآن لتجربة الرحلة والاشعارات وتأكيد الصعود/النزول والتخطي من جديد!\n";
