<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Parent\ParentModel;
use App\Models\Shared\Complaint;
use App\Models\Shared\DriverReview;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Trip;

/**
 * اختبار وحدة "الشكاوى وتقييمات السائقين" (Admin + Parent) بعد إصلاح:
 * 1) ComplaintResource كان لا يعرض submitted_by (اسم ولي الأمر) رغم توفر البيانات.
 * 2) allReviews()/index() كانا يرجعان شكلين مختلفين لنفس البيانات — تم توحيدهما.
 * 3) DELETE /api/parent/driver-reviews/{id} كان بلا أي تحقق ملكية — أي ولي أمر
 *    يقدر يحذف تقييم أي ولي أمر آخر نهائياً.
 * 4) StoreDriverReviewRequest كان يتحقق من عدم تكرار التقييم بمقارنة auth()->id()
 *    (users.id) بعمود parent_id الذي يخزّن فعلياً parents.id — فلا يكتشف التكرار أبداً.
 * 5) App\Services\Parent\ComplaintService::updateComplaint() كان يستخدم Exception
 *    بدون استيرادها فتنهار الدالة بخطأ فادح بدل استجابة JSON نظيفة.
 *
 * ملاحظة تحقّق: driver_reviews.parent_id و complaints.submitted_by كلاهما
 * مفتاح أجنبي فعلي على parents.id (وليس users.id) — تم التأكد من هذا مباشرة
 * من information_schema.KEY_COLUMN_USAGE على قاعدة بيانات الاختبار قبل كتابة
 * أي إصلاح، تفادياً للاعتماد على ملفات الهجرة وحدها (قد تُعدَّل لاحقاً بهجرات أخرى).
 */
