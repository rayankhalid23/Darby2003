<?php

namespace App\Console\Commands;

use App\Models\Driver\Driver;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Route;
use App\Models\Shared\RouteStop;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Services\Trip\DailyTripGenerationService;
use App\Services\Trip\MasterRouteStopSyncService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CreateDriverTripCommand extends Command
{
    /**
     * اسم وتوقيع الأمر في Artisan
     */
    protected $signature = 'trip:create-for-driver 
                            {driver_id : المعرف الرقمي للسائق (drivers.id أو users.id)} 
                            {--date= : تاريخ الرحلة بصيغة YYYY-MM-DD (افتراضياً تاريخ اليوم)} 
                            {--type=Morning : نوع الوردية (Morning أو Afternoon)} 
                            {--force : إعادة بناء الرحلة ومحطاتها حتى لو كانت موجودة مسبقاً}';

    /**
     * وصف الأمر
     */
    protected $description = 'إنشاء وتجهيز رحلة تشغيلية يومية مع محطاتها لسائق محدد وفق مساره واشتراكاته النشطة';

    public function handle(DailyTripGenerationService $generationService, MasterRouteStopSyncService $syncService): int
    {
        $inputDriverId = (int) $this->argument('driver_id');
        $date = $this->option('date') ? Carbon::parse($this->option('date'))->toDateString() : Carbon::today()->toDateString();
        $tripType = ucfirst(strtolower($this->option('type') ?: 'Morning'));
        $force = (bool) $this->option('force');

        $this->info("🚀 جاري معالجة إنشاء الرحلة للسائق [#{$inputDriverId}] لتاريخ [{$date}] ونوع [{$tripType}]...");

        // 1. العثور على السائق
        $driver = Driver::find($inputDriverId) ?? Driver::where('user_id', $inputDriverId)->first();
        if (!$driver) {
            $this->error("❌ لم يتم العثور على أي سائق بالمعرّف [#{$inputDriverId}].");
            return Command::FAILURE;
        }

        $driverName = $driver->user?->full_name ?? 'سائق #' . $driver->id;
        $this->line("👤 السائق: <comment>{$driverName}</comment> (Driver ID: {$driver->id}, User ID: {$driver->user_id})");

        // 2. فحص أو إنشاء المسار التشغيلي (Route)
        $route = Route::where('driver_id', $driver->id)
            ->where('route_type', $tripType)
            ->where('status', 'Active')
            ->first();

        if (!$route) {
            $this->warn("⚠️ لا يوجد مسار نشط للسائق من نوع [{$tripType}]. جاري البحث عن اشتراكات نشطة لبنائه...");
            
            $activeSubs = ActiveSubscription::forDriver($driver->id)
                ->where('status', 'active')
                ->with(['child.school', 'subscriptionRequest'])
                ->get();

            if ($activeSubs->isEmpty()) {
                $this->error("❌ السائق ليس لديه أي اشتراكات نشطة لبناء المسار والرحلة.");
                return Command::FAILURE;
            }

            $primarySub = $activeSubs->first();
            $shiftSlot = ($tripType === 'Morning') ? 'morning_go' : 'morning_return';

            $route = Route::create([
                'driver_id'               => $driver->id,
                'vehicle_id'              => $driver->current_vehicle_id ?? $driver->vehicles()->value('id') ?? 1,
                'subscription_request_id' => $primarySub->subscription_request_id,
                'route_name'              => "مسار {$tripType} - {$driverName}",
                'route_type'              => $tripType,
                'shift_slot'              => $shiftSlot,
                'start_time'              => ($tripType === 'Morning') ? '07:00:00' : '13:30:00',
                'status'                  => 'Active',
            ]);

            $this->info("✅ تم إنشاء المسار الأساسي بنجاح (Route ID: #{$route->id}).");
        } else {
            $this->line("🛣️ المسار المعتمد: <comment>#{$route->id} ({$route->route_name})</comment>");
        }

        // التأكد من وجود محطات في route_stops
        $routeStopsCount = RouteStop::where('route_id', $route->id)->count();
        if ($routeStopsCount === 0) {
            $this->warn("⚠️ لا توجد محطات في مسار السائق، جاري سحب الأطفال من الاشتراكات النشطة...");
            $activeSubs = ActiveSubscription::forDriver($driver->id)->where('status', 'active')->get();
            foreach ($activeSubs as $sub) {
                $syncService->addChildToRoute($route, $sub);
            }
            $routeStopsCount = RouteStop::where('route_id', $route->id)->count();
            $this->info("✅ تمت إضافة {$routeStopsCount} محطة لمسار السائق.");
        }

        // 3. التحقق من وجود رحلة قائمة
        $existingTrip = Trip::where('driver_id', $driver->id)
            ->where('route_id', $route->id)
            ->whereDate('trip_date', $date)
            ->where('trip_type', $tripType)
            ->first();

        if ($existingTrip && !$force) {
            $this->warn("ℹ️ توجد رحلة منشأة مسبقاً لهذا السائق اليوم (Trip ID: #{$existingTrip->id}, الحالة: {$existingTrip->status}).");
            $this->line("💡 لإعادة إنشائها من جديد، استخدم الخيار: <info>--force</info>");
            $this->displayTripDetails($existingTrip);
            return Command::SUCCESS;
        }

        if ($existingTrip && $force) {
            $this->warn("🔄 خيار --force مفعل: جاري حذف الرحلة السابقة ومحطاتها وإعادة التوليد...");
            TripStop::where('trip_id', $existingTrip->id)->delete();
            $existingTrip->delete();
        }

        // 4. توليد الرحلة اليومية ومحطاتها عبر DailyTripGenerationService
        $trip = $generationService->generateTripForRoute($route, $date);

        if (!$trip) {
            // في حال تم حجبها بسبب تواريخ التغطية أو الغياب، ننشئها يدوياً لتسهيل الاختبار
            $this->warn("⚠️ قام المحرك بتخطي التوليد التلقائي، جاري فرض إنشاء الرحلة ومحطاتها للاختبار...");
            $trip = Trip::create([
                'driver_id'            => $driver->id,
                'route_id'             => $route->id,
                'trip_type'            => $tripType,
                'shift_slot'           => $route->shift_slot,
                'status'               => 'pending',
                'scheduled_at'         => now(),
                'scheduled_start_time' => $route->start_time ?? '07:00:00',
                'trip_date'            => $date,
            ]);

            // نسخ محطات المسار إلى محطات الرحلة
            $routeStops = RouteStop::where('route_id', $route->id)->orderBy('sequence_order')->get();
            $seq = 1;
            foreach ($routeStops as $rStop) {
                TripStop::create([
                    'trip_id'        => $trip->id,
                    'route_stop_id'  => $rStop->id,
                    'stop_type'      => $rStop->stop_type,
                    'child_id'       => $rStop->child_id,
                    'school_id'      => $rStop->school_id,
                    'lat'            => $rStop->lat,
                    'lng'            => $rStop->lng,
                    'label'          => $rStop->label,
                    'sequence_order' => $seq++,
                    'status'         => TripStop::STATUS_PENDING,
                ]);
            }
        }

        $this->info("🎉 تم إنشاء وتجهيز الرحلة بنجاح!");
        $this->displayTripDetails($trip);

        return Command::SUCCESS;
    }

    private function displayTripDetails(Trip $trip): void
    {
        $stops = TripStop::where('trip_id', $trip->id)
            ->orderBy('sequence_order')
            ->with(['child', 'school'])
            ->get();

        $this->table(
            ['معرف الرحلة', 'التاريخ', 'النوع', 'الحالة', 'وقت الانطلاق المجدول', 'عدد المحطات'],
            [[
                $trip->id,
                $trip->trip_date,
                $trip->trip_type,
                $trip->status,
                $trip->scheduled_start_time,
                $stops->count(),
            ]]
        );

        if ($stops->isNotEmpty()) {
            $this->line("\n📍 <info>جدول محطات الرحلة بالترتيب:</info>");
            $rows = [];
            foreach ($stops as $s) {
                $name = ($s->stop_type === 'home') 
                    ? ($s->child?->full_name ?? $s->child?->name ?? 'طفل #' . $s->child_id)
                    : ($s->school?->name ?? 'مدرسة #' . $s->school_id);

                $rows[] = [
                    $s->sequence_order,
                    $s->stop_type === 'home' ? 'منزل طفل' : 'مدرسة',
                    $name,
                    $s->label ?? '-',
                    "{$s->lat}, {$s->lng}",
                    $s->status,
                ];
            }

            $this->table(['الترتيب', 'نوع المحطة', 'الاسم', 'التسمية', 'الإحداثيات', 'الحالة'], $rows);
        }
    }
}
