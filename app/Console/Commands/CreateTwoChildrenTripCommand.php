<?php

namespace App\Console\Commands;

use App\Models\Driver\Driver;
use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\RequestChild;
use App\Models\Shared\Route;
use App\Models\Shared\RouteStop;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateTwoChildrenTripCommand extends Command
{
    /**
     * اسم وتوقيع الأمر
     */
    protected $signature = 'trip:create-2children 
                            {--type=Morning : نوع الوردية (Morning أو Afternoon)} 
                            {--date= : تاريخ الرحلة YYYY-MM-DD (افتراضياً تاريخ اليوم)}
                            {--start : بدء الرحلة فوراً وجعلها قيد التشغيل in_progress}';

    /**
     * وصف الأمر
     */
    protected $description = 'إنشاء وتجهيز رحلة جديدة تحتوي على طفلين فقط للسائق الأساسي لاختبارها في Postman';

    public function handle(): int
    {
        $today = $this->option('date') ? Carbon::parse($this->option('date'))->toDateString() : Carbon::today()->toDateString();
        $tripType = ucfirst(strtolower($this->option('type') ?: 'Morning'));
        $shouldStart = (bool) $this->option('start');

        $this->info("🚀 جاري تجهيز رحلة جديدة بطفلين فقط للسائق الأساسي لتاريخ [{$today}] ونوع [{$tripType}]...");

        // 1. العثور على السائق الأساسي أو إنشاؤه
        $driverUser = User::firstOrCreate(
            ['email' => 'driver_main@darby.ly'],
            [
                'full_name'    => 'عبد السلام الأشهب (السائق الأساسي)',
                'phone_number' => '0919999010',
                'password'     => Hash::make('password123'),
                'role_id'      => 8,
                'is_active'    => 1,
            ]
        );

        $driver = Driver::firstOrCreate(
            ['user_id' => $driverUser->id],
            [
                'national_id'    => '119999990010',
                'license_number' => 'DL-991100',
                'license_expiry' => now()->addYears(2),
                'status'         => 'Approved',
                'current_lat'    => 32.887200,
                'current_lng'    => 13.155400,
            ]
        );

        $vehicle = $driver->vehicles()->firstOrCreate(
            ['plate_number' => '5-11111'],
            [
                'brand'             => 'تويوتا',
                'model'             => 'كوستر',
                'year'              => 2022,
                'color'             => 'أبيض',
                'capacity_manual'   => 14,
                'has_ac'            => 1,
                'status'            => 'Active',
                'vehicle_image_url' => 'https://example.com/bus_main.jpg',
            ]
        );

        $driverToken = $driverUser->createToken('main_driver_postman')->plainTextToken;

        // 2. حساب ولي الأمر
        $parentUser = User::firstOrCreate(
            ['email' => 'parent_emergency@darby.ly'],
            [
                'full_name'         => 'علي مصطفى الترهوني',
                'phone_number'      => '0919999002',
                'alternative_phone' => '0929999002',
                'password'          => Hash::make('password123'),
                'role_id'           => 7,
                'is_active'         => 1,
            ]
        );

        // 3. المدرسة
        $school = School::firstOrCreate(
            ['name' => 'مدرسة طرابلس المركزية'],
            [
                'address'   => 'شارع عمر المختار، طرابلس',
                'lat'       => 32.889000,
                'lng'       => 13.185000,
                'is_active' => 1,
            ]
        );

        // 4. الطفلان فقط
        $child1 = Child::updateOrCreate(
            ['full_name' => 'سند طه القمودي', 'parent_id' => $parentUser->id],
            [
                'school_id'  => $school->id,
                'birth_date' => '2015-03-01',
                'gender'     => 'male',
                'grade'      => 4,
                'is_active'  => 1,
            ]
        );

        $child2 = Child::updateOrCreate(
            ['full_name' => 'أحمد علي سالم', 'parent_id' => $parentUser->id],
            [
                'school_id'  => $school->id,
                'birth_date' => '2016-05-15',
                'gender'     => 'male',
                'grade'      => 3,
                'is_active'  => 1,
            ]
        );

        // 5. طلب الاشتراك (SubscriptionRequest)
        $subRequest = SubscriptionRequest::updateOrCreate(
            [
                'parent_id' => $parentUser->id,
                'driver_id' => $driver->id,
            ],
            [
                'subscription_type' => 'monthly',
                'trip_direction'    => 'two_way',
                'status'            => SubscriptionRequest::STATUS_ACCEPTED,
                'home_lat'          => 32.882100,
                'home_lng'          => 13.142000,
                'home_label'        => 'حي الأندلس / السياحية',
            ]
        );

        // 6. المسار الأساسي (Route)
        $shiftSlot = ($tripType === 'Morning') ? 'morning_go' : 'morning_return';
        $routeName = "مسار {$tripType} - طفلين فقط";

        $route = Route::updateOrCreate(
            ['driver_id' => $driver->id, 'route_name' => $routeName],
            [
                'vehicle_id'              => $vehicle->id,
                'subscription_request_id' => $subRequest->id,
                'route_type'              => $tripType,
                'shift_slot'              => $shiftSlot,
                'status'                  => 'Active',
                'start_time'              => ($tripType === 'Morning') ? '07:00:00' : '13:30:00',
            ]
        );

        // 7. تفعيل الاشتراكات للطفلين مع هذا المسار والسائق
        RouteStop::where('route_id', $route->id)->delete();

        foreach ([$child1, $child2] as $idx => $child) {
            $reqChild = RequestChild::updateOrCreate(
                [
                    'request_id' => $subRequest->id,
                    'child_id'   => $child->id,
                ],
                [
                    'school_id'    => $school->id,
                    'school_label' => $school->name,
                    'school_lat'   => $school->lat,
                    'school_lng'   => $school->lng,
                ]
            );

            $pickupLat = ($idx === 0) ? 32.882100 : 32.875000;
            $pickupLng = ($idx === 0) ? 13.142000 : 13.168000;
            $pickupLabel = "منزل " . $child->full_name;

            ActiveSubscription::updateOrCreate(
                [
                    'subscription_request_id' => $subRequest->id,
                    'request_child_id'        => $reqChild->id,
                ],
                [
                    'route_id'      => $route->id,
                    'pickup_lat'    => $pickupLat,
                    'pickup_lng'    => $pickupLng,
                    'pickup_label'  => $pickupLabel,
                    'dropoff_lat'   => 32.889000,
                    'dropoff_lng'   => 13.185000,
                    'dropoff_label' => $school->name,
                    'sort_order'    => $idx + 1,
                    'status'        => 'active',
                ]
            );

            // محطات المسار الأساسي
            RouteStop::create([
                'route_id'       => $route->id,
                'stop_type'      => 'home',
                'child_id'       => $child->id,
                'lat'            => $pickupLat,
                'lng'            => $pickupLng,
                'label'          => $pickupLabel,
                'sequence_order' => $idx + 1,
            ]);
        }

        // محطة المدرسة في المسار الأساسي
        RouteStop::create([
            'route_id'       => $route->id,
            'stop_type'      => 'school',
            'school_id'      => $school->id,
            'lat'            => 32.889000,
            'lng'            => 13.185000,
            'label'          => $school->name,
            'sequence_order' => 3,
        ]);

        // 7. إنشاء الرحلة (Trip)
        $tripStatus = $shouldStart ? 'in_progress' : 'pending';

        $trip = Trip::create([
            'driver_id'            => $driver->id,
            'route_id'             => $route->id,
            'trip_type'            => $tripType,
            'shift_slot'           => $shiftSlot,
            'status'               => $tripStatus,
            'scheduled_start_time' => ($tripType === 'Morning') ? '07:00:00' : '13:30:00',
            'actual_start_time'    => $shouldStart ? now() : null,
            'started_at'           => $shouldStart ? now() : null,
            'start_lat'            => 32.887200,
            'start_lng'            => 13.155400,
            'trip_date'            => $today,
        ]);

        // 8. إنشاء محطات الرحلة (طفلين + المدرسة)
        if ($tripType === 'Morning') {
            // محطة 1: منزل سند (السياحية)
            TripStop::create([
                'trip_id'        => $trip->id,
                'stop_type'      => 'home',
                'child_id'       => $child1->id,
                'school_id'      => $school->id,
                'lat'            => 32.882100,
                'lng'            => 13.142000,
                'label'          => 'منزل سند طه (السياحية)',
                'sequence_order' => 1,
                'status'         => TripStop::STATUS_PENDING,
            ]);

            // محطة 2: منزل أحمد (قرقارش)
            TripStop::create([
                'trip_id'        => $trip->id,
                'stop_type'      => 'home',
                'child_id'       => $child2->id,
                'school_id'      => $school->id,
                'lat'            => 32.875000,
                'lng'            => 13.168000,
                'label'          => 'منزل أحمد علي (قرقارش)',
                'sequence_order' => 2,
                'status'         => TripStop::STATUS_PENDING,
            ]);

            // محطة 3: مدرسة طرابلس المركزية
            TripStop::create([
                'trip_id'        => $trip->id,
                'stop_type'      => 'school',
                'school_id'      => $school->id,
                'lat'            => 32.889000,
                'lng'            => 13.185000,
                'label'          => 'مدرسة طرابلس المركزية',
                'sequence_order' => 3,
                'status'         => TripStop::STATUS_PENDING,
            ]);
        } else {
            // رحلة عودة (إياب): المدرسة أولاً ثم منازل الطفلين
            TripStop::create([
                'trip_id'        => $trip->id,
                'stop_type'      => 'school',
                'school_id'      => $school->id,
                'lat'            => 32.889000,
                'lng'            => 13.185000,
                'label'          => 'مدرسة طرابلس المركزية (استلام الطفلين)',
                'sequence_order' => 1,
                'status'         => TripStop::STATUS_PENDING,
            ]);

            TripStop::create([
                'trip_id'        => $trip->id,
                'stop_type'      => 'home',
                'child_id'       => $child2->id,
                'school_id'      => $school->id,
                'lat'            => 32.875000,
                'lng'            => 13.168000,
                'label'          => 'تنزيل أحمد علي (قرقارش)',
                'sequence_order' => 2,
                'status'         => TripStop::STATUS_PENDING,
            ]);

            TripStop::create([
                'trip_id'        => $trip->id,
                'stop_type'      => 'home',
                'child_id'       => $child1->id,
                'school_id'      => $school->id,
                'lat'            => 32.882100,
                'lng'            => 13.142000,
                'label'          => 'تنزيل سند طه (السياحية)',
                'sequence_order' => 3,
                'status'         => TripStop::STATUS_PENDING,
            ]);
        }

        $this->info("🎉 تم إنشاء الرحلة المخصصة بطفلين بنجاح!");

        $this->table(
            ['معرف الرحلة (Trip ID)', 'السائق', 'النوع', 'الحالة', 'عدد الأطفال', 'التاريخ'],
            [[
                $trip->id,
                $driverUser->full_name,
                $trip->trip_type,
                $trip->status,
                2,
                $trip->trip_date,
            ]]
        );

        $this->line("\n🔑 <comment>بيانات الدخول للـ Postman:</comment>");
        $this->line("• البريد الإلكتروني: <info>{$driverUser->email}</info>");
        $this->line("• كلمة المرور: <info>password123</info>");
        $this->line("• التوكن الجاهز: <info>{$driverToken}</info>");

        $this->line("\n👦 <comment>الطفلان في هذه الرحلة:</comment>");
        $this->line("1. <info>سند طه القمودي</info> (Child ID: <comment>{$child1->id}</comment>) - السياحية");
        $this->line("2. <info>أحمد علي سالم</info> (Child ID: <comment>{$child2->id}</comment>) - قرقارش");

        $this->line("\n📍 <comment>محطات الرحلة:</comment>");
        $stops = TripStop::where('trip_id', $trip->id)->orderBy('sequence_order')->get();
        $rows = [];
        foreach ($stops as $s) {
            $rows[] = [$s->sequence_order, $s->stop_type, $s->label, "{$s->lat}, {$s->lng}", $s->status];
        }
        $this->table(['الترتيب', 'النوع', 'التسمية', 'الإحداثيات', 'الحالة'], $rows);

        return Command::SUCCESS;
    }
}
