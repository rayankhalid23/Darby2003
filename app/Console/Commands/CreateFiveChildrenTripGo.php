<?php

namespace App\Console\Commands;

use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Route;
use App\Models\Shared\RouteStop;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Services\Trip\MasterRouteStopSyncService;
use App\Services\Trip\DailyTripGenerationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;
use ReflectionMethod;

class CreateFiveChildrenTripGo extends Command
{
    protected $signature = 'trip:create-5children-go';
    protected $description = 'إنشاء رحلة الذهاب الصباحية (Morning Go) لخمسة أطفال وتجهيز محطاتها';

    public function handle(MasterRouteStopSyncService $sync, DailyTripGenerationService $gen): int
    {
        $driverId = 908;
        $routeId = 11;

        $route = Route::find($routeId);
        if (!$route) {
            $this->error("❌ المسار 11 غير موجود.");
            return Command::FAILURE;
        }

        // 1. تحديث صلاحية الطلب
        DB::table('requests')->where('id', 158)->update([
            'start_date' => '2025-01-01',
            'end_date'   => '2030-12-31',
        ]);

        // 2. تحديث بيانات الأطفال الخمسة
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

        // 3. مزامنة محطات المسار الرئيسي
        $subs = ActiveSubscription::where('subscription_request_id', 158)->get();
        RouteStop::where('route_id', $routeId)->delete();

        foreach ($subs as $sub) {
            $sync->addChildToRoute($route, $sub);
        }

        // 4. توقيت الرحلة
        $now = Carbon::now();
        $startTimeIn30Mins = $now->copy()->addMinutes(30)->format('H:i:s');
        $todayDate = $now->toDateString();

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

        // 5. توليد محطات الرحلة
        $method = new ReflectionMethod(DailyTripGenerationService::class, 'buildTripStops');
        $method->setAccessible(true);
        $method->invoke($gen, $trip, $route, $todayDate);

        // 6. تصفير الكاش
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

        $this->info("===================================================================");
        $this->info("✅ تم إنشاء رحلة الذهاب الصباحية بنجاح على السيرفر!");
        $this->info("🆔 معرّف الرحلة (Trip ID): {$trip->id}");
        $this->info("👤 معرّف السائق (Driver ID): {$trip->driver_id}");
        $this->info("🛣️ معرّف المسار (Route ID): {$trip->route_id}");
        $this->info("📅 تاريخ الرحلة: {$trip->trip_date}");
        $this->info("⏰ وقت البدء المجدول: {$trip->scheduled_start_time}");
        $this->info("📊 الحالة: {$trip->status}");
        $this->info("===================================================================");

        return Command::SUCCESS;
    }
}
