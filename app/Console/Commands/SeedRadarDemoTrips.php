<?php

namespace App\Console\Commands;

use App\Models\Driver\Driver;
use App\Models\Driver\Vehicle;
use App\Models\Parent\Address;
use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Route;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Models\Shared\TripTracking;
use App\Models\Shared\Zone;
use App\Models\User;
use App\Services\Shared\SubscriptionRequestService;
use App\Services\Trip\DailyTripGenerationService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ينشئ عدداً حقيقياً (>7) من الرحلات النشطة الآن (status=in_progress) عبر المسار
 * الإنتاجي الفعلي بالكامل: SubscriptionRequestService::createRequest() + ->updateStatus('accepted')
 * (يولّد Route + RouteStop + ActiveSubscription + PlatformFinance + قيود مالية حقيقية
 * وخصم/إيداع محافظ فعلي)، ثم DailyTripGenerationService::generateForRoute() لبناء
 * الرحلة ومحطاتها (TripStop) من نفس مولّد الرحلات اليومية الحقيقي في الإنتاج.
 *
 * كل رحلة تُحفظ لها لاحقاً مسار حقيقي (نقاط متتالية ملتصقة بالشوارع عبر OSRM) في
 * storage/app/drive-simulations/radar/trip_{id}.json ليقرأه أمر trip:radar-simulate
 * ويحرّك السائق عليه بشكل طبيعي.
 */
class SeedRadarDemoTrips extends Command
{
    protected $signature = 'trip:seed-radar-demo
        {--fresh : حذف بيانات العرض التجريبي السابقة لهذا الأمر قبل إعادة إنشائها}
        {--wipe : حذف بيانات العرض التجريبي فقط دون إعادة إنشائها والخروج}';

    protected $description = 'ينشئ أكثر من 7 رحلات نشطة حقيقية (سائقين/أطفال/اشتراكات/ماليات فعلية) لاختبار رادار الأدمن للرحلات الحية';

    private const EMAIL_DOMAIN = 'radar-demo.darby.ly';
    private const SEED_MARK    = 'RADAR_DEMO_SEED';

    /**
     * كل سيناريو يمثل منطقة مختلفة من طرابلس (تطابق دوال getRegionName في لوحة الأدمن)
     * حتى تتوزع الرحلات على الرادار بدل تكدسها في نقطة واحدة.
     */
    private array $scenarios = [
        [
            'region'      => 'السياحية',
            'driver_name' => 'سالم عبدالله الفقيه',
            'parent_name' => 'نورة الككلي',
            'home'        => [32.970000, 13.090000],
            'school_id'   => 18,
            'children'    => [['name' => 'طارق سالم الفقيه', 'gender' => 'male', 'grade' => 4]],
        ],
        [
            'region'      => 'حي الأندلس',
            'driver_name' => 'خالد بشير الورفلي',
            'parent_name' => 'أسماء الشريف',
            'home'        => [32.885000, 13.165000],
            'school_id'   => 14,
            'children'    => [
                ['name' => 'أحمد خالد الورفلي', 'gender' => 'male', 'grade' => 3],
                ['name' => 'سلمى خالد الورفلي', 'gender' => 'female', 'grade' => 1],
            ],
        ],
        [
            'region'      => 'بن عاشور',
            'driver_name' => 'عماد الصادق البرعصي',
            'parent_name' => 'فاطمة الطرابلسي',
            'home'        => [32.920000, 13.240000],
            'school_id'   => 4,
            'children'    => [['name' => 'يوسف عماد البرعصي', 'gender' => 'male', 'grade' => 6]],
        ],
        [
            'region'      => 'قرقارش',
            'driver_name' => 'يوسف رمضان السويحلي',
            'parent_name' => 'حنان النفاتي',
            'home'        => [32.860000, 13.090000],
            'school_id'   => 16,
            'children'    => [
                ['name' => 'علي يوسف السويحلي', 'gender' => 'male', 'grade' => 5],
                ['name' => 'رقية يوسف السويحلي', 'gender' => 'female', 'grade' => 2],
            ],
        ],
        [
            'region'      => 'سوق الجمعة',
            'driver_name' => 'الهادي مفتاح الغرياني',
            'parent_name' => 'سعاد القبائلي',
            'home'        => [32.820000, 13.250000],
            'school_id'   => 11,
            'children'    => [['name' => 'مريم الهادي الغرياني', 'gender' => 'female', 'grade' => 3]],
        ],
        [
            'region'      => 'تاجوراء',
            'driver_name' => 'نوري سالم الزليتني',
            'parent_name' => 'زينب البوسيفي',
            'home'        => [32.870000, 13.370000],
            'school_id'   => 7,
            'children'    => [
                ['name' => 'محمد نوري الزليتني', 'gender' => 'male', 'grade' => 7],
                ['name' => 'هدى نوري الزليتني', 'gender' => 'female', 'grade' => 4],
            ],
        ],
        [
            'region'      => 'جنزور',
            'driver_name' => 'عبدالناصر خليفة التاورغي',
            'parent_name' => 'آمنة الدرسي',
            'home'        => [32.850000, 13.040000],
            'school_id'   => 12,
            'children'    => [['name' => 'عمر عبدالناصر التاورغي', 'gender' => 'male', 'grade' => 2]],
        ],
        [
            'region'      => 'طرابلس المركز',
            'driver_name' => 'جمعة علي المجبري',
            'parent_name' => 'ابتسام الحداد',
            'home'        => [32.860000, 13.180000],
            'school_id'   => 3,
            'children'    => [
                ['name' => 'ياسمين جمعة المجبري', 'gender' => 'female', 'grade' => 1],
                ['name' => 'زياد جمعة المجبري', 'gender' => 'male', 'grade' => 5],
            ],
        ],
    ];

