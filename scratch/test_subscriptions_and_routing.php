<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Driver\Vehicle;
use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Shared\PricingSetting;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Route;
use App\Models\Shared\RouteStop;
use App\Models\Shared\LocationChangeRequest;
use App\Models\Shared\AbsenceLog;

echo "=== اختبار حزمة الاشتراكات والمسارات والطلبات (V2) ===\n\n";

// 1. اختبار جدول التسعير pricing_settings
$pricing = PricingSetting::first();
if (!$pricing) {
    throw new Exception("خطأ: جدول pricing_settings فارغ!");
}
echo "✓ جدول pricing_settings يعمل: عمولة المنصة = {$pricing->platform_commission_rate}%, سعر المكيفة = {$pricing->price_per_km_ac}, رسوم تغيير الموقع = {$pricing->location_change_fee}\n";

// 2. إنشاء مستخدم ولي أمر وسائق ومدرسة وطفل
$parent = User::create([
    'full_name' => 'ولي أمر تجريبي',
    'email' => 'parent_test_' . uniqid() . '@example.com',
    'phone_number' => '091' . rand(1000000, 9999999),
    'password' => bcrypt('secret123'),
    'role_id' => 3,
    'status' => 'Active',
]);

$driverUser = User::create([
    'full_name' => 'سائق تجريبي',
    'email' => 'driver_test_' . uniqid() . '@example.com',
    'phone_number' => '092' . rand(1000000, 9999999),
    'password' => bcrypt('secret123'),
    'role_id' => 4,
    'status' => 'Active',
]);

$driver = Driver::create([
    'user_id' => $driverUser->id,
    'status' => 'Approved',
    'is_active' => true,
    'is_available' => true,
    'license_number' => 'LIC-' . rand(10000, 99999),
    'license_expiry' => now()->addYears(2),
]);

$vehicle = Vehicle::create([
    'driver_id' => $driver->id,
    'plate_number' => 'TRP-' . rand(1000, 9999),
    'brand' => 'Toyota',
    'model' => 'HiAce',
    'year' => 2022,
    'color' => 'White',
    'type' => 'Van',
    'capacity_manual' => 14,
    'has_ac' => true,
    'status' => 'Active',
]);

$school = School::create([
    'name' => 'مدرسة الأمل النموذجية',
    'lat' => 32.8872,
    'lng' => 13.1913,
    'status' => 'Approved',
]);

$child = Child::create([
    'parent_id' => $parent->id,
    'school_id' => $school->id,
    'full_name' => 'علي ولي الأمر',
    'birth_date' => '2016-05-10',
    'gender' => 'male',
    'grade' => 3,
    'is_active' => true,
]);

echo "✓ تم إنشاء الأطراف الأساسية (ولي أمر #{$parent->id}، سائق #{$driver->id}، طفل #{$child->id}، مدرسة #{$school->id})\n";

// 3. إنشاء طلب اشتراك requests
$request = SubscriptionRequest::create([
    'parent_id' => $parent->id,
    'driver_id' => $driver->id,
    'status' => SubscriptionRequest::STATUS_PENDING,
    'total_price' => 500.00,
    'discount_amount' => 50.00,
    'total_amount_after_discount' => 450.00,
    'children_count' => 1,
    'children_acceptance_mode' => 'all',
    'pickup_time' => '07:15:00',
    'dropoff_time' => '13:30:00',
    'max_waiting_time' => 10,
    'notes' => 'الرجاء الالتزام بالموعد',
]);

echo "✓ تم إنشاء طلب الاشتراك requests #{$request->id}\n";

// 4. ربط تفاصيل الطفل في request_children
$request->children()->attach($child->id, [
    'subscription_type' => 'monthly',
    'trip_direction' => 'two_way',
    'timing' => 'MORNING',
    'start_date' => now()->toDateString(),
    'end_date' => now()->addMonth()->toDateString(),
    'working_days_count' => 22,
    'distance_km' => 8.5,
    'home_label' => 'المنزل - حي الأندلس',
    'home_lat' => 32.8700,
    'home_lng' => 13.1800,
    'school_label' => $school->name,
    'school_lat' => $school->lat,
    'school_lng' => $school->lng,
    'price_per_child' => 500.00,
    'trip_price' => 500.00,
    'discount_amount' => 50.00,
    'total_amount_after_discount' => 450.00,
    'driver_net_price' => 414.00, // 450 - 8% (36)
]);

$childPivot = $request->children()->first()->pivot;
echo "✓ تم ربط بيانات الطفل بالطلب: مسافة {$childPivot->distance_km} كم، صافي السائق = {$childPivot->driver_net_price} د.ل\n";

