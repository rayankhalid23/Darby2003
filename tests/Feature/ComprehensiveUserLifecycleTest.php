<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\User;
use App\Models\Parent\ParentModel;
use App\Models\Parent\Address;
use App\Models\Parent\School;
use App\Models\Parent\Child;
use App\Models\Shared\Zone;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class ComprehensiveUserLifecycleTest extends TestCase
{
    // ⚠️ كان هذا الملف بلا أي سمة عزل قاعدة بيانات: كل مستخدم/طفل/عنوان ينشئه
    // كان يُكتب فعلياً ويبقى دائماً في قاعدة البيانات المتصلة (school_transport_db)
    // بدل أن يُلغى تلقائياً بعد كل اختبار. هذا ما تسبب في تراكم ~190 مستخدماً
    // وهمياً بعناوين @darby.test داخل قاعدة بياناتك الحقيقية.
    use DatabaseTransactions;

    protected User $adminUser;
    protected User $parentUser;
    protected ParentModel $parentProfile;
    protected Zone $testZone;
    protected School $testSchool;
    protected Address $testAddress;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        // جلب أو إنشاء المنطقة للاختبار
        $this->testZone = Zone::first() ?? Zone::create([
            'sub_municipality_id' => 1,
            'name' => 'منطقة الاختبار الشامل ' . uniqid(),
        ]);

        // جلب أو إنشاء المدير
        $this->adminUser = User::where('role_id', 1)->first() ?? User::create([
            'full_name' => 'مدير النظام التنفيذي',
            'email' => 'admin.test.' . uniqid() . '@darby.test',
            'phone_number' => '091' . rand(1000000, 9999999),
            'password_hash' => Hash::make('Darby2026'),
            'role_id' => 1,
            'is_active' => 1,
        ]);

        // إنشاء ولي أمر نظيف للاختبار
        $this->parentUser = User::create([
            'full_name' => 'عبد الرحمن يوسف التاجوري',
            'email' => 'parent.lifecycle.' . uniqid() . '@darby.test',
            'phone_number' => '092' . rand(1000000, 9999999),
            'password_hash' => Hash::make('Pass1234'),
            'role_id' => 3,
            'is_active' => 1,
        ]);

        $this->parentProfile = ParentModel::create([
            'user_id' => $this->parentUser->id,
            'is_trusted' => 1,
        ]);

        // إنشاء مدرسة للاختبار
        $this->testSchool = School::create([
            'name' => 'مدرسة النخبة النموذجية ' . uniqid(),
            'lat' => 32.8850,
            'lng' => 13.1950,
            'address' => 'طرابلس - شارع الجمهورية',
            'zone_id' => $this->testZone->id,
            'status' => 'active',
        ]);

        // إنشاء عنوان لولي الأمر
        $this->testAddress = Address::create([
            'parent_id' => $this->parentUser->id,
            'label' => 'المنزل الرئيسي ' . rand(100000, 999999),
            'lat' => 32.8910,
            'lng' => 13.1760,
        ]);
    }

    /**
     * حساب تاريخ بداية ونهاية صالح للاشتراك المدرسي (أيام عمل غير جمعة وسبت)
     */
    private function getValidWorkingDates(int $durationDays = 30): array
    {
        $start = Carbon::now()->addDays(2);
        while ($start->isFriday() || $start->isSaturday()) {
            $start->addDay();
        }

        $end = $start->copy()->addDays($durationDays);
        while ($end->isFriday() || $end->isSaturday()) {
            $end->addDay();
        }

        return [$start->toDateString(), $end->toDateString()];
    }

    // =========================================================================
    // 1. اختبارات ولي الأمر (تسجيل، دخول، بروفايل، تغيير الإيميل)
    // =========================================================================

    public function test_parent_send_otp_validations_and_success(): void
    {
        // 1. بريد فارغ أو صيغة خاطئة
        $res1 = $this->postJson('/api/parent/send-otp', ['email' => 'invalid-email-format']);
        $res1->assertStatus(422);

        // 2. بريد مسجل مسبقاً
        $res2 = $this->postJson('/api/parent/send-otp', ['email' => $this->parentUser->email]);
        $res2->assertStatus(400)
            ->assertJsonPath('error_code', 'EMAIL_ALREADY_EXISTS');

        // 3. نجاح إرسال OTP لبريد جديد
        $newEmail = 'new.parent.' . uniqid() . '@darby.test';
        $res3 = $this->postJson('/api/parent/send-otp', ['email' => $newEmail]);
        $res3->assertStatus(200)
            ->assertJsonPath('status', true);
    }

    public function test_parent_register_validations_and_success(): void
    {
        $email = 'register.parent.' . uniqid() . '@darby.test';
        \App\Models\Shared\OtpCode::create([
            'email' => $email,
            'purpose' => 'REGISTER',
            'code_hash' => Hash::make('123456'),
            'expires_at' => now()->addMinutes(15),
            'is_used' => 0,
            'attempts' => 0,
        ]);

        // 1. فشل عند نقص الحقول الإجبارية
        $res1 = $this->postJson('/api/parent/register', []);
        $res1->assertStatus(422)
            ->assertJsonStructure(['status', 'error_code', 'message', 'errors']);

        // 2. فشل عند إدخال كلمة مرور بدون أرقام (حروف فقط)
        $res2 = $this->postJson('/api/parent/register', [
            'full_name' => 'خالد عبد الله المنفي',
            'email' => $email,
            'phone_number' => '0918887766',
            'password' => 'PassOnlyWithoutDigits',
            'password_confirmation' => 'PassOnlyWithoutDigits',
            'otp' => '123456',
        ]);
        $res2->assertStatus(422);

        // 3. فشل عند عدم تطابق تأكيد كلمة المرور
        $res3 = $this->postJson('/api/parent/register', [
            'full_name' => 'خالد عبد الله المنفي',
            'email' => $email,
            'phone_number' => '0918887766',
            'password' => 'Pass1234',
            'password_confirmation' => 'DifferentPass1234',
            'otp' => '123456',
        ]);
        $res3->assertStatus(422);

        // 4. نجاح التسجيل مع كافة الحقول الإجبارية والاختيارية والصورة
        $avatar = UploadedFile::fake()->image('parent_avatar.jpg', 300, 300);
        $res4 = $this->postJson('/api/parent/register', [
            'full_name' => 'خالد عبد الله المنفي',
            'email' => $email,
            'phone_number' => '091' . rand(1000000, 9999999),
            'alternative_phone' => '092' . rand(1000000, 9999999),
            'password' => 'Pass1234',
            'password_confirmation' => 'Pass1234',
            'otp' => '123456',
            'avatar' => $avatar,
            'device_name' => 'Samsung Galaxy S24',
            'platform' => 'android',
            'fcm_token' => 'fcm_token_sample_' . uniqid(),
        ]);

        $res4->assertStatus(201)
            ->assertJsonPath('status', true)
            ->assertJsonStructure(['status', 'message', 'data' => ['id_user', 'full_name', 'email'], 'token']);

        $this->assertDatabaseHas('users', ['email' => $email, 'role_id' => 3]);
    }

    public function test_parent_profile_view_and_update_lifecycle(): void
    {
        // 1. غير مسجل دخول يرجع 401
        $resUnauth = $this->getJson('/api/parent/profile');
        $resUnauth->assertStatus(401);

        // 2. عرض البروفايل بنجاح
        $resProfile = $this->actingAs($this->parentUser, 'sanctum')->getJson('/api/parent/profile');
        $resProfile->assertStatus(200)
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.email', $this->parentUser->email);

        // 3. فشل تحديث البروفايل عند إرسال بريد مكرر لمستخدم آخر
        $otherUser = User::create([
            'full_name' => 'مستخدم آخر للنظام',
            'email' => 'other.user.' . uniqid() . '@darby.test',
            'phone_number' => '091' . rand(1000000, 9999999),
            'password_hash' => Hash::make('Pass1234'),
            'role_id' => 3,
        ]);

        $resDupEmail = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/update', [
            'email' => $otherUser->email,
        ]);
        $resDupEmail->assertStatus(422);

        // 4. تحديث البروفايل بنجاح مع صورة وحقول اختيارية
        $avatar = UploadedFile::fake()->image('updated_avatar.png', 400, 400);
        $resUpdate = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/profile/update', [
            'full_name' => 'عبد الرحمن يوسف التاجوري المعدل',
            'alternative_phone' => '0219998877',
            'avatar' => $avatar,
            'is_trusted' => true,
        ]);

        $resUpdate->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.profile.full_name', 'عبد الرحمن يوسف التاجوري المعدل');

        // 5. فحص حالة تغيير البريد الإلكتروني
        $resStatus = $this->actingAs($this->parentUser, 'sanctum')->getJson('/api/parent/profile/email-change/status');
        $resStatus->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    // =========================================================================
    // 2. اختبارات العناوين (Addresses: إضافة، عرض، تعديل، حذف)
    // =========================================================================

    public function test_parent_addresses_crud_lifecycle(): void
    {
        // 1. إضافة عنوان جديد - أخطاء التحقق
        $resMissing = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/addresses', []);
        $resMissing->assertStatus(422);

        $resInvalidCoord = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/addresses', [
            'label' => 'موقع خاطئ',
            'lat' => 200, // خطأ: خارج نطاق -90 و 90
            'lng' => 13.18,
        ]);
        $resInvalidCoord->assertStatus(422);

        // 2. إضافة عنوان بنجاح
        $uniqueLabel = 'منزل العائلة ' . rand(100000, 999999);
        $resCreate = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/addresses', [
            'label' => $uniqueLabel,
            'lat' => 32.8950,
            'lng' => 13.1820,
        ]);

        $resCreate->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.label', $uniqueLabel);

        $newAddressId = $resCreate->json('data.id');

        // 3. فشل إضافة نفس الاسم لنفس ولي الأمر (Duplicate Label)
        $resDup = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/addresses', [
            'label' => $uniqueLabel,
            'lat' => 32.9000,
            'lng' => 13.2000,
        ]);
        $resDup->assertStatus(422);

        // 4. جلب قائمة العناوين
        $resList = $this->actingAs($this->parentUser, 'sanctum')->getJson('/api/parent/addresses');
        $resList->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['success', 'message', 'data']);

        // 5. تعديل العنوان
        $updatedLabel = 'فيلا العائلة المعدلة ' . rand(100000, 999999);
        $resUpdate = $this->actingAs($this->parentUser, 'sanctum')->postJson("/api/parent/addresses/{$newAddressId}", [
            'label' => $updatedLabel,
            'lat' => 32.8960,
            'lng' => 13.1830,
        ]);

        $resUpdate->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.label', $updatedLabel);

        // 6. حذف العنوان
        $resDelete = $this->actingAs($this->parentUser, 'sanctum')->deleteJson("/api/parent/addresses/{$newAddressId}");
        $resDelete->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    // =========================================================================
    // 3. اختبارات المدارس (Schools: عرض، اقتراح من ولي الأمر، إضافة/تعديل/حذف من الأدمن)
    // =========================================================================

    public function test_schools_parent_and_admin_lifecycle(): void
    {
        // 1. عرض المدارس من واجهة ولي الأمر
        $resParentSchools = $this->actingAs($this->parentUser, 'sanctum')->getJson('/api/parent/schools');
        $resParentSchools->assertStatus(200)
            ->assertJsonPath('success', true);

        // 2. ولي الأمر يقترح مدرسة جديدة
        $suggestedName = 'مدرسة الأفق المقترحة ' . uniqid();
        $resSuggest = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/suggest-school', [
            'name' => $suggestedName,
            'lat' => 32.8900,
            'lng' => 13.1700,
            'address' => 'حي الأندلس - شارع المعاهد',
            'zone_id' => $this->testZone->id,
        ]);

        $resSuggest->assertStatus(201)
            ->assertJsonPath('success', true);
        $this->assertDatabaseHas('schools', ['name' => $suggestedName, 'status' => 'pending']);

        // 3. الأدمن يضيف مدرسة جديدة مفعلة مباشرة
        $adminSchoolName = 'مدرسة المستقبل المعتمدة ' . uniqid();
        $resAdminStore = $this->actingAs($this->adminUser, 'sanctum')->postJson('/api/admin/schools', [
            'name' => $adminSchoolName,
            'lat' => 32.8800,
            'lng' => 13.1900,
            'address' => 'بن عاشور - بالقرب من السفارة',
            'zone_id' => $this->testZone->id,
        ]);

        $resAdminStore->assertStatus(201)
            ->assertJsonPath('success', true);
        $schoolId = $resAdminStore->json('data.id');

        // 4. فشل إضافة مدرسة بنفس الاسم المكرر
        $resDup = $this->actingAs($this->adminUser, 'sanctum')->postJson('/api/admin/schools', [
            'name' => $adminSchoolName,
            'lat' => 32.8800,
            'lng' => 13.1900,
            'address' => 'عنوان آخر',
            'zone_id' => $this->testZone->id,
        ]);
        $resDup->assertStatus(422);

        // 5. عرض تفاصيل المدرسة للأدمن
        $resShow = $this->actingAs($this->adminUser, 'sanctum')->getJson("/api/admin/schools/{$schoolId}");
        $resShow->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', $adminSchoolName);

        // 6. تعديل بيانات المدرسة
        $updatedSchoolName = 'مدرسة المستقبل المطورة ' . uniqid();
        $resUpdate = $this->actingAs($this->adminUser, 'sanctum')->postJson("/api/admin/schools/{$schoolId}", [
            'name' => $updatedSchoolName,
            'address' => 'بن عاشور - المجمع التعليمي',
            'status' => 'active',
        ]);

        $resUpdate->assertStatus(200)
            ->assertJsonPath('success', true);

        // 7. حذف المدرسة غير المرتبطة بأطفال
        $resDelete = $this->actingAs($this->adminUser, 'sanctum')->deleteJson("/api/admin/schools/{$schoolId}");
        $resDelete->assertStatus(200)
            ->assertJsonPath('success', true);
    }

    // =========================================================================
    // 4. اختبارات الأطفال (Children: إضافة، عرض، تعديل، حذف، فحص الاشتراكات)
    // =========================================================================

    public function test_children_crud_and_validations_lifecycle(): void
    {
        // 1. أخطاء التحقق عند ترك الحقول فارغة
        $resEmpty = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/children', []);
        $resEmpty->assertStatus(422)
            ->assertJsonStructure(['success', 'message', 'errors']);

        // 2. خطأ: الاسم ليس ثلاثياً بالعربية
        $resShortName = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/children', [
            'full_name' => 'أحمد علي', // كلمتان فقط
            'school_id' => $this->testSchool->id,
            'address_id' => $this->testAddress->id,
            'birth_date' => '2016-05-15',
            'gender' => 'male',
            'grade' => 3,
            'preferred_time_slot' => 'morning',
        ]);
        $resShortName->assertStatus(422);

        // 3. خطأ: عمر الطفل غير متوافق (أقل من 6 سنوات أو أكبر من 21)
        $resInvalidAge = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/children', [
            'full_name' => 'أحمد علي محمود التاجوري',
            'school_id' => $this->testSchool->id,
            'address_id' => $this->testAddress->id,
            'birth_date' => Carbon::now()->subYears(3)->format('Y-m-d'), // 3 سنوات فقط
            'gender' => 'male',
            'grade' => 1,
            'preferred_time_slot' => 'morning',
        ]);
        $resInvalidAge->assertStatus(422);

        // 5. نجاح إضافة الطفل مع كافة البيانات الإجبارية والاختيارية والصورة
        $childPhoto = UploadedFile::fake()->image('student_photo.jpg', 300, 300);
        $childName = 'سند عبد الرحمن التاجوري';
        $resCreate = $this->actingAs($this->parentUser, 'sanctum')->postJson('/api/parent/children', [
            'full_name' => $childName,
            'school_id' => $this->testSchool->id,
            'address_id' => $this->testAddress->id,
            'birth_date' => '2016-05-15',
            'gender' => 'male',
            'grade' => 3,
            'preferred_time_slot' => 'morning',
            'pickup_time' => '07:15',
            'dropoff_time' => '13:45',
            'medical_notes' => 'حساسية طفيفة من البنسلين',
            'notification_radius' => 600,
            'photo' => $childPhoto,
        ]);

        $resCreate->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.full_name', $childName)
            ->assertJsonPath('data.school_stage', 'primary')
            ->assertJsonPath('data.school_stage_label', 'ابتدائي')
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'id', 'full_name', 'gender', 'age', 'grade',
                    'school_stage', 'school_stage_label', 'qr_code_token',
                    'school', 'address', 'logistics'
                ]
            ]);

        $childId = $resCreate->json('data.id');

        // 6. فحص دالة وجود أطفال لولي الأمر (has-children)
        $resHasChildren = $this->actingAs($this->parentUser, 'sanctum')->getJson('/api/parent/children/has-children');
        $resHasChildren->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('has_children', true);

        // 7. جلب قائمة أطفال ولي الأمر
        $resList = $this->actingAs($this->parentUser, 'sanctum')->getJson('/api/parent/children');
        $resList->assertStatus(200)
            ->assertJsonPath('success', true);

        // 8. عرض تفاصيل طفل محدد
        $resShow = $this->actingAs($this->parentUser, 'sanctum')->getJson("/api/parent/children/{$childId}");
        $resShow->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $childId);

        // 9. عرض اشتراك ولوجستيات الطفل
        $resSub = $this->actingAs($this->parentUser, 'sanctum')->getJson("/api/parent/children/{$childId}/subscription");
        $resSub->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.child_id', $childId);

        // 10. تعديل بيانات الطفل بنجاح
        $updatedChildName = 'سند عبد الرحمن يوسف التاجوري';
        $resUpdate = $this->actingAs($this->parentUser, 'sanctum')->postJson("/api/parent/children/{$childId}", [
            'full_name' => $updatedChildName,
            'grade' => 4,
            'medical_notes' => 'لا توجد أي موانع صحية',
            'notification_radius' => 750,
        ]);

        $resUpdate->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.full_name', $updatedChildName);

        // 11. حذف الطفل وإلغاء اشتراكه
        $resDelete = $this->actingAs($this->parentUser, 'sanctum')->deleteJson("/api/parent/children/{$childId}");
        $resDelete->assertStatus(200)
            ->assertJsonPath('success', true);
    }
}
