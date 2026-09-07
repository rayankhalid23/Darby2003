<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Parent\ParentModel;
use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Route as RouteModel;
use App\Models\Shared\RouteStop;
use App\Services\Shared\SubscriptionRequestService;

/**
 * اختبار الحالات الحدّية لإضافة اشتراك جديد إلى مسار السائق
 * (MasterRouteStopSyncService::syncOnAcceptance + addChildStopsToRoute + resequenceRoute).
 *
 * كل اختبار هنا يثبّت سلوكاً مطلوباً بعد إصلاح أخطاء المزامنة الستة.
 */
class MasterRouteStopSyncEdgeCasesTest extends TestCase
{
    use DatabaseTransactions;

    protected Driver $driver;
    protected ParentModel $parent;
    protected User $parentUser;
    protected School $school;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('roles')->insertOrIgnore([
            ['id' => 2, 'name' => 'Driver', 'display_name' => 'سائق'],
            ['id' => 3, 'name' => 'Parent', 'display_name' => 'ولي أمر'],
        ]);

        $driverUser = User::create([
            'full_name'     => 'سائق حالات حدية',
            'email'         => 'driver.edge.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 2,
            'is_active'     => 1,
        ]);

        $this->driver = Driver::create([
            'user_id'        => $driverUser->id,
            'national_id'    => 'NAT' . rand(100000, 999999),
            'license_number' => 'LIC' . rand(100000, 999999),
            'license_expiry' => now()->addYears(2)->format('Y-m-d'),
            'status'         => 'Approved',
            'current_lat'    => 32.8872,
            'current_lng'    => 13.1932,
        ]);

