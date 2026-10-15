<?php

namespace Tests\Unit\Services\Driver;

use App\Models\Admin\AdminAlert;
use App\Models\Driver\Driver;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\SubscriptionRequest;
use App\Models\User;
use App\Services\Driver\DriverInactivityAlertService;
use App\Services\Notification\NotificationFormatter;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DriverInactivityAlertServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected User $driverUser;
    protected Driver $driver;
    protected DriverInactivityAlertService $service;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('roles')->insertOrIgnore([
            ['id' => 1, 'name' => 'super_admin', 'display_name' => 'مدير النظام'],
            ['id' => 2, 'name' => 'admin', 'display_name' => 'مشرف'],
            ['id' => 4, 'name' => 'driver', 'display_name' => 'سائق'],
        ]);

        $this->adminUser = User::create([
            'full_name'     => 'مدير النظام تجريبي',
            'email'         => 'admin.' . uniqid() . '@example.com',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password'),
            'role_id'       => 1,
            'is_active'     => 1,
        ]);

        $this->driverUser = User::create([
            'full_name'     => 'سائق تجريبي للاختبار',
            'email'         => 'driver.' . uniqid() . '@example.com',
            'phone_number'  => '092' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password'),
            'role_id'       => 4,
            'is_active'     => 1,
        ]);

        $this->driver = Driver::create([
            'user_id'           => $this->driverUser->id,
            'national_id'       => '1199' . rand(100000, 999999),
            'license_number'    => 'DL' . rand(10000, 99999),
            'status'            => 'Approved',
            'rating_avg'        => 5.0,
            'shift'             => 'Morning',
            'subscription_type' => 'both',
        ]);

        $this->service = app(DriverInactivityAlertService::class);
    }

    public function test_no_alert_when_driver_has_active_subscriptions(): void
    {
        // إنشاء طلب اشتراك واشتراك نشط للسائق
        $req = SubscriptionRequest::create([
            'driver_id'         => $this->driver->id,
            'parent_id'         => $this->adminUser->id,
            'status'            => 'accepted',
            'subscription_type' => 'both',
            'trip_direction'    => 'both',
        ]);

        ActiveSubscription::create([
            'subscription_request_id' => $req->id,
            'status'                  => 'active',
        ]);

        // حتى لو رفض 5 طلبات
        for ($i = 0; $i < 5; $i++) {
            SubscriptionRequest::create([
                'driver_id'         => $this->driver->id,
                'parent_id'         => $this->adminUser->id,
                'status'            => SubscriptionRequest::STATUS_REJECTED,
                'subscription_type' => 'both',
                'trip_direction'    => 'both',
                'updated_at'        => Carbon::now()->subDays(1),
            ]);
        }

        $result = $this->service->checkAndAlertOnExcessiveRejections($this->driver);

        $this->assertFalse($result['alerted']);
        $this->assertSame('driver_has_active_subscriptions', $result['reason']);
        $this->assertDatabaseMissing('admin_alerts', [
            'driver_id'  => $this->driver->id,
            'alert_type' => 'driver_inactivity_rejections',
        ]);
    }

    public function test_no_alert_when_rejections_below_threshold(): void
    {
        // رفض طلبين فقط (أقل من العتبة 3)
        for ($i = 0; $i < 2; $i++) {
            SubscriptionRequest::create([
                'driver_id'         => $this->driver->id,
                'parent_id'         => $this->adminUser->id,
                'status'            => SubscriptionRequest::STATUS_REJECTED,
                'subscription_type' => 'both',
                'trip_direction'    => 'both',
                'responded_at'      => Carbon::now()->subDays(1),
                'updated_at'        => Carbon::now()->subDays(1),
            ]);
        }

        $result = $this->service->checkAndAlertOnExcessiveRejections($this->driver);

        $this->assertFalse($result['alerted']);
        $this->assertSame('threshold_not_reached', $result['reason']);
        $this->assertSame(2, $result['rejections_count']);
        $this->assertDatabaseMissing('admin_alerts', [
            'driver_id'  => $this->driver->id,
            'alert_type' => 'driver_inactivity_rejections',
        ]);
    }

    public function test_triggers_alert_to_driver_and_admin_when_inactive_and_excessive_rejections(): void
    {
        // إنشاء 3 طلبات مرفوضة خلال آخر 7 أيام
        for ($i = 0; $i < 3; $i++) {
            SubscriptionRequest::create([
                'driver_id'         => $this->driver->id,
                'parent_id'         => $this->adminUser->id,
                'status'            => SubscriptionRequest::STATUS_REJECTED,
                'subscription_type' => 'both',
                'trip_direction'    => 'both',
                'responded_at'      => Carbon::now()->subDays(2),
                'updated_at'        => Carbon::now()->subDays(2),
            ]);
        }

        $result = $this->service->checkAndAlertOnExcessiveRejections($this->driver);

        $this->assertTrue($result['alerted']);
        $this->assertSame(3, $result['rejections_count']);
        $this->assertNotNull($result['alert_id']);

        // التحقق من إنشاء AdminAlert
        $this->assertDatabaseHas('admin_alerts', [
            'id'         => $result['alert_id'],
            'driver_id'  => $this->driver->id,
            'alert_type' => 'driver_inactivity_rejections',
            'risk_level' => 'WARNING',
        ]);

        // التحقق من وصول إشعار للسائق
        $driverNotif = $this->driverUser->notifications()->latest()->first();
        $this->assertNotNull($driverNotif);
        $this->assertEquals(NotificationFormatter::TYPE_DRIVER_INACTIVITY_REJECTION_ALERT, $driverNotif->data['type']);
        $this->assertStringContainsString('3', $driverNotif->data['message']);

        // التحقق من وصول إشعار للمدير
        $adminNotif = $this->adminUser->notifications()->latest()->first();
        $this->assertNotNull($adminNotif);
        $this->assertEquals(NotificationFormatter::TYPE_DRIVER_INACTIVITY_REJECTION_ADMIN, $adminNotif->data['type']);
        $this->assertStringContainsString('3', $adminNotif->data['message']);

        // التأكد من عدم تغيير حالة السائق (حسب الطلب: رقم 4 مانبيهاش)
        $this->driver->refresh();
        $this->assertSame('Approved', $this->driver->status);
    }

    public function test_respects_cooldown_period_to_prevent_duplicate_alerts(): void
    {
        // 3 طلبات مرفوضة
        for ($i = 0; $i < 3; $i++) {
            SubscriptionRequest::create([
                'driver_id'         => $this->driver->id,
                'parent_id'         => $this->adminUser->id,
                'status'            => SubscriptionRequest::STATUS_REJECTED,
                'subscription_type' => 'both',
                'trip_direction'    => 'both',
                'responded_at'      => Carbon::now()->subDays(2),
                'updated_at'        => Carbon::now()->subDays(2),
            ]);
        }

        // التنبيه الأول ينجح
        $first = $this->service->checkAndAlertOnExcessiveRejections($this->driver);
        $this->assertTrue($first['alerted']);

        // طلب رفض إضافي بعد ساعة
        SubscriptionRequest::create([
            'driver_id'         => $this->driver->id,
            'parent_id'         => $this->adminUser->id,
            'status'            => SubscriptionRequest::STATUS_REJECTED,
            'subscription_type' => 'both',
            'trip_direction'    => 'both',
            'responded_at'      => Carbon::now(),
            'updated_at'        => Carbon::now(),
        ]);

        // التنبيه الثاني يجب أن يتوقف بسبب الـ Cooldown
        $second = $this->service->checkAndAlertOnExcessiveRejections($this->driver);
        $this->assertFalse($second['alerted']);
        $this->assertSame('cooldown_active', $second['reason']);

        // التأكد من وجود سجل تنبيه واحد فقط في قاعدة البيانات
        $this->assertEquals(1, AdminAlert::where('driver_id', $this->driver->id)->where('alert_type', 'driver_inactivity_rejections')->count());
    }

    public function test_rejection_via_subscription_request_service_triggers_inactivity_check(): void
    {
        $parentUser = User::create([
            'full_name'     => 'ولي أمر تجريبي',
            'email'         => 'parent.' . uniqid() . '@example.com',
            'phone_number'  => '093' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password'),
            'role_id'       => 3,
            'is_active'     => 1,
        ]);

        // تجهيز طلبين مرفوضين سابقاً
        for ($i = 0; $i < 2; $i++) {
            SubscriptionRequest::create([
                'driver_id'         => $this->driver->id,
                'parent_id'         => $parentUser->id,
                'status'            => SubscriptionRequest::STATUS_REJECTED,
                'subscription_type' => 'both',
                'trip_direction'    => 'both',
                'responded_at'      => Carbon::now()->subDays(1),
                'updated_at'        => Carbon::now()->subDays(1),
            ]);
        }

        // إنشاء طلب جديد معلق
        $pendingReq = SubscriptionRequest::create([
            'driver_id'         => $this->driver->id,
            'parent_id'         => $parentUser->id,
            'status'            => SubscriptionRequest::STATUS_PENDING,
            'subscription_type' => 'both',
            'trip_direction'    => 'both',
        ]);

        // استدعاء الخدمة لرفض الطلب (تصل الآن إلى 3 رفض)
        $subService = app(\App\Services\Shared\SubscriptionRequestService::class);
        $subService->updateStatus($pendingReq, SubscriptionRequest::STATUS_REJECTED, 'غير متفرغ');

        // التحقق من إنشاء AdminAlert
        $this->assertDatabaseHas('admin_alerts', [
            'driver_id'  => $this->driver->id,
            'alert_type' => 'driver_inactivity_rejections',
        ]);

        // التحقق من إشعار السائق
        $driverNotif = $this->driverUser->notifications()->latest()->first();
        $this->assertNotNull($driverNotif);
        $this->assertEquals(NotificationFormatter::TYPE_DRIVER_INACTIVITY_REJECTION_ALERT, $driverNotif->data['type']);

        // التحقق من إشعار الإدارة
        $adminNotif = $this->adminUser->notifications()->where('data->type', NotificationFormatter::TYPE_DRIVER_INACTIVITY_REJECTION_ADMIN)->latest()->first();
        $this->assertNotNull($adminNotif);
        $this->assertEquals(NotificationFormatter::TYPE_DRIVER_INACTIVITY_REJECTION_ADMIN, $adminNotif->data['type']);
    }
}
