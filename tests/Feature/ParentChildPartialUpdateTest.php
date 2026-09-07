<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Models\Parent\ParentModel;
use App\Models\Parent\Child;
use App\Models\Parent\School;
use App\Models\Parent\Address;

class ParentChildPartialUpdateTest extends TestCase
{
    use DatabaseTransactions;

    protected User $parentUser;
    protected ParentModel $parent;
    protected School $school1;
    protected School $school2;
    protected Address $address1;
    protected Address $address2;
    protected Child $child;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        DB::table('roles')->insertOrIgnore([
            ['id' => 3, 'name' => 'ParentRole3', 'display_name' => 'ولي أمر'],
        ]);

        $this->parentUser = User::create([
            'full_name'     => 'ولي أمر تجريبي للتعديل',
            'email'         => 'parent.child.update.' . uniqid() . '@darby.test',
            'phone_number'  => '091' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => 3,
            'is_active'     => 1,
        ]);

        $this->parent = ParentModel::create([
            'user_id' => $this->parentUser->id,
        ]);

        $this->school1 = School::create([
            'name'      => 'مدرسة النور الابتدائية',
            'address'   => 'طرابلس - النوفليين',
            'lat'       => 32.88,
            'lng'       => 13.18,
            'status'    => 'approved',
        ]);

        $this->school2 = School::create([
            'name'      => 'مدرسة الأمل الثانوية',
            'address'   => 'طرابلس - بن عاشور',
            'lat'       => 32.89,
            'lng'       => 13.20,
            'status'    => 'approved',
        ]);

        $this->address1 = Address::create([
            'parent_id' => $this->parentUser->id,
            'label'     => 'البيت',
            'lat'       => 32.87,
            'lng'       => 13.17,
        ]);

        $this->address2 = Address::create([
            'parent_id' => $this->parentUser->id,
            'label'     => 'بيت الجدة',
            'lat'       => 32.90,
            'lng'       => 13.22,
        ]);

        $this->child = Child::create([
            'parent_id'           => $this->parent->id,
            'school_id'           => $this->school1->id,
            'address_id'          => $this->address1->id,
            'full_name'           => 'عمر أحمد علي',
            'birth_date'          => '2015-05-10',
            'gender'              => 'male',
            'grade'               => 3,
            'medical_notes'       => 'حساسية من الغبار',
            'notification_radius' => 300,
        ]);

        $this->child->logistics()->create([
            'preferred_time_slot' => 'morning',
            'pickup_time'         => '07:30',
            'dropoff_time'        => '13:30',
            'is_active'           => true,
        ]);
    }

    /**
     * 1. اختبار التعديل الجزئي للاسم والصف الدراسي فقط
     */
    public function test_parent_can_partially_update_child_name_and_grade(): void
    {
        $newName = 'عمر أحمد المحمودي';
        $newGrade = 4;

        $response = $this->actingAs($this->parentUser)
            ->postJson("/api/parent/children/{$this->child->id}", [
                'full_name' => $newName,
                'grade'     => $newGrade,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->child->refresh();
        $this->assertEquals($newName, $this->child->full_name);
        $this->assertEquals($newGrade, $this->child->grade);

        // التأكد من بقاء الحقول الأخرى كما هي
        $this->assertEquals($this->school1->id, $this->child->school_id);
        $this->assertEquals($this->address1->id, $this->child->address_id);
        $this->assertEquals('حساسية من الغبار', $this->child->medical_notes);
    }

    /**
     * 2. اختبار التعديل الجزئي للمدرسة والعنوان فقط
     */
    public function test_parent_can_partially_update_child_school_and_address(): void
    {
        $response = $this->actingAs($this->parentUser)
            ->putJson("/api/parent/children/{$this->child->id}", [
                'school_id'  => $this->school2->id,
                'address_id' => $this->address2->id,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->child->refresh();
        $this->assertEquals($this->school2->id, $this->child->school_id);
        $this->assertEquals($this->address2->id, $this->child->address_id);
        $this->assertEquals('عمر أحمد علي', $this->child->full_name);
    }

    /**
     * 3. اختبار التعديل الجزئي لصورة الطفل عبر الاسم البديل (child_photo)
     */
    public function test_parent_can_partially_update_child_photo_with_alias(): void
    {
        $response = $this->actingAs($this->parentUser)
            ->postJson("/api/parent/children/{$this->child->id}", [
                'child_photo' => UploadedFile::fake()->image('child_avatar.webp'),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->child->refresh();
        $this->assertNotEmpty($this->child->photo_url);
    }

    /**
     * 4. اختبار التعديل الجزئي للبيانات اللوجستية والتوقيت فقط
     */
    public function test_parent_can_partially_update_child_logistics_only(): void
    {
        $response = $this->actingAs($this->parentUser)
            ->patchJson("/api/parent/children/{$this->child->id}", [
                'preferred_time_slot' => 'evening',
                'pickup_time'         => '12:45',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->child->refresh();
        $logistics = $this->child->logistics;

        $this->assertEquals('evening', $logistics->preferred_time_slot);
        $this->assertEquals('12:45:00', $logistics->pickup_time);
        $this->assertEquals('13:30:00', $logistics->dropoff_time); // الحقل غير المعدل ظل كما هو
    }

    /**
     * 5. اختبار التعديل الجزئي للملاحظات الطبية ونصف قطر التنبيه
     */
    public function test_parent_can_partially_update_child_medical_notes_and_radius(): void
    {
        $newNotes = 'لا توجد أي حساسية حالياً، بصحة ممتازة.';
        $newRadius = 500;

        $response = $this->actingAs($this->parentUser)
            ->postJson("/api/parent/children/{$this->child->id}", [
                'medical_notes'       => $newNotes,
                'notification_radius' => $newRadius,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->child->refresh();
        $this->assertEquals($newNotes, $this->child->medical_notes);
        $this->assertEquals($newRadius, $this->child->notification_radius);
    }
}