class ComplaintsAndDriverReviewsTest extends TestCase
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

        // ملاحظة (2026-09-14): بعد تطبيع V2 لم يعد admins/parents جدولين منفصلين
        // (ParentModel/Admin أصبحا Proxy فوق users مفلتَرين بالدور)، وجدول roles
        // الحقيقي مزروع مسبقاً بأدوار فعلية: 1=super_admin (kind=staff)،
        // 7=parent، 8=driver (kind=account). id=1/2/3 القديمة كانت تصادف أدوار
        // staff أخرى فعلية (fleet_supervisor إلخ) دون أن يظهر ذلك كخطأ بسبب
        // insertOrIgnore، والإدراج المباشر في جدول admins كان يفشل لعدم وجوده.
        DB::table('roles')->insertOrIgnore([
            ['id' => 1, 'name' => 'super_admin', 'display_name' => 'مدير النظام العام', 'kind' => 'staff', 'is_super' => 1],
            ['id' => 7, 'name' => 'parent',      'display_name' => 'ولي أمر',            'kind' => 'account', 'is_super' => 0],
            ['id' => 8, 'name' => 'driver',      'display_name' => 'سائق حافلة/فان',      'kind' => 'account', 'is_super' => 0],
        ]);

        $this->adminUser = User::create([
            'full_name'    => 'مدير الشكاوى',
            'email'        => 'admin.cx.' . uniqid() . '@darby.test',
            'phone_number' => '090' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'      => 1,
            'is_active'    => 1,
        ]);

        $this->driverUser = User::create([
            'full_name'    => 'سائق الشكاوى',
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
            'full_name'    => 'ولي أمر الشكاوى',
            'email'        => 'parent.cx.' . uniqid() . '@darby.test',
            'phone_number' => '092' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'      => 7,
            'is_active'    => 1,
            'is_trusted'   => 1,
        ]);

        $this->parent = ParentModel::findOrFail($this->parentUser->id);
    }

    /**
     * ينشئ اشتراكاً "نشطاً"/"مكتملاً" فعلياً بين ولي الأمر والسائق — مطلوب
     * كبوابة إلزامية قبل السماح بإضافة تقييم/تعليق (راجع DriverReviewsTest).
     */
    protected function makeActiveSubscription(User $parentUser, Driver $driver, string $status = 'active'): ActiveSubscription
    {
        $request = SubscriptionRequest::create([
            'parent_id' => $parentUser->id,
            'driver_id' => $driver->id,
            'status'    => SubscriptionRequest::STATUS_ACCEPTED,
        ]);

        return ActiveSubscription::create([
            'subscription_request_id' => $request->id,
            'status'                  => $status,
        ]);
    }

    // =========================================================
    // Driver Reviews
    // =========================================================

    public function test_parent_can_submit_driver_review(): void
    {
        $this->makeActiveSubscription($this->parentUser, $this->driver, 'active');

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
            'full_name'    => 'ولي أمر آخر للشكاوى',
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

    // =========================================================
    // Parent — تقديم شكوى (عامة ومتعلقة برحلة)
    // =========================================================

    /**
     * تقديم الشكوى يتطلب اشتراكاً سابقاً أو حالياً بين ولي الأمر والسائق
     * (ComplaintService::createComplaint) — نُنشئ طلب اشتراك بأي حالة "فعالة"
     * لإثبات وجود العلاقة بينهما.
     */
    protected function giveParentSubscriptionWithDriver(string $status = SubscriptionRequest::STATUS_ACCEPTED): SubscriptionRequest
    {
        return SubscriptionRequest::create([
            'parent_id' => $this->parentUser->id,
            'driver_id' => $this->driver->id,
            'status'    => $status,
        ]);
    }

    public function test_parent_can_submit_general_complaint_against_driver(): void
    {
        $this->giveParentSubscriptionWithDriver();

        $response = $this->actingAs($this->parentUser)->postJson('/api/parent/complaints', [
            'driver_id'   => $this->driver->id,
            'description' => 'السائق يتحدث بطريقة غير لائقة مع الأطفال داخل الحافلة.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.driver.id', $this->driver->id);

        $this->assertDatabaseHas('complaints', [
            'submitted_by' => $this->parentUser->id,
            'driver_id'    => $this->driver->id,
            'trip_id'      => null,
            'status'       => 'pending',
        ]);
    }

    public function test_parent_can_submit_trip_related_complaint_against_driver(): void
    {
        $this->giveParentSubscriptionWithDriver();

        $trip = Trip::create(['driver_id' => $this->driver->id]);

        $response = $this->actingAs($this->parentUser)->postJson('/api/parent/complaints', [
            'driver_id'   => $this->driver->id,
            'trip_id'     => $trip->id,
            'description' => 'السائق تجاوز الوقت المحدد لإيصال الأطفال في هذه الرحلة تحديداً.',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('complaints', [
            'submitted_by' => $this->parentUser->id,
            'driver_id'    => $this->driver->id,
            'trip_id'      => $trip->id,
        ]);
    }

    public function test_parent_cannot_submit_complaint_without_subscription_with_driver(): void
    {
        // لا يوجد أي طلب اشتراك يربط ولي الأمر بهذا السائق هنا عمداً.
        $response = $this->actingAs($this->parentUser)->postJson('/api/parent/complaints', [
            'driver_id'   => $this->driver->id,
            'description' => 'شكوى بلا أي علاقة اشتراك سابقة مع هذا السائق.',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
        $this->assertDatabaseMissing('complaints', [
            'submitted_by' => $this->parentUser->id,
            'driver_id'    => $this->driver->id,
        ]);
    }

    public function test_parent_cannot_submit_trip_complaint_for_trip_belonging_to_another_driver(): void
    {
        $this->giveParentSubscriptionWithDriver();

        $otherDriverUser = User::create([
            'full_name'    => 'سائق آخر للشكاوى',
            'email'        => 'other.driver.cx.' . uniqid() . '@darby.test',
            'phone_number' => '095' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'      => 8,
            'is_active'    => 1,
        ]);
        $otherDriver = Driver::create([
            'user_id'        => $otherDriverUser->id,
            'national_id'    => 'NAT' . rand(100000, 999999),
            'license_number' => 'LIC' . rand(100000, 999999),
            'license_expiry' => now()->addYears(2)->format('Y-m-d'),
            'status'         => 'Approved',
        ]);
        $tripForOtherDriver = Trip::create(['driver_id' => $otherDriver->id]);

        $response = $this->actingAs($this->parentUser)->postJson('/api/parent/complaints', [
            'driver_id'   => $this->driver->id,
            'trip_id'     => $tripForOtherDriver->id,
            'description' => 'محاولة ربط شكوى برحلة لا تخص السائق المشتكى عليه.',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    // =========================================================
    // Admin — Complaints
    // =========================================================

    protected function makeComplaint(string $status = 'pending'): Complaint
    {
        return Complaint::create([
            'submitted_by'   => $this->parent->id,
            'against_type'   => 'DRIVER',
            'against_id'     => $this->driver->id,
            'driver_id'      => $this->driver->id,
            'description'    => 'السائق تأخر أكثر من 30 دقيقة عن الموعد المحدد دون إشعار مسبق.',
            'status'         => $status,
        ]);
    }

    public function test_admin_complaints_index_returns_paginated_list_with_submitted_by(): void
    {
        $this->makeComplaint();

        $response = $this->actingAs($this->adminUser)->getJson('/api/admin/complaints');

        $response->assertStatus(200);
        $response->assertJsonPath('status', true);
        $response->assertJsonStructure([
            'status',
            'data' => [['id', 'description', 'status', 'action_taken', 'action_details', 'driver', 'submitted_by']],
            'pagination' => ['current_page', 'last_page', 'total', 'per_page'],
        ]);

        $item = collect($response->json('data'))->first();
        $this->assertEquals($this->parentUser->full_name, $item['submitted_by']['name']);
        $this->assertEquals($this->driverUser->full_name, $item['driver']['name']);
    }

    public function test_admin_complaints_index_filters_by_status_and_driver_id(): void
    {
        $pending = $this->makeComplaint('pending');
        $resolved = $this->makeComplaint('completed');

        $byStatus = $this->actingAs($this->adminUser)->getJson('/api/admin/complaints?status=pending');
        $byStatus->assertStatus(200);
        $ids = collect($byStatus->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($pending->id));
        $this->assertFalse($ids->contains($resolved->id));

        $byDriver = $this->actingAs($this->adminUser)->getJson('/api/admin/complaints?driver_id=' . $this->driver->id);
        $byDriver->assertStatus(200);
        $this->assertGreaterThanOrEqual(2, count($byDriver->json('data')));
    }

    public function test_admin_complaint_detail_returns_full_context(): void
    {
        $complaint = $this->makeComplaint();

        $response = $this->actingAs($this->adminUser)->getJson("/api/admin/complaints/{$complaint->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $complaint->id);
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.submitted_by.name', $this->parentUser->full_name);
        $response->assertJsonPath('data.driver.name', $this->driverUser->full_name);
    }

    public function test_admin_driver_complaints_lists_only_that_driver(): void
    {
        $complaint = $this->makeComplaint();

        $response = $this->actingAs($this->adminUser)
            ->getJson("/api/admin/complaints/driver/{$this->driver->id}");

        $response->assertStatus(200);
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($complaint->id));
    }

    public function test_admin_can_review_complaint_with_warning_action(): void
    {
        $complaint = $this->makeComplaint();

        $response = $this->actingAs($this->adminUser)->postJson("/api/admin/complaints/{$complaint->id}/review", [
            'action'         => 'warning',
            'action_details' => 'تم توجيه إنذار كتابي للسائق.',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'completed');
        $response->assertJsonPath('data.action_taken', 'warning');
        $this->assertDatabaseHas('complaints', ['id' => $complaint->id, 'status' => 'completed', 'action_taken' => 'warning']);
    }

    public function test_admin_review_with_suspension_suspends_driver_immediately(): void
    {
        $complaint = $this->makeComplaint();

        $this->actingAs($this->adminUser)->postJson("/api/admin/complaints/{$complaint->id}/review", [
            'action' => 'suspension',
        ])->assertStatus(200);

        $this->assertDatabaseHas('drivers', ['id' => $this->driver->id, 'status' => 'Suspended']);
    }

    public function test_admin_review_with_invalid_action_returns_422(): void
    {
        $complaint = $this->makeComplaint();

        $response = $this->actingAs($this->adminUser)->postJson("/api/admin/complaints/{$complaint->id}/review", [
            'action' => 'invalid',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['action']);
    }

    public function test_admin_review_nonexistent_complaint_returns_404(): void
    {
        $response = $this->actingAs($this->adminUser)->postJson('/api/admin/complaints/999999/review', [
            'action' => 'dismiss',
        ]);

        $response->assertStatus(404);
    }

    public function test_admin_cannot_review_already_processed_complaint_again(): void
    {
        $complaint = $this->makeComplaint();

        $this->actingAs($this->adminUser)->postJson("/api/admin/complaints/{$complaint->id}/review", [
            'action' => 'dismiss',
        ])->assertStatus(200);

        $response = $this->actingAs($this->adminUser)->postJson("/api/admin/complaints/{$complaint->id}/review", [
            'action' => 'warning',
        ]);

        $response->assertStatus(422);
    }

    // =========================================================
    // إصلاح: تعديل شكوى في حالة محظورة كان يسبب Fatal Error بدل استجابة نظيفة
    // =========================================================

    public function test_parent_updating_a_blocked_complaint_returns_clean_error_not_crash(): void
    {
        $complaint = $this->makeComplaint();
        $complaint->update(['status' => 'in_progress']);

        $response = $this->actingAs($this->parentUser)->postJson("/api/parent/complaints/{$complaint->id}", [
            'description' => 'محاولة تعديل بعد بدء المعالجة.',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }
}
