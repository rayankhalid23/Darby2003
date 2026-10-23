<?php

namespace App\Console\Commands;

use App\Models\Driver\Driver;
use App\Models\Parent\Child;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Route;
use App\Models\Shared\RouteStop;
use App\Models\Shared\Trip;
use App\Models\Shared\TripBreakdownDispatch;
use App\Models\Shared\TripEvent;
use App\Models\Shared\TripStop;
use App\Models\Shared\TripTracking;
use App\Models\User;
use App\Services\Trip\DailyTripGenerationService;
use App\Services\Trip\MasterRouteStopSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;

class CreateTwoDiffParentsTripCommand extends Command
{
    /**
     * اسم وتوقيع الأمر
     */
    protected $signature = 'trip:create-2diff-parents 
                            {--date= : تاريخ الرحلة YYYY-MM-DD (افتراضياً تاريخ اليوم)}
                            {--start : بدء الرحلة فوراً بجعلها in_progress}';

    /**
     * وصف الأمر
     */
    protected $description = 'إنشاء رحلة ذهاب بطفلين من وليَّي أمر مختلفين ومدرستين مختلفتين، مع تنظيف آمن للرحلات السابقة لتجنب أي تعارض';

    public function handle(): int
    {
        $todayDate = $this->option('date') ? Carbon::parse($this->option('date'))->toDateString() : Carbon::today()->toDateString();
        $shouldStart = (bool) $this->option('start');

        $this->info("🚀 جاري إنشاء رحلة ذهاب (طفلان من وليَّي أمر مختلفين ومدرستين مختلفتين) لتاريخ [{$todayDate}]...");

        $driverId = 908; // ريان خالد المريمي
        $routeId = 11;   // مسار ذهاب بن عاشور / النوفليين
        $requestId = 158;

        $route = Route::find($routeId);
        if (!$route) {
            $this->error("❌ المسار رقم #{$routeId} غير موجود.");
            return self::FAILURE;
        }

        // 1. تحديث صلاحية الطلب
        DB::table('requests')->where('id', $requestId)->update([
            'start_date' => '2025-01-01',
            'end_date'   => '2030-12-31',
        ]);

        // 2. قصر الاشتراكات الفعالة للسائق ريان والمسار #11 على الطفلين فقط (يوسف وطارق)
        $targetSubIds = [258, 260];
        ActiveSubscription::forDriver($driverId)
            ->whereNotIn('id', $targetSubIds)
            ->update(['status' => 'cancelled']);
        ActiveSubscription::where('route_id', $routeId)
            ->whereNotIn('id', $targetSubIds)
            ->update(['status' => 'cancelled']);
        ActiveSubscription::whereIn('id', $targetSubIds)
            ->update([
                'status'   => 'active',
                'route_id' => $routeId,
            ]);

        $subs = ActiveSubscription::whereIn('id', $targetSubIds)->with(['child.school', 'child.parent.user'])->get();
        RouteStop::where('route_id', $routeId)->delete();

        $sync = app(MasterRouteStopSyncService::class);
        foreach ($subs as $sub) {
            $sync->addChildToRoute($route, $sub);
        }

        // 3. تنظيف آمن وذكي لأي رحلات سابقة لهذا المسار أو للسائق ريان أو للسائق البديل في هذا اليوم لمنع أي تعارض (Zero Conflict)
        $subDriverUser = User::where('email', 'driver.sub.free@darby.ly')->first();
        $subDriverId = $subDriverUser?->driver?->id ?? 2923;

        $oldDispatches = TripBreakdownDispatch::whereHas('trip', function ($q) use ($routeId, $driverId, $todayDate) {
            $q->where(function ($subQ) use ($routeId, $driverId) {
                $subQ->where('route_id', $routeId)->orWhere('driver_id', $driverId);
            })->whereDate('trip_date', $todayDate);
        })->get();
        foreach ($oldDispatches as $disp) {
            if ($disp->substitute_trip_id) {
                TripEvent::where('trip_id', $disp->substitute_trip_id)->delete();
                TripStop::where('trip_id', $disp->substitute_trip_id)->delete();
                TripTracking::where('trip_id', $disp->substitute_trip_id)->delete();
                Trip::where('id', $disp->substitute_trip_id)->delete();
            }
            $disp->delete();
        }

        // تنظيف أي رحلات قديمة للسائق البديل اليوم لضمان أنه متفرغ 100% بدون أي رحلة أو أطفال
        $subOldTrips = Trip::where('driver_id', $subDriverId)->whereDate('trip_date', $todayDate)->get();
        foreach ($subOldTrips as $sTrip) {
            TripEvent::where('trip_id', $sTrip->id)->delete();
            TripStop::where('trip_id', $sTrip->id)->delete();
            TripTracking::where('trip_id', $sTrip->id)->delete();
            $sTrip->delete();
        }

        $oldTrips = Trip::where(function ($q) use ($routeId, $driverId) {
            $q->where('route_id', $routeId)->orWhere('driver_id', $driverId);
        })->whereDate('trip_date', $todayDate)->get();
        foreach ($oldTrips as $oldTrip) {
            TripEvent::where('trip_id', $oldTrip->id)->delete();
            TripStop::where('trip_id', $oldTrip->id)->delete();
            TripTracking::where('trip_id', $oldTrip->id)->delete();
            TripBreakdownDispatch::where('trip_id', $oldTrip->id)->delete();
            $oldTrip->delete();
        }

        // 4. توقيت الرحلة وإنشاؤها بحالة pending (أو in_progress إذا حُدد --start)
        $now = Carbon::now();
        $startTime = $now->copy()->addMinutes(30)->format('H:i:s');
        $tripStatus = $shouldStart ? 'in_progress' : 'pending';

        $trip = Trip::create([
            'driver_id'            => $driverId,
            'route_id'             => $routeId,
            'trip_type'            => 'Morning',
            'shift_slot'           => 'morning_go',
            'status'               => $tripStatus,
            'scheduled_at'         => $now,
            'scheduled_start_time' => $startTime,
            'actual_start_time'    => $shouldStart ? $now : null,
            'started_at'           => $shouldStart ? $now : null,
            'start_lat'            => 32.870000,
            'start_lng'            => 13.195000,
            'trip_date'            => $todayDate,
        ]);

        // 5. توليد محطات الرحلة اليومية
        $gen = app(DailyTripGenerationService::class);
        $method = new ReflectionMethod(DailyTripGenerationService::class, 'buildTripStops');
        $method->setAccessible(true);
        $method->invoke($gen, $trip, $route, $todayDate);

        // 6. تصفير الكاش والإشعارات السابقة للرحلة
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

        // 7. تجهيز التوكنات لجميع الأطراف
        $driverUser = User::where('email', 'driver13@darby.ly')->first();
        $subDriverUser = User::where('email', 'driver.sub.free@darby.ly')->first();
        $parent1User = User::where('email', 'parent1@darby.ly')->first();
        $parent2User = User::where('email', 'parent2@darby.ly')->first();

        $driverToken = $driverUser ? $driverUser->createToken('postman_driver13')->plainTextToken : '';
        $subDriverToken = $subDriverUser ? $subDriverUser->createToken('postman_subdriver_free')->plainTextToken : '';
        $parent1Token = $parent1User ? $parent1User->createToken('postman_p1')->plainTextToken : '';
        $parent2Token = $parent2User ? $parent2User->createToken('postman_p2')->plainTextToken : '';

        // 8. طباعة التفاصيل للمستخدم
        $this->info("\n===================================================================");
        $this->info("✅ تم إنشاء الرحلة الجديدة بنجاح! جاهزة للاختبار بدون أي تعارض.");
        $this->info("===================================================================");

        $this->table(
            ['معرف الرحلة (Trip ID)', 'حالة الرحلة', 'السائق الأساسي', 'النوع', 'تاريخ الرحلة'],
            [[
                $trip->id,
                $trip->status,
                $driverUser?->full_name ?? 'ريان المريمي',
                'Morning (ذهاب)',
                $trip->trip_date,
            ]]
        );

        $this->line("\n🔑 <comment>توكنات السائقين المباشرة (Postman Bearer Tokens):</comment>");
        $this->line("• توكن السائق الأساسي (ريان): <info>{$driverToken}</info>");
        $this->line("  (Email: <comment>driver13@darby.ly</comment> | Pass: <comment>Password123!</comment> | Driver ID: <comment>908</comment>)");
        $this->line("• توكن السائق البديل المتفرغ (كابتن وسام): <info>{$subDriverToken}</info>");
        $this->line("  (Email: <comment>driver.sub.free@darby.ly</comment> | Pass: <comment>Password123!</comment> | Driver ID: <comment>2923</comment> | سعة المركبة: 12 مقعداً شاغراً بدون أي رحلة)");

        $this->line("\n👨‍👩‍👧‍👦 <comment>بيانات الأطفال وأولياء الأمور والتوكنات:</comment>");
        $this->line("1. <info>يوسف الترهوني</info> (Child ID: <comment>1</comment> | Sub ID: 258)");
        $this->line("   - ولي الأمر: محمد علي الترهوني (Email: <comment>parent1@darby.ly</comment> | Pass: <comment>Password123!</comment> | هاتف: 0911111111)");
        $this->line("   - توكن ولي الأمر 1: <info>{$parent1Token}</info>");
        $this->line("   - المنزل: بن عاشور (Lat: <comment>32.878000</comment>, Lng: <comment>13.196500</comment>)");
        $this->line("   - المدرسة: مدرسة بن عاشور الابتدائية (Lat: <comment>32.874560</comment>, Lng: <comment>13.190230</comment>)");

        $this->line("\n2. <info>طارق الفيتوري</info> (Child ID: <comment>4</comment> | Sub ID: 260)");
        $this->line("   - ولي الأمر: خالد أحمد الفيتوري (Email: <comment>parent2@darby.ly</comment> | Pass: <comment>Password123!</comment> | هاتف: 0912222222)");
        $this->line("   - توكن ولي الأمر 2: <info>{$parent2Token}</info>");
        $this->line("   - المنزل: النوفليين (Lat: <comment>32.885000</comment>, Lng: <comment>13.198500</comment>)");
        $this->line("   - المدرسة: مدرسة النوفليين الأهلية (Lat: <comment>32.889340</comment>, Lng: <comment>13.205120</comment>)");

        $this->line("\n🚏 <comment>محطات الرحلة المرتبة (Trip Stops):</comment>");
        $stops = TripStop::where('trip_id', $trip->id)->orderBy('sequence_order')->get();
        $rows = [];
        foreach ($stops as $s) {
            $rows[] = [$s->sequence_order, $s->stop_type, $s->label, "{$s->lat}, {$s->lng}", $s->status];
        }
        $this->table(['الترتيب', 'النوع', 'التسمية', 'الإحداثيات', 'الحالة'], $rows);

        return self::SUCCESS;
    }
}