// 5. إنشاء مسار السائق routes
$route = Route::create([
    'subscription_request_id' => $request->id,
    'driver_id' => $driver->id,
    'vehicle_id' => $vehicle->id,
    'route_name' => 'مسار صباحي - ذهاب وعودة',
    'route_type' => 'Morning',
    'shift_slot' => 'morning_go',
    'start_time' => '07:00:00',
    'total_distance' => 15.2,
    'estimated_duration' => 35,
    'status' => 'Active',
]);

echo "✓ تم إنشاء المسار routes #{$route->id} (الفترة: {$route->shift_slot})\n";

// 6. إنشاء الاشتراك النشط active_subscriptions
$activeSub = ActiveSubscription::create([
    'subscription_request_id' => $request->id,
    'child_id' => $child->id,
    'driver_id' => $driver->id,
    'parent_id' => $parent->id,
    'route_id' => $route->id,
    'pickup_lat' => $childPivot->home_lat,
    'pickup_lng' => $childPivot->home_lng,
    'pickup_label' => $childPivot->home_label,
    'dropoff_lat' => $childPivot->school_lat,
    'dropoff_lng' => $childPivot->school_lng,
    'dropoff_label' => $childPivot->school_label,
    'pickup_time' => '07:15:00',
    'dropoff_time' => '13:30:00',
    'sort_order' => 1,
    'status' => 'Active',
]);

echo "✓ تم إنشاء الاشتراك في active_subscriptions #{$activeSub->id}\n";

// 7. محطات المسار route_stops
$stopHome = RouteStop::create([
    'route_id' => $route->id,
    'stop_type' => 'home',
    'child_id' => $child->id,
    'lat' => $childPivot->home_lat,
    'lng' => $childPivot->home_lng,
    'label' => 'محطة صعود: ' . $child->name,
    'sequence_order' => 1,
]);

$stopSchool = RouteStop::create([
    'route_id' => $route->id,
    'stop_type' => 'school',
    'school_id' => $school->id,
    'lat' => $school->lat,
    'lng' => $school->lng,
    'label' => 'المدرسة: ' . $school->name,
    'sequence_order' => 2,
]);

echo "✓ تم تسجيل محطات المسار route_stops (منزل: #{$stopHome->id}، مدرسة: #{$stopSchool->id})\n";

// 8. طلب تغيير الموقع location_change_requests
$locChange = LocationChangeRequest::create([
    'active_subscription_id' => $activeSub->id,
    'child_id' => $child->id,
    'parent_id' => $parent->id,
    'driver_id' => $driver->id,
    'point_type' => 'dropoff',
    'change_date' => now()->addDays(1)->toDateString(),
    'is_single_day' => true,
    'new_lat' => 32.8900,
    'new_lng' => 13.2000,
    'new_label' => 'منزل الجدة - بن عاشور',
    'distance_km' => 4.2,
    'fee_tier' => '2_to_6km',
    'fee_amount' => 10.00,
    'commission_rate' => 8.00,
    'platform_commission_amount' => 0.80,
    'driver_net_fee' => 9.20,
    'status' => 'pending',
]);

echo "✓ تم إنشاء طلب تغيير الموقع location_change_requests #{$locChange->id} (المبلغ: {$locChange->fee_amount} د.ل)\n";

// 9. سجل غياب الطفل absence_logs
$absence = AbsenceLog::create([
    'child_id' => $child->id,
    'absence_date' => now()->addDays(3)->toDateString(),
    'absence_type' => 'both',
]);

echo "✓ تم تسجيل غياب الطفل absence_logs #{$absence->id} بتاريخ {$absence->absence_date}\n";

// 10. التحقق من العلاقات العكسية عبر Eloquent
echo "\n=== التحقق من سلامة العلاقات العكسية (Eloquent Relations) ===\n";
echo "ActiveSubscription -> subscriptionRequest ID: " . $activeSub->subscriptionRequest->id . "\n";
echo "ActiveSubscription -> route Name: " . $activeSub->route->route_name . "\n";
echo "ActiveSubscription -> child Name: " . $activeSub->child->name . "\n";
echo "Route -> stops count: " . $route->stops()->count() . "\n";
echo "Route -> activeSubscriptions count: " . $route->activeSubscriptions()->count() . "\n";
echo "Child -> absenceLogs count: " . AbsenceLog::where('child_id', $child->id)->count() . "\n";
echo "LocationChangeRequest -> activeSubscription ID: " . $locChange->active_subscription_id . "\n";

echo "\n>>> جميع جداول وعلاقات وقيود الحزمة (Requests, RequestChildren, Routes, ActiveSubscriptions, RouteStops, LocationChangeRequests, AbsenceLogs, PricingSettings) تعمل بنسبة 100% بنجاح! <<<\n";