    public function handle(SubscriptionRequestService $subscriptionService, DailyTripGenerationService $dailyTripService): int
    {
        if ($this->option('wipe')) {
            $this->wipePreviousDemoData();
            $this->info('✅ تم حذف كل بيانات عرض الرادار التجريبية.');
            return self::SUCCESS;
        }

        if ($this->option('fresh')) {
            $this->wipePreviousDemoData();
        }

        $this->info('🚕 بدء إنشاء رحلات الرادار الحية الحقيقية (' . count($this->scenarios) . ' رحلة)...');
        $routeDir = storage_path('app/drive-simulations/radar');
        if (!is_dir($routeDir)) {
            mkdir($routeDir, 0777, true);
        }

        $today = Carbon::today();
        $zoneId = Zone::first()?->id;
        $createdTrips = [];

        foreach ($this->scenarios as $i => $scenario) {
            $n = $i + 1;
            $this->newLine();
            $this->line("<fg=cyan>━━ [{$n}/8] منطقة: {$scenario['region']} ━━</>");

            [$homeLat, $homeLng] = $scenario['home'];
            $school = School::find($scenario['school_id']) ?? School::first();

            // ── السائق + المركبة ──────────────────────────────────────────
            $driverEmail = "radar.driver{$n}@" . self::EMAIL_DOMAIN;
            $driverUser = User::where('email', $driverEmail)->first();
            $driverIsNew = !$driverUser;

            if (!$driverUser) {
                $driverUser = User::create([
                    'full_name'         => $scenario['driver_name'],
                    'email'             => $driverEmail,
                    'phone_number'      => '0929' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
                    'password'          => Hash::make('Password123!'),
                    'role_id'           => DB::table('roles')->where('name', 'driver')->value('id') ?? 8,
                    'is_active'         => true,
                    'is_trusted'        => true,
                    'gender'            => 'male',
                    'email_verified_at' => now(),
                ]);
            }

            // نقطة انطلاق السائق: بالقرب من أول منزل لكنها ليست عليه تماماً (وصلة أولى واقعية)
            $leadInLat = $homeLat + (mt_rand(-60, 60) / 100000);
            $leadInLng = $homeLng + (mt_rand(-60, 60) / 100000);

            $driver = Driver::where('user_id', $driverUser->id)->first();
            if (!$driver) {
                $driver = Driver::create([
                    'user_id'           => $driverUser->id,
                    'national_id'       => '1198800' . str_pad((string) $n, 4, '0', STR_PAD_LEFT),
                    'license_number'    => 'LY-TR-RDR-' . $n,
                    'license_expiry'    => Carbon::now()->addYears(2)->format('Y-m-d'),
                    'status'            => 'Approved',
                    'shift'             => 'both',
                    'morning_go'        => 1,
                    'morning_return'    => 1,
                    'afternoon_go'      => 1,
                    'afternoon_return'  => 1,
                    'subscription_type' => 'multi_day',
                    'accepted_gender'   => 'both',
                    'current_lat'       => $leadInLat,
                    'current_lng'       => $leadInLng,
                    'last_ping_at'      => now(),
                ]);
            } else {
                $driver->update(['current_lat' => $leadInLat, 'current_lng' => $leadInLng, 'last_ping_at' => now()]);
            }

            if (Vehicle::where('driver_id', $driver->id)->where('status', 'Active')->doesntExist()) {
                Vehicle::create([
                    'driver_id'       => $driver->id,
                    'plate_number'    => '5-RDR-' . $n,
                    'brand'           => 'Toyota',
                    'model'           => 'HiAce',
                    'year'            => 2023,
                    'color'           => 'أبيض',
                    'type'            => 'Bus',
                    'capacity_manual' => 14,
                    'has_ac'          => 1,
                    'status'          => 'Active',
                ]);
            }

            // إذا كانت هناك رحلة نشطة بالفعل لهذا السائق التجريبي، لا نكرر بناء الاشتراك من الصفر
            $existingTrip = Trip::where('driver_id', $driver->id)->where('status', 'in_progress')->first();
            if (!$driverIsNew && $existingTrip) {
                $this->line("   ↺ رحلة نشطة موجودة بالفعل (trip_id={$existingTrip->id}) — تحديث الطابع الزمني فقط.");
                $existingTrip->update(['actual_start_time' => now()->subMinutes(rand(3, 15)), 'started_at' => now()->subMinutes(rand(3, 15))]);
                $this->writeRouteFile($existingTrip, $leadInLat, $leadInLng);
                $createdTrips[] = $existingTrip->id;
                continue;
            }

            // ── ولي الأمر + العنوان + الأطفال ──────────────────────────────
            $parentEmail = "radar.parent{$n}@" . self::EMAIL_DOMAIN;
            $parentUser = User::where('email', $parentEmail)->first();
            if (!$parentUser) {
                $parentUser = User::create([
                    'full_name'         => $scenario['parent_name'],
                    'email'             => $parentEmail,
                    'phone_number'      => '0928' . str_pad((string) $n, 6, '0', STR_PAD_LEFT),
                    'password'          => Hash::make('Password123!'),
                    'role_id'           => DB::table('roles')->where('name', 'parent')->value('id') ?? 7,
                    'is_active'         => true,
                    'is_trusted'        => true,
                    'gender'            => 'female',
                    'email_verified_at' => now(),
                ]);
            }

            // تمويل محفظة ولي الأمر فعلياً (رصيد حقيقي كافٍ لأي سعر اشتراك محتمل)
            $parentModel = \App\Models\Parent\ParentModel::find($parentUser->id);
            if (((int) $parentModel->balance) < 800000) {
                $parentModel->deposit(800000 - (int) $parentModel->balance);
            }

            $address = Address::where('user_id', $parentUser->id)->first();
            if (!$address) {
                $address = Address::create([
                    'user_id'    => $parentUser->id,
                    'label'      => 'منزل ' . $scenario['parent_name'] . ' (' . $scenario['region'] . ')',
                    'lat'        => $homeLat,
                    'lng'        => $homeLng,
                    'zone_id'    => $zoneId,
                    'is_default' => true,
                ]);
            }

            $childIds = [];
            foreach ($scenario['children'] as $childData) {
                $child = Child::where('parent_id', $parentUser->id)->where('full_name', $childData['name'])->first();
                if (!$child) {
                    $child = Child::create([
                        'parent_id'            => $parentUser->id,
                        'full_name'            => $childData['name'],
                        'school_id'            => $school?->id,
                        'address_id'           => $address->id,
                        'grade'                => $childData['grade'],
                        'gender'               => $childData['gender'],
                        'birth_date'           => Carbon::now()->subYears(6 + $childData['grade'])->format('Y-m-d'),
                        'preferred_time_slot'  => 'morning',
                        'is_active'            => true,
                    ]);
                }
                $childIds[] = $child->id;
            }

            // ── طلب الاشتراك (المسار الإنتاجي الحقيقي بالكامل) ──────────────
            $subReq = $subscriptionService->createRequest([
                'driver_id'         => $driver->id,
                'subscription_type' => 'monthly',
                'trip_direction'    => 'both',
                'start_date'        => $today->toDateString(),
                'end_date'          => $today->copy()->addDays(30)->toDateString(),
                'timing'            => 'MORNING',
                'home_address_id'   => $address->id,
                'notes'             => self::SEED_MARK,
                'children'          => array_map(fn ($id) => ['child_id' => $id], $childIds),
            ], $parentUser->id);

            try {
                $subscriptionService->updateStatus($subReq, SubscriptionRequest::STATUS_ACCEPTED);
            } catch (\Throwable $e) {
                $this->error("   ✗ فشل قبول الاشتراك للسائق [{$scenario['driver_name']}]: " . $e->getMessage());
                continue;
            }

            $primaryRoute = Route::where('driver_id', $driver->id)
                ->where('shift_slot', 'morning_go')
                ->where('status', 'Active')
                ->first();

            if (!$primaryRoute) {
                $this->error('   ✗ تعذر إيجاد المسار الأساسي (morning_go) بعد قبول الاشتراك.');
                continue;
            }

            // ── توليد الرحلة اليومية الحقيقية من نفس مولّد trips:generate-daily ──
            $trip = $dailyTripService->generateForRoute($primaryRoute, $today);
            if (!$trip) {
                $this->error('   ✗ تعذر توليد الرحلة اليومية لهذا المسار.');
                continue;
            }

            $startedMinutesAgo = rand(3, 15);
            $trip->update([
                'status'            => 'in_progress',
                'actual_start_time' => now()->subMinutes($startedMinutesAgo),
                'started_at'        => now()->subMinutes($startedMinutesAgo),
                'start_lat'         => $leadInLat,
                'start_lng'         => $leadInLng,
            ]);

            $this->writeRouteFile($trip, $leadInLat, $leadInLng);

            $childNames = implode(' و ', array_column($scenario['children'], 'name'));
            $this->info("   ✅ trip_id={$trip->id} | السائق: {$scenario['driver_name']} | الأطفال: {$childNames} | المدرسة: " . ($school?->name ?? '—'));

            $createdTrips[] = $trip->id;
        }

        $this->newLine();
        $this->info('🎉 اكتمل إنشاء ' . count($createdTrips) . ' رحلة نشطة حقيقية: ' . implode(', ', $createdTrips));
        $this->line('   للتحقق من ظهورها في رادار الأدمن: GET /api/admin/dashboard/active-trips');
        $this->line('   لتحريك السائقين على مساراتهم الآن، شغّل في تيرمنال آخر:');
        $this->line('   <fg=yellow>php artisan trip:radar-simulate</>');

        return self::SUCCESS;
    }

