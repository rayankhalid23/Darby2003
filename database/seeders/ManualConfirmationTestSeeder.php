<?php

namespace Database\Seeders;

use App\Models\Driver\Driver;
use App\Models\Driver\Vehicle;
use App\Models\Parent\Address;
use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\Trip;
use App\Models\Shared\TripStop;
use App\Models\Shared\Zone;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * ManualConfirmationTestSeeder
 *
 * الغرض: تزويد فريق QA/الفرونت ببيانات جاهزة ونظيفة 100% لاختبار التأكيد اليدوي
 * (Pickup & Dropoff & Skip) مع Geofence متطابق تماماً مع إحداثيات السائق والمحطة،
 * تغطي 5 حالات اختبار: صعود خارج النطاق، صعود ناجح، نزول خارج النطاق، نزول ناجح، تخطي.
 *
 * القواعد:
 *  1) APPEND ONLY - لا يحذف ولا يعدل أي بيانات سابقة غير محطات هذا السائق التجريبي.
 *  2) Idempotent - آمن للتشغيل المتكرر (يعيد ضبط حالة المحطات إلى pending في كل مرة
 *     كي يمكن إعادة تجربة نفس السيناريوهات من الصفر).
 *  3) كلمة المرور الموحدة: Password123!
 */
class ManualConfirmationTestSeeder extends Seeder
{
    public const DEFAULT_PASSWORD = 'Password123!';
    public const DRIVER_EMAIL     = 'driver.test.manual@darby.ly';
    public const PARENT_EMAIL     = 'parent.test.manual@darby.ly';

    // إحداثيات صحيحة (داخل النطاق المسموح: 100م منزل / 200م مدرسة)
    public const HOME_LAT   = 32.880000;
    public const HOME_LNG   = 13.180000;

    // إحداثيات بعيدة عمداً (~10كم) لاختبار رفض OUT_OF_RANGE
    public const FAR_LAT = 32.950000;
    public const FAR_LNG = 13.250000;

