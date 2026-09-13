<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Parent\ParentModel;
use App\Models\Shared\DriverReview;
use App\Models\Shared\SubscriptionRequest;

/**
 * اختبار وحدة "تقييمات السائقين" (Admin + Parent) بعد إصلاح:
 * 1) allReviews()/index() كانا يرجعان شكلين مختلفين لنفس البيانات — تم توحيدهما.
 * 2) DELETE /api/parent/driver-reviews/{id} كان بلا أي تحقق ملكية — أي ولي أمر
 *    يقدر يحذف تقييم أي ولي أمر آخر نهائياً.
 * 3) StoreDriverReviewRequest كان يتحقق من عدم تكرار التقييم بمقارنة auth()->id()
 *    (users.id) بعمود parent_id الذي يخزّن فعلياً parents.id — فلا يكتشف التكرار أبداً.
 *
 * ملاحظة: كانت هذه الحالات ضمن ComplaintsAndDriverReviewsTest قبل إزالة منطق
 * الشكاوى بالكامل من الكود الحي (جدول complaints بقي في القاعدة كأرشيف صامت
 * بدون أي Route أو Model يصل إليه).
 *
 * إصلاح إضافي (2026-09-14): الإعداد كان يستخدم role_id مُختلَقة (1/2/3 بأسماء
 * Admin/Driver/Parent) ويُدرج مباشرة في جدول admins القديم. بعد تطبيع V2،
 * admins/parents لم يعودا جدولين منفصلين (ParentModel/Admin أصبحا Proxy فوق
 * users)، وجدول roles الحقيقي مزروع مسبقاً بأدوار فعلية (1=super_admin كـ
 * staff، 7=parent، 8=driver كـ account). استخدام 1/2/3 كان يصادف أدوار staff
 * أخرى (fleet_supervisor إلخ) بسبب insertOrIgnore (لا يكتب فوق الصفوف
 * الموجودة فعلاً)، والإدراج في admins كان يفشل لعدم وجود الجدول أصلاً.
 */
class DriverReviewsTest extends TestCase
{
    use DatabaseTransactions;

    protected User $adminUser;
    protected User $driverUser;
    protected Driver $driver;
    protected User $parentUser;
    protected ParentModel $parent;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('roles')->insertOrIgnore([
            ['id' => 1, 'name' => 'super_admin', 'display_name' => 'مدير النظام العام', 'kind' => 'staff', 'is_super' => 1],
            ['id' => 7, 'name' => 'parent',      'display_name' => 'ولي أمر',            'kind' => 'account', 'is_super' => 0],
            ['id' => 8, 'name' => 'driver',      'display_name' => 'سائق حافلة/فان',      'kind' => 'account', 'is_super' => 0],
        ]);

        $this->adminUser = User::create([
            'full_name'    => 'مدير المراجعات',
            'email'        => 'admin.cx.' . uniqid() . '@darby.test',
            'phone_number' => '090' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'      => 1,
            'is_active'    => 1,
        ]);

        $this->driverUser = User::create([
            'full_name'    => 'سائق المراجعات',
            'email'        => 'driver.cx.' . uniqid() . '@darby.test',
            'phone_number' => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'      => 8,
            'is_active'    => 1,
        ]);
        $this->driver = Driver::create([
            'user_id'        => $this->driverUser->id,
            'national_id'    => 'NAT' . rand(100000, 999999),
            'license_number' => 'LIC' . rand(100000, 999999),
            'license_expiry' => now()->addYears(2)->format('Y-m-d'),
            'status'         => 'Approved',
        ]);

        $this->parentUser = User::create([
            'full_name'    => 'ولي أمر المراجعات',
            'email'        => 'parent.cx.' . uniqid() . '@darby.test',
            'phone_number' => '092' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'      => 7,
            'is_active'    => 1,
            'is_trusted'   => 1,
        ]);