    /**
     * يبني مسار القيادة الحقيقي (نقاط ملتصقة بالشوارع) لهذه الرحلة عبر OSRM
     * ويحفظه في ملف JSON يقرأه أمر المحاكاة trip:radar-simulate، ثم يسجّل أول
     * نقطة تتبع فوراً حتى تظهر الرحلة على الرادار لحظة انتهاء هذا الأمر.
     */
    private function writeRouteFile(Trip $trip, float $startLat, float $startLng): void
    {
        $stops = TripStop::where('trip_id', $trip->id)
            ->where('sequence_order', '>', 0)
            ->orderBy('sequence_order')
            ->get();

        $waypoints = [[$startLat, $startLng]];
        foreach ($stops as $stop) {
            $waypoints[] = [(float) $stop->lat, (float) $stop->lng];
        }

        if (count($waypoints) < 2) {
            return;
        }

        $points = $this->fetchOsrmPolyline($waypoints);

        $path = storage_path("app/drive-simulations/radar/trip_{$trip->id}.json");
        file_put_contents($path, json_encode([
            'trip_id'   => $trip->id,
            'driver_id' => $trip->driver_id,
            'points'    => $points,
        ], JSON_UNESCAPED_UNICODE));

        $first = $points[0] ?? [$startLat, $startLng];
        TripTracking::create([
            'trip_id'     => $trip->id,
            'latitude'    => $first[0],
            'longitude'   => $first[1],
            'speed'       => 0,
            'recorded_at' => now(),
        ]);
    }