        DB::table('vehicles')->insert([
            'driver_id'       => $this->driver->id,
            'brand'           => 'تويوتا',
            'model'           => 'هايس',
            'year'            => 2022,
            'color'           => 'أبيض',
            'plate_number'    => 'EDGE-' . rand(1000, 9999),
            'capacity_manual' => 14,
            'status'          => 'Active',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->parentUser = User::create([
            'full_name'     => 'ولي أمر حالات حدية',
            'email'         => 'parent.edge.' . uniqid() . '@darby.test',
            'phone_number'  => '092' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 3,
            'is_active'     => 1,
        ]);

        // ParentModel هو Proxy فوق جدول users في التطبيع V2، فالمعرّف نفسه معرّف المستخدم
        $this->parent = ParentModel::findOrFail($this->parentUser->id);
        $this->parent->deposit(5000000);

        $this->school = School::create([
            'name'    => 'مدرسة الحالات الحدية',
            'address' => 'شارع الاختبار',
            'lat'     => 32.9000,
            'lng'     => 13.2000,
            'status'  => 'Approved',
        ]);
    }

    private function makeChild(string $label): Child
    {
        return Child::create([
            'parent_id'           => $this->parent->id,
            'school_id'           => $this->school->id,
            'full_name'           => 'طفل ' . $label,
            'birth_date'          => '2018-05-10',
            'gender'              => 'male',
            'grade'               => 1,
            'notification_radius' => 500,
        ]);
    }

    /**
     * ينشئ طلب اشتراك معلّق لطفل محدد مع إمكانية التحكم في الاتجاه/الفترة/الإحداثيات/التواريخ.
     */
    private function makeRequest(
        Child $child,
        string $direction = 'go',
        string $timing = 'MORNING',
        ?float $homeLat = 32.8800,
        ?float $homeLng = 13.1900,
        ?string $startDate = null,
        ?string $endDate = null
    ): SubscriptionRequest {
        $request = SubscriptionRequest::create([
            'parent_id'                   => $this->parent->id,
            'driver_id'                   => $this->driver->id,
            'status'                      => SubscriptionRequest::STATUS_PENDING,
            'total_price'                 => 200.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 200.00,
            'children_count'              => 1,
        ]);

        DB::table('request_children')->insert([
            'request_id'                  => $request->id,
            'child_id'                    => $child->id,
            'subscription_type'           => 'multi_day',
            'trip_direction'              => $direction,
            'timing'                      => $timing,
            'start_date'                  => $startDate ?? now()->addDay()->format('Y-m-d'),
            'end_date'                    => $endDate ?? now()->addMonths(1)->format('Y-m-d'),
            'working_days_count'          => 22,
            'distance_km'                 => 5.0,
            'trip_price'                  => 200.00,
            'price_per_child'             => 200.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 200.00,
            'driver_net_price'            => 184.00,
            'home_lat'                    => $homeLat,
            'home_lng'                    => $homeLng,
            'home_label'                  => 'منزل الاختبار',
            'school_lat'                  => 32.9000,
            'school_lng'                  => 13.2000,
            'school_label'                => $this->school->name,
            'created_at'                  => now(),
            'updated_at'                  => now(),
        ]);

        return $request;
    }

    // =====================================================================
    // 1) المسار السعيد: أول اشتراك ينشئ المسار ومحطتَي المنزل والمدرسة بترتيب صحيح
    // =====================================================================
    public function test_first_accepted_subscription_builds_route_with_ordered_stops(): void
    {
        $child = $this->makeChild('أ');
        $request = $this->makeRequest($child, 'go', 'MORNING');

        app(SubscriptionRequestService::class)->updateStatus($request, 'accepted');

        $route = RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_go')->first();
        $this->assertNotNull($route, 'لم يُنشأ مسار morning_go.');

        $stops = RouteStop::where('route_id', $route->id)->orderBy('sequence_order')->get();
        $this->assertCount(2, $stops, 'يجب أن تُنشأ محطتان: منزل + مدرسة.');

        // اتجاه ذهاب: المنازل أولاً ثم المدرسة أخيراً
        $this->assertSame('home', $stops[0]->stop_type);
        $this->assertSame('school', $stops[1]->stop_type);
        $this->assertSame(1, $stops[0]->sequence_order);
        $this->assertSame(2, $stops[1]->sequence_order);

        $this->assertGreaterThan(0, (float) $route->fresh()->total_distance, 'لم تُحتسب مسافة المسار.');
    }

    // =====================================================================
    // 2) اتجاه إياب: المدرسة يجب أن تكون أول محطة والمنازل بعدها
    // =====================================================================
    public function test_return_direction_orders_school_first(): void
    {
        $child = $this->makeChild('ب');
        $request = $this->makeRequest($child, 'return', 'MORNING');

        app(SubscriptionRequestService::class)->updateStatus($request, 'accepted');

        $route = RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_return')->first();
        $this->assertNotNull($route);

        $stops = RouteStop::where('route_id', $route->id)->orderBy('sequence_order')->get();
        $this->assertSame('school', $stops[0]->stop_type, 'في الإياب يجب أن تكون المدرسة المحطة الأولى.');
        $this->assertSame('home', $stops[1]->stop_type);
    }

    // =====================================================================
    // 3) مسار الإياب ينطلق من وقت الانصراف، لا من وقت الاصطحاب الصباحي
    // =====================================================================
    public function test_return_route_uses_dropoff_time_not_go_start_time(): void
    {
        $child = $this->makeChild('ج');
        $request = $this->makeRequest($child, 'both', 'MORNING');
        $request->update(['pickup_time' => '07:00:00', 'dropoff_time' => '14:00:00']);

        app(SubscriptionRequestService::class)->updateStatus($request->fresh(), 'accepted');

        $goRoute     = RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_go')->first();
        $returnRoute = RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_return')->first();

        $this->assertNotNull($goRoute);
        $this->assertNotNull($returnRoute);

        $this->assertSame('07:00:00', (string) $goRoute->start_time, 'مسار الذهاب يجب أن ينطلق وقت الاصطحاب.');
        $this->assertSame('14:00:00', (string) $returnRoute->start_time, 'مسار الإياب يجب أن ينطلق وقت الانصراف.');
        $this->assertNotSame((string) $goRoute->start_time, (string) $returnRoute->start_time);
    }

    // =====================================================================
    // 3-ب) طلب اتجاهه "إياب فقط": المسار الأساسي نفسه يجب أن يأخذ وقت الانصراف
    // =====================================================================
    public function test_return_only_primary_route_uses_dropoff_time(): void
    {
        $child = $this->makeChild('ج2');
        $request = $this->makeRequest($child, 'return', 'MORNING');
        $request->update(['pickup_time' => '07:00:00', 'dropoff_time' => '13:30:00']);

        app(SubscriptionRequestService::class)->updateStatus($request->fresh(), 'accepted');

        $returnRoute = RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_return')->first();

        $this->assertNotNull($returnRoute);
        $this->assertSame('13:30:00', (string) $returnRoute->start_time);
    }

    // =====================================================================
    // 4) إحداثيات محطة المنزل تُحدَّث عند اشتراك جديد بموقع مختلف
    // =====================================================================
    public function test_home_coordinates_are_refreshed_on_resubscription(): void
    {
        $service = app(SubscriptionRequestService::class);
        $child = $this->makeChild('د');

        // الاشتراك الأول بموقع منزل أ
        $req1 = $this->makeRequest($child, 'go', 'MORNING', 32.8800, 13.1900);
        $service->updateStatus($req1, 'accepted');

        $route = RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_go')->first();
        $stopBefore = RouteStop::where('route_id', $route->id)->where('child_id', $child->id)->first();
        $this->assertEqualsWithDelta(32.8800, (float) $stopBefore->lat, 0.0001);

        // اشتراك ثانٍ لنفس الطفل ونفس السائق بموقع منزل مختلف تماماً (ب)
        $req2 = $this->makeRequest(
            $child, 'go', 'MORNING', 32.9500, 13.3000,
            now()->addMonths(2)->format('Y-m-d'),
            now()->addMonths(3)->format('Y-m-d')
        );
        $service->updateStatus($req2, 'accepted');

        $stopAfter = RouteStop::where('route_id', $route->id)->where('child_id', $child->id)->first();

        $this->assertEqualsWithDelta(
            32.9500,
            (float) $stopAfter->lat,
            0.0001,
            'يجب تحديث إحداثيات محطة المنزل بموقع الاشتراك الجديد.'
        );
        $this->assertEqualsWithDelta(13.3000, (float) $stopAfter->lng, 0.0001);

        // ولا تتضاعف المحطة
        $this->assertSame(1, RouteStop::where('route_id', $route->id)->where('child_id', $child->id)->count());
    }

    // =====================================================================
    // 5) إلغاء اشتراك لا يحذف محطة المنزل ما دام هناك اشتراك نشط آخر يحتاجها
    // =====================================================================
    public function test_cancelling_one_subscription_keeps_stop_needed_by_active_one(): void
    {
        $service = app(SubscriptionRequestService::class);
        $child = $this->makeChild('هـ');

        $req1 = $this->makeRequest($child, 'go', 'MORNING');
        $service->updateStatus($req1, 'accepted');

        $req2 = $this->makeRequest(
            $child, 'go', 'MORNING', 32.8800, 13.1900,
            now()->addMonths(2)->format('Y-m-d'),
            now()->addMonths(3)->format('Y-m-d')
        );
        $service->updateStatus($req2, 'accepted');

        $route = RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_go')->first();
        $this->assertSame(
            1,
            RouteStop::where('route_id', $route->id)->where('child_id', $child->id)->count(),
            'الاشتراكان يتقاسمان محطة منزل واحدة (firstOrCreate).'
        );

        $sub1 = ActiveSubscription::where('subscription_request_id', $req1->id)->first();
        $sub2 = ActiveSubscription::where('subscription_request_id', $req2->id)->first();
        $this->assertNotNull($sub1);
        $this->assertNotNull($sub2);

        // إلغاء الاشتراك الأول فقط
        $service->updateActiveSubscriptionStatus($sub1->id, 'cancelled');

        $this->assertSame('active', $sub2->fresh()->status, 'الاشتراك الثاني ما زال نشطاً.');

        $this->assertSame(
            1,
            RouteStop::where('route_id', $route->id)->where('child_id', $child->id)->count(),
            'يجب الإبقاء على محطة الطفل لوجود اشتراك نشط آخر له مع نفس السائق.'
        );

        // وعند إلغاء الاشتراك الأخير أيضاً تُحذف المحطة فعلاً
        $service->updateActiveSubscriptionStatus($sub2->id, 'cancelled');

        $this->assertSame(
            0,
            RouteStop::where('route_id', $route->id)->where('child_id', $child->id)->count(),
            'بعد إلغاء آخر اشتراك نشط يجب أن تُحذف محطة الطفل من المسار.'
        );
    }

    // =====================================================================
    // 6) فترة/اتجاه غير معروفين ⇒ يُرفض القبول ولا يُنشأ مسار ولا اشتراك
    // =====================================================================
    public function test_unresolvable_timing_is_rejected_instead_of_creating_dead_subscription(): void
    {
        $child = $this->makeChild('و');
        $request = $this->makeRequest($child, 'go', 'NIGHT');

        $routesBefore = RouteModel::where('driver_id', $this->driver->id)->count();
        $thrown = null;

        try {
            app(SubscriptionRequestService::class)->updateStatus($request, 'accepted');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'كان يجب رفض قبول طلب لا يمكن تحديد فترته/اتجاهه.');
        $this->assertStringContainsString('تعذّر تحديد فترة/اتجاه', $thrown->getMessage());

        $this->assertSame(
            $routesBefore,
            RouteModel::where('driver_id', $this->driver->id)->count(),
            'لا يجوز إنشاء مسار لطلب لا يمكن تحديد فترته.'
        );

        $this->assertDatabaseMissing('active_subscriptions', [
            'subscription_request_id' => $request->id,
        ]);

        $this->assertSame(
            SubscriptionRequest::STATUS_PENDING,
            $request->fresh()->status,
            'يجب أن يبقى الطلب معلقاً بعد رفض القبول (تراجع كامل عن المعاملة).'
        );
    }

    // =====================================================================
    // 7) غياب الإحداثيات ⇒ يُرفض القبول بدل إنشاء محطة بلا موقع
    // =====================================================================
    public function test_missing_coordinates_are_rejected_instead_of_creating_geoless_stop(): void
    {
        $child = $this->makeChild('ز');
        $request = $this->makeRequest($child, 'go', 'MORNING', null, null);

        $thrown = null;

        try {
            app(SubscriptionRequestService::class)->updateStatus($request, 'accepted');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, 'كان يجب رفض القبول عند غياب إحداثيات الاصطحاب.');
        $this->assertStringContainsString('إحداثيات', $thrown->getMessage());

        $this->assertSame(
            0,
            RouteStop::where('child_id', $child->id)->count(),
            'لا يجوز بقاء أي محطة لهذا الطفل بعد التراجع عن المعاملة.'
        );

        $this->assertDatabaseMissing('active_subscriptions', [
            'subscription_request_id' => $request->id,
        ]);
    }

    // =====================================================================
    // 8) اتجاه كل طفل يُحترم على حدة داخل الطلب الواحد
    // =====================================================================
    public function test_per_child_direction_is_respected_in_route_sync(): void
    {
        $childBoth = $this->makeChild('ح-ذهاب وإياب');
        $childGoOnly = $this->makeChild('ط-ذهاب فقط');

        $request = SubscriptionRequest::create([
            'parent_id'                   => $this->parent->id,
            'driver_id'                   => $this->driver->id,
            'status'                      => SubscriptionRequest::STATUS_PENDING,
            'total_price'                 => 400.00,
            'discount_amount'             => 0.00,
            'total_amount_after_discount' => 400.00,
            'children_count'              => 2,
        ]);

        foreach ([[$childBoth, 'both'], [$childGoOnly, 'go']] as [$child, $direction]) {
            DB::table('request_children')->insert([
                'request_id'                  => $request->id,
                'child_id'                    => $child->id,
                'subscription_type'           => 'multi_day',
                'trip_direction'              => $direction,
                'timing'                      => 'MORNING',
                'start_date'                  => now()->addDay()->format('Y-m-d'),
                'end_date'                    => now()->addMonths(1)->format('Y-m-d'),
                'working_days_count'          => 22,
                'distance_km'                 => 5.0,
                'trip_price'                  => 200.00,
                'price_per_child'             => 200.00,
                'discount_amount'             => 0.00,
                'total_amount_after_discount' => 200.00,
                'driver_net_price'            => 184.00,
                'home_lat'                    => 32.8800,
                'home_lng'                    => 13.1900,
                'home_label'                  => 'منزل الاختبار',
                'school_lat'                  => 32.9000,
                'school_lng'                  => 13.2000,
                'school_label'                => $this->school->name,
                'created_at'                  => now(),
                'updated_at'                  => now(),
            ]);
        }

        app(SubscriptionRequestService::class)->updateStatus($request, 'accepted');

        $goRoute     = RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_go')->first();
        $returnRoute = RouteModel::where('driver_id', $this->driver->id)->where('shift_slot', 'morning_return')->first();

        $this->assertNotNull($goRoute, 'يجب إنشاء مسار الذهاب (كلا الطفلين مشترك فيه).');
        $this->assertNotNull($returnRoute, 'يجب إنشاء مسار الإياب للطفل المشترك بالاتجاهين.');

        // الذهاب: الطفلان معاً
        $this->assertDatabaseHas('route_stops', ['route_id' => $goRoute->id, 'stop_type' => 'home', 'child_id' => $childBoth->id]);
        $this->assertDatabaseHas('route_stops', ['route_id' => $goRoute->id, 'stop_type' => 'home', 'child_id' => $childGoOnly->id]);

        // الإياب: الطفل المشترك بالاتجاهين فقط
        $this->assertDatabaseHas('route_stops', ['route_id' => $returnRoute->id, 'stop_type' => 'home', 'child_id' => $childBoth->id]);
        $this->assertDatabaseMissing('route_stops', [
            'route_id'  => $returnRoute->id,
            'stop_type' => 'home',
            'child_id'  => $childGoOnly->id,
        ]);
    }
}