    public function run(): void
    {
        $this->command?->info('🚗 بدء تشغيل ManualConfirmationTestSeeder (Append Only)...');

        $driverRoleId = DB::table('roles')->where('name', 'driver')->value('id') ?? 8;
        $parentRoleId = DB::table('roles')->where('name', 'parent')->value('id') ?? 9;

        // 1. إنشاء أو جلب السائق
        $driverUser = User::where('email', self::DRIVER_EMAIL)->first();
        if (!$driverUser) {
            $driverUser = User::create([
                'full_name'         => 'سائق الاختبار اليدوي (كابتن عادل)',
                'email'             => self::DRIVER_EMAIL,
                'phone_number'      => '0919998877',
                'password'          => Hash::make(self::DEFAULT_PASSWORD),
                'role_id'           => $driverRoleId,
                'is_active'         => true,
                'is_trusted'        => true,
                'gender'            => 'male',
                'email_verified_at' => now(),
            ]);
        }

        $driver = Driver::where('user_id', $driverUser->id)->first();
        if (!$driver) {
            $driver = Driver::create([
                'user_id'            => $driverUser->id,
                'national_id'        => '119899988771',
                'license_number'     => 'LY-TR-TEST-99',
                'license_expiry'     => Carbon::now()->addYears(2)->format('Y-m-d'),
                'status'             => 'Approved',
                'shift'              => 'both',
                'morning_go'         => 1,
                'morning_return'     => 1,
                'afternoon_go'       => 1,
                'afternoon_return'   => 1,
                'subscription_type'  => 'multi_day',
                'accepted_gender'    => 'both',
                'current_lat'        => 32.880000,
                'current_lng'        => 13.180000,
                'last_ping_at'       => now(),
            ]);

            Vehicle::create([
                'driver_id'       => $driver->id,
                'plate_number'    => '5-TEST-99',
                'brand'           => 'Toyota',
                'model'           => 'HiAce',
                'year'            => 2023,
                'color'           => 'أبيض',
                'type'            => 'Bus',
                'capacity_manual' => 14,
                'has_ac'          => 1,
                'status'          => 'Active',
            ]);
        } else {
            $driver->update([
                'current_lat'  => 32.880000,
                'current_lng'  => 13.180000,
                'last_ping_at' => now(),
            ]);
        }

        // 2. إنشاء أو جلب ولي الأمر والطفل
        $parentUser = User::where('email', self::PARENT_EMAIL)->first();
        if (!$parentUser) {
            $parentUser = User::create([
                'full_name'         => 'ولي أمر تجريبي (أحمد سالم)',
                'email'             => self::PARENT_EMAIL,
                'phone_number'      => '0917778899',
                'password'          => Hash::make(self::DEFAULT_PASSWORD),
                'role_id'           => $parentRoleId,
                'is_active'         => true,
                'is_trusted'        => true,
                'gender'            => 'male',
                'email_verified_at' => now(),
            ]);
        }

        $address = Address::where('user_id', $parentUser->id)->first();
        if (!$address) {
            $address = Address::create([
                'user_id'    => $parentUser->id,
                'label'      => 'منزل تجريبي',
                'lat'        => 32.880000,
                'lng'        => 13.180000,
                'zone_id'    => Zone::first()->id ?? 1,
                'is_default' => true,
            ]);
        }

        $school = School::first();

        // الطفل 1: "طارق" — يُستخدم للمسار الكامل (صعود خارج النطاق → صعود ناجح → نزول خارج النطاق → نزول ناجح)
        $child = Child::where('parent_id', $parentUser->id)->where('full_name', 'like', 'طارق%')->first();
        if (!$child) {
            $child = Child::create([
                'parent_id'     => $parentUser->id,
                'full_name'     => 'طارق أحمد سالم (طالب تجريبي - صعود/نزول)',
                'school_id'     => $school?->id ?? 1,
                'grade'         => 5,
                'gender'        => 'male',
                'qr_code_token' => 'TEST-QR-MANUAL-' . Str::upper(Str::random(6)),
                'address_id'    => $address->id,
                'birth_date'    => Carbon::now()->subYears(10)->format('Y-m-d'),
                'is_active'     => true,
            ]);
        }

        // الطفل 2: "سلمى" — مخصصة فقط لاختبار التخطي (Skip)، تبقى بلا صعود/نزول
        $child2 = Child::where('parent_id', $parentUser->id)->where('full_name', 'like', 'سلمى%')->first();
        if (!$child2) {
            $child2 = Child::create([
                'parent_id'     => $parentUser->id,
                'full_name'     => 'سلمى خالد (طالبة تجريبية - تخطي)',
                'school_id'     => $school?->id ?? 1,
                'grade'         => 3,
                'gender'        => 'female',
                'qr_code_token' => 'TEST-QR-MANUAL-' . Str::upper(Str::random(6)),
                'address_id'    => $address->id,
                'birth_date'    => Carbon::now()->subYears(8)->format('Y-m-d'),
                'is_active'     => true,
            ]);
        }

        // 3. عقد اشتراك نشط ActiveSubscription (نفس الطلب لكلا الطفلين — driver_id/parent_id
        // في هذا الجدول محسوبان الآن عبر subscription_request_id، لا عمودان مباشران)
        $subReq = SubscriptionRequest::where('parent_id', $parentUser->id)->where('driver_id', $driver->id)->first();
        if (!$subReq) {
            $subReq = SubscriptionRequest::create([
                'parent_id'                   => $parentUser->id,
                'driver_id'                   => $driver->id,
                'status'                      => 'accepted',
                'start_date'                  => now()->subDays(2)->format('Y-m-d'),
                'end_date'                    => now()->addMonths(1)->format('Y-m-d'),
                'total_price'                 => 360.00,
                'total_amount_after_discount' => 360.00,
                'driver_net_amount'           => 320.00,
                'children_count'              => 2,
                'pickup_time'                 => '07:30:00',
                'dropoff_time'                => '13:30:00',
                'subscription_type'           => 'monthly',
                'trip_direction'              => 'both',
                'home_label'                  => $address->label,
                'home_lat'                    => $address->lat,
                'home_lng'                    => $address->lng,
                'home_address_id'             => $address->id,
            ]);
        }

        $makeActiveSub = function (Child $forChild, string $pickupLabel) use ($subReq, $school): ActiveSubscription {
            $reqChild = DB::table('request_children')->where('request_id', $subReq->id)->where('child_id', $forChild->id)->first();
            if (!$reqChild) {
                $rcId = DB::table('request_children')->insertGetId([
                    'request_id'                  => $subReq->id,
                    'child_id'                    => $forChild->id,
                    'school_id'                   => $forChild->school_id,
                    'timing'                      => 'both',
                    'distance_km'                 => 4.5,
                    'billable_distance_km'        => 4.5,
                    'school_label'                => $school?->name ?? 'مدرسة النور',
                    'school_lat'                  => $school?->lat ?? 32.885000,
                    'school_lng'                  => $school?->lng ?? 13.185000,
                    'price_per_child'             => 9.00,
                    'trip_price'                  => 4.50,
                    'trip_price_after_discount'   => 4.50,
                    'daily_price'                 => 9.00,
                    'discount_amount'             => 0,
                    'total_amount_after_discount' => 180.00,
                    'driver_net_price'            => 160.00,
                    'created_at'                  => now(),
                    'updated_at'                  => now(),
                ]);
            } else {
                $rcId = $reqChild->id;
            }

            $activeSub = ActiveSubscription::where('request_child_id', $rcId)->first();
            if (!$activeSub) {
                $activeSub = ActiveSubscription::create([
                    'subscription_request_id' => $subReq->id,
                    'request_child_id'        => $rcId,
                    'pickup_lat'              => self::HOME_LAT,
                    'pickup_lng'              => self::HOME_LNG,
                    'pickup_label'            => $pickupLabel,
                    'dropoff_lat'             => $school?->lat ?? 32.885000,
                    'dropoff_lng'             => $school?->lng ?? 13.185000,
                    'dropoff_label'           => 'مدرسة النور الابتدائية (32.8850, 13.1850)',
                    'pickup_time'             => '07:30:00',
                    'dropoff_time'            => '13:30:00',
                    'sort_order'              => 1,
                    'status'                  => 'active',
                ]);
            }

            return $activeSub;
        };

        $activeSub  = $makeActiveSub($child, 'منزل طارق (32.8800, 13.1800)');
        $activeSub2 = $makeActiveSub($child2, 'منزل سلمى (32.8800, 13.1800)');

        // 4. إنشاء رحلة نشطة حالياً (in_progress) جاهزة لتجربة التأكيد اليدوي
        $trip = Trip::where('driver_id', $driver->id)->where('status', 'in_progress')->first();
        if (!$trip) {
            $trip = Trip::create([
                'driver_id'   => $driver->id,
                'trip_date'   => now()->format('Y-m-d'),
                'trip_type'   => 'Morning',
                'shift_slot'  => 'morning_go',
                'status'      => 'in_progress',
                'start_lat'   => self::HOME_LAT,
                'start_lng'   => self::HOME_LNG,
                'started_at'  => now()->subMinutes(10),
            ]);
        }

        // 5. محطات الرحلة (TripStops) — تُعاد لحالة pending في كل تشغيل كي يمكن إعادة الاختبار
        $homeStop = TripStop::where('trip_id', $trip->id)->where('child_id', $child->id)->where('stop_type', 'home')->first();
        if (!$homeStop) {
            $homeStop = TripStop::create([
                'trip_id'        => $trip->id,
                'child_id'       => $child->id,
                'stop_type'      => 'home',
                'lat'            => self::HOME_LAT,
                'lng'            => self::HOME_LNG,
                'sequence_order' => 1,
                'status'         => 'pending',
                'label'          => 'منزل الطالب طارق',
            ]);
        } else {
            $homeStop->update(['status' => 'pending', 'reason' => null]);
        }

        $homeStop2 = TripStop::where('trip_id', $trip->id)->where('child_id', $child2->id)->where('stop_type', 'home')->first();
        if (!$homeStop2) {
            $homeStop2 = TripStop::create([
                'trip_id'        => $trip->id,
                'child_id'       => $child2->id,
                'stop_type'      => 'home',
                'lat'            => self::HOME_LAT,
                'lng'            => self::HOME_LNG,
                'sequence_order' => 2,
                'status'         => 'pending',
                'label'          => 'منزل الطالبة سلمى',
            ]);
        } else {
            $homeStop2->update(['status' => 'pending', 'reason' => null]);
        }

        $schoolStop = TripStop::where('trip_id', $trip->id)->where('stop_type', 'school')->first();
        if (!$schoolStop) {
            $schoolStop = TripStop::create([
                'trip_id'        => $trip->id,
                'child_id'       => $child->id,
                'stop_type'      => 'school',
                'lat'            => $school?->lat ?? 32.885000,
                'lng'            => $school?->lng ?? 13.185000,
                'sequence_order' => 3,
                'status'         => 'pending',
                'label'          => 'مدرسة النور الابتدائية',
            ]);
        } else {
            $schoolStop->update(['status' => 'pending']);
        }

        $this->command?->info("✅ اكتمل تجهيز بيانات الاختبار اليدوي بنجاح!");
        $this->command?->info("   👤 حساب السائق: " . self::DRIVER_EMAIL . " | كلمة المرور: " . self::DEFAULT_PASSWORD);
        $this->command?->info("   🚘 trip_id = {$trip->id}  (status = in_progress)");
        $this->command?->info("   👶 طارق  -> trip_child_id = {$activeSub->id}   (child_id={$child->id})");
        $this->command?->info("   👧 سلمى  -> trip_child_id = {$activeSub2->id}  (child_id={$child2->id})");
        $this->command?->info("   📍 إحداثيات المنزل (صحيحة): lat=" . self::HOME_LAT . " lng=" . self::HOME_LNG);
        $this->command?->info("   🏫 إحداثيات المدرسة (صحيحة): lat={$schoolStop->lat} lng={$schoolStop->lng}");
        $this->command?->info("   🚫 إحداثيات بعيدة (لاختبار OUT_OF_RANGE): lat=" . self::FAR_LAT . " lng=" . self::FAR_LNG);
    }
}