    /**
     * يطلب مساراً حقيقياً ملتصقاً بالشوارع من محرك OSRM العام (نفس نمط OsrmRoutingService
     * لكن عبر خادم OSRM العمومي لأن المحرك المحلي localhost:5001 غير مُشغَّل بيئة التطوير)،
     * ويعود لمسار اصطناعي بسيط (خط متعرج بلطف) عند تعذر الاتصال حتى لا يفشل الأمر بالكامل.
     */
    private function fetchOsrmPolyline(array $waypoints): array
    {
        try {
            $coordsStr = collect($waypoints)->map(fn ($p) => $p[1] . ',' . $p[0])->implode(';');
            $response = Http::timeout(8)->get("https://router.project-osrm.org/route/v1/driving/{$coordsStr}", [
                'overview'   => 'full',
                'geometries' => 'geojson',
            ]);

            if ($response->successful() && $response->json('code') === 'Ok') {
                $coords = $response->json('routes.0.geometry.coordinates', []);
                if (count($coords) >= 2) {
                    return array_map(fn ($c) => [$c[1], $c[0]], $coords);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('trip:seed-radar-demo: تعذر الاتصال بـ OSRM العمومي، سيُستخدم مسار اصطناعي. ' . $e->getMessage());
        }

        return $this->syntheticPolyline($waypoints);
    }

    /**
     * مسار احتياطي بلا اتصال إنترنت: يصل كل نقطتين متتاليتين بخط متعرج بلطف
     * (بدل خط مستقيم تماماً) حتى تبدو الحركة طبيعية على الرادار حتى لو تعذر OSRM.
     */
    private function syntheticPolyline(array $waypoints): array
    {
        $points = [];
        for ($i = 0; $i < count($waypoints) - 1; $i++) {
            [$lat1, $lng1] = $waypoints[$i];
            [$lat2, $lng2] = $waypoints[$i + 1];

            $steps = max(15, (int) (sqrt(($lat2 - $lat1) ** 2 + ($lng2 - $lng1) ** 2) * 4000));
            for ($s = 0; $s <= $steps; $s++) {
                $t = $s / $steps;
                $wiggle = sin($t * M_PI * 3) * 0.0006 * (1 - abs(2 * $t - 1));
                $lat = $lat1 + ($lat2 - $lat1) * $t - $wiggle;
                $lng = $lng1 + ($lng2 - $lng1) * $t + $wiggle;
                $points[] = [round($lat, 7), round($lng, 7)];
            }
        }

        return $points;
    }

    private function wipePreviousDemoData(): void
    {
        $this->warn('🗑️  حذف بيانات عرض الرادار التجريبية السابقة...');

        $driverUserIds = User::where('email', 'like', 'radar.driver%@' . self::EMAIL_DOMAIN)->pluck('id');
        $parentUserIds = User::where('email', 'like', 'radar.parent%@' . self::EMAIL_DOMAIN)->pluck('id');

        $driverIds = Driver::whereIn('user_id', $driverUserIds)->pluck('id');
        $tripIds = Trip::whereIn('driver_id', $driverIds)->pluck('id');

        TripTracking::whereIn('trip_id', $tripIds)->delete();
        TripStop::whereIn('trip_id', $tripIds)->delete();
        Trip::whereIn('id', $tripIds)->delete();

        $routeIds = Route::whereIn('driver_id', $driverIds)->pluck('id');
        DB::table('route_stops')->whereIn('route_id', $routeIds)->delete();

        $reqIds = SubscriptionRequest::whereIn('driver_id', $driverIds)->pluck('id');
        ActiveSubscription::whereIn('subscription_request_id', $reqIds)->delete();
        DB::table('request_children')->whereIn('request_id', $reqIds)->delete();
        DB::table('platform_finances')->whereIn('subscription_request_id', $reqIds)->delete();
        SubscriptionRequest::whereIn('id', $reqIds)->delete();

        Route::whereIn('id', $routeIds)->delete();
        Vehicle::whereIn('driver_id', $driverIds)->delete();
        Child::whereIn('parent_id', $parentUserIds)->forceDelete();
        Address::whereIn('user_id', $parentUserIds)->forceDelete();
        Driver::whereIn('id', $driverIds)->delete();
        User::whereIn('id', $driverUserIds->merge($parentUserIds))->forceDelete();

        foreach (glob(storage_path('app/drive-simulations/radar/*.json')) ?: [] as $file) {
            @unlink($file);
        }
    }
}