        $this->parent = ParentModel::findOrFail($this->parentUser->id);
    }

    // =========================================================
    // Driver Reviews
    // =========================================================

    public function test_parent_can_submit_driver_review(): void
    {
        $response = $this->actingAs($this->parentUser)->postJson('/api/parent/driver-reviews', [
            'driver_id' => $this->driver->id,
            'rating'    => 5,
            'comment'   => 'سائق ممتاز وملتزم بالمواعيد.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', true);
        $response->assertJsonPath('data.rating', 5);
        $response->assertJsonPath('data.driver.id', $this->driver->id);

        $this->assertDatabaseHas('driver_reviews', [
            'driver_id' => $this->driver->id,
            'parent_id' => $this->parentUser->id,
        ]);
    }

    public function test_parent_cannot_submit_duplicate_review_for_same_driver(): void
    {
        DriverReview::create([
            'parent_id' => $this->parentUser->id,
            'driver_id' => $this->driver->id,
            'rating'    => 4,
            'status'    => 'active',
        ]);

        $response = $this->actingAs($this->parentUser)->postJson('/api/parent/driver-reviews', [
            'driver_id' => $this->driver->id,
            'rating'    => 5,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['driver_id']);
    }

    /**
     * ولي الأمر الذي لديه اشتراك مع السائق (بأي حالة) يقدر يترك أكثر من
     * تعليق/تقييم عادي — لا يُطبّق عليه قيد "تعليق واحد فقط".
     */
    public function test_parent_with_subscription_can_submit_more_than_one_review_for_same_driver(): void
    {
        SubscriptionRequest::create([
            'parent_id' => $this->parentUser->id,
            'driver_id' => $this->driver->id,
            'status'    => SubscriptionRequest::STATUS_ACCEPTED,
        ]);

        DriverReview::create([
            'parent_id' => $this->parentUser->id,
            'driver_id' => $this->driver->id,
            'rating'    => 4,
            'comment'   => 'تعليق أول',
            'status'    => 'active',
        ]);

        $response = $this->actingAs($this->parentUser)->postJson('/api/parent/driver-reviews', [
            'driver_id' => $this->driver->id,
            'rating'    => 5,
            'comment'   => 'تعليق ثانٍ لنفس السائق بعد وجود اشتراك بيننا.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', true);

        $this->assertEquals(2, DriverReview::where('parent_id', $this->parentUser->id)
            ->where('driver_id', $this->driver->id)
            ->count());
    }

    /**
     * ولي الأمر بدون أي اشتراك مع السائق يبقى مقيّداً بتعليق واحد فقط
     * (يغطي نفس سلوك test_parent_cannot_submit_duplicate_review_for_same_driver
     * لكن بعد إضافة استثناء الاشتراك، للتأكد من عدم كسر القيد الأصلي).
     */
    public function test_parent_without_subscription_still_limited_to_one_review(): void
    {
        DriverReview::create([
            'parent_id' => $this->parentUser->id,
            'driver_id' => $this->driver->id,
            'rating'    => 4,
            'status'    => 'active',
        ]);

        $response = $this->actingAs($this->parentUser)->postJson('/api/parent/driver-reviews', [
            'driver_id' => $this->driver->id,
            'rating'    => 5,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['driver_id']);
    }

    // =========================================================
    // SubscriptionRequest::existsForParentAndDriver
    // =========================================================

    /**
     * يجب أن ترجع true إن وُجد صف في جدول الاشتراكات (requests) يربط ولي
     * الأمر بالسائق، بغض النظر عن حالة الاشتراك: نشط (accepted)، ملغي
     * (cancelled)، قيد الانتظار (pending)، أو مكتمل (contract_offered).
     */
    public function test_exists_for_parent_and_driver_returns_true_regardless_of_status(): void
    {
        foreach ([
            SubscriptionRequest::STATUS_PENDING,
            SubscriptionRequest::STATUS_ACCEPTED,
            SubscriptionRequest::STATUS_CANCELLED,
            'contract_offered',
        ] as $status) {
            $driverUser = User::create([
                'full_name'    => 'سائق اختبار ' . $status,
                'email'        => 'driver.status.' . uniqid() . '@darby.test',
                'phone_number' => '094' . rand(1000000, 9999999),
                'password_hash' => bcrypt('password123'),
                'role_id'      => 8,
                'is_active'    => 1,
            ]);
            $driver = Driver::create([
                'user_id'        => $driverUser->id,
                'national_id'    => 'NAT' . rand(100000, 999999),
                'license_number' => 'LIC' . rand(100000, 999999),
                'license_expiry' => now()->addYears(2)->format('Y-m-d'),
                'status'         => 'Approved',
            ]);

            SubscriptionRequest::create([
                'parent_id' => $this->parentUser->id,
                'driver_id' => $driver->id,
                'status'    => $status,
            ]);

            $this->assertTrue(
                SubscriptionRequest::existsForParentAndDriver($this->parentUser->id, $driver->id),
                "expected true for status [{$status}]"
            );
        }
    }

    public function test_exists_for_parent_and_driver_returns_false_when_no_subscription(): void
    {
        $this->assertFalse(
            SubscriptionRequest::existsForParentAndDriver($this->parentUser->id, $this->driver->id)
        );
    }

    public function test_parent_can_update_own_review(): void
    {
        $review = DriverReview::create([
            'parent_id' => $this->parentUser->id,
            'driver_id' => $this->driver->id,
            'rating'    => 3,
            'comment'   => 'مقبول',
            'status'    => 'active',
        ]);

        $response = $this->actingAs($this->parentUser)
            ->putJson("/api/parent/driver-reviews/{$review->id}", ['rating' => 4, 'comment' => 'تحسّن الأداء']);

        $response->assertStatus(200);
        $response->assertJsonPath('data.rating', 4);
    }

    public function test_parent_can_delete_own_review(): void
    {
        $review = DriverReview::create([
            'parent_id' => $this->parentUser->id,
            'driver_id' => $this->driver->id,
            'rating'    => 2,
            'status'    => 'active',
        ]);

        $response = $this->actingAs($this->parentUser)->deleteJson("/api/parent/driver-reviews/{$review->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('driver_reviews', ['id' => $review->id]);
    }

    public function test_parent_cannot_delete_another_parents_review(): void
    {
        $otherParentUser = User::create([
            'full_name'    => 'ولي أمر آخر للمراجعات',
            'email'        => 'other.cx.' . uniqid() . '@darby.test',
            'phone_number' => '093' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'      => 7,
            'is_active'    => 1,
        ]);
        $otherParent = ParentModel::findOrFail($otherParentUser->id);

        $review = DriverReview::create([
            'parent_id' => $otherParentUser->id,
            'driver_id' => $this->driver->id,
            'rating'    => 5,
            'status'    => 'active',
        ]);

        $response = $this->actingAs($this->parentUser)->deleteJson("/api/parent/driver-reviews/{$review->id}");

        $response->assertStatus(404);
        $this->assertDatabaseHas('driver_reviews', ['id' => $review->id]);
    }

    // =========================================================
    // Admin — Driver Reviews: شكل استجابة موحّد بين all و driver/{id}
    // =========================================================

    public function test_admin_all_reviews_returns_unified_shape_with_driver_and_parent_names(): void
    {
        DriverReview::create([
            'parent_id' => $this->parentUser->id,
            'driver_id' => $this->driver->id,
            'rating'    => 5,
            'comment'   => 'ممتاز',
            'status'    => 'active',
        ]);

        $response = $this->actingAs($this->adminUser)->getJson('/api/admin/driver-reviews/all');

        $response->assertStatus(200);
        $response->assertJsonPath('status', true);
        $response->assertJsonStructure([
            'status', 'data' => [['id', 'driver_id', 'rating', 'comment', 'created_at', 'parent', 'driver']],
            'pagination' => ['current_page', 'last_page', 'total', 'per_page'],
        ]);

        $item = collect($response->json('data'))->firstWhere('driver_id', $this->driver->id);
        $this->assertEquals($this->parentUser->full_name, $item['parent']['full_name']);
        $this->assertEquals($this->driverUser->full_name, $item['driver']['name']);
    }

    public function test_admin_driver_reviews_by_driver_matches_same_shape(): void
    {
        DriverReview::create([
            'parent_id' => $this->parentUser->id,
            'driver_id' => $this->driver->id,
            'rating'    => 4,
            'status'    => 'active',
        ]);

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/admin/driver-reviews/driver/{$this->driver->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure(['status', 'data' => [['id', 'driver_id', 'rating', 'parent', 'driver']]]);
        $response->assertJsonPath('data.0.driver.name', $this->driverUser->full_name);
    }

    public function test_admin_can_force_delete_a_review(): void
    {
        $review = DriverReview::create([
            'parent_id' => $this->parentUser->id,
            'driver_id' => $this->driver->id,
            'rating'    => 1,
            'status'    => 'active',
        ]);

        $response = $this->actingAs($this->adminUser)->deleteJson("/api/admin/driver-reviews/{$review->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('driver_reviews', ['id' => $review->id]);
    }
}
