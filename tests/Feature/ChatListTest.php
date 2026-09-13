<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Shared\SubscriptionRequest;

/**
 * اختبار قوائم المحادثات (GET /api/parent/chats و GET /api/driver/chats).
 *
 * الغلط المكتشف: كلا الدالتين (SubscriptionRequestService::getParentChats/
 * getDriverChats) كانتا تجلبان صفوف جدول الاشتراكات (requests) بشرط
 * ->whereIn('status', ['accepted', 'contract_offered', 'pending', 'active'])
 * — أي أن أي اشتراك حالته 'cancelled' أو 'rejected' (وليس له بعد صف في
 * active_subscriptions) كان يختفي تماماً من قائمة المحادثات، رغم وجود علاقة
 * اشتراك فعلية سابقة بين ولي الأمر والسائق. تم حذف شرط whereIn هذا بالكامل
 * ليعود أي اشتراك — بغض النظر عن حالته — ضمن قائمة المحادثات، بما يتفق مع
 * SubscriptionRequest::existsForParentAndDriver() المستخدمة في تقييمات
 * السائقين والشكاوى (نفس المبدأ: العلاقة موجودة، بصرف النظر عن حالتها).
 */
class ChatListTest extends TestCase
{
    use DatabaseTransactions;

    protected User $parentUser;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('roles')->insertOrIgnore([
            ['id' => 7, 'name' => 'parent', 'display_name' => 'ولي أمر', 'kind' => 'account', 'is_super' => 0],
            ['id' => 8, 'name' => 'driver', 'display_name' => 'سائق حافلة/فان', 'kind' => 'account', 'is_super' => 0],
        ]);

        $this->parentUser = User::create([
            'full_name'    => 'ولي أمر المحادثات',
            'email'        => 'parent.chat.' . uniqid() . '@darby.test',
            'phone_number' => '096' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'      => 7,
            'is_active'    => 1,
        ]);
    }

    protected function makeDriver(string $label): Driver
    {
        $driverUser = User::create([
            'full_name'    => 'سائق المحادثات ' . $label,
            'email'        => 'driver.chat.' . uniqid() . '@darby.test',
            'phone_number' => '097' . rand(1000000, 9999999),
            'password_hash' => bcrypt('password123'),
            'role_id'      => 8,
            'is_active'    => 1,
        ]);

        return Driver::create([
            'user_id'        => $driverUser->id,
            'national_id'    => 'NAT' . rand(100000, 999999),
            'license_number' => 'LIC' . rand(100000, 999999),
            'license_expiry' => now()->addYears(2)->format('Y-m-d'),
            'status'         => 'Approved',
        ]);
    }

    public static function subscriptionStatusProvider(): array
    {
        return [
            'pending'          => [SubscriptionRequest::STATUS_PENDING],
            'accepted'         => [SubscriptionRequest::STATUS_ACCEPTED],
            'cancelled'        => [SubscriptionRequest::STATUS_CANCELLED],
            'rejected'         => [SubscriptionRequest::STATUS_REJECTED],
            'contract_offered' => ['contract_offered'],
        ];
    }

    /**
     * @dataProvider subscriptionStatusProvider
     */
    public function test_parent_chat_list_includes_driver_regardless_of_subscription_status(string $status): void
    {
        $driver = $this->makeDriver($status);

        SubscriptionRequest::create([
            'parent_id' => $this->parentUser->id,
            'driver_id' => $driver->id,
            'status'    => $status,
        ]);

        $response = $this->actingAs($this->parentUser)->getJson('/api/parent/chats');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $driverIds = collect($response->json('data'))->pluck('driver_id');
        $this->assertTrue(
            $driverIds->contains($driver->id),
            "expected driver {$driver->id} in parent chat list for subscription status [{$status}], got: " . json_encode($driverIds)
        );
    }

    /**
     * @dataProvider subscriptionStatusProvider
     */
    public function test_driver_chat_list_includes_parent_regardless_of_subscription_status(string $status): void
    {
        $driver = $this->makeDriver('for_driver_' . $status);

        SubscriptionRequest::create([
            'parent_id' => $this->parentUser->id,
            'driver_id' => $driver->id,
            'status'    => $status,
        ]);

        $response = $this->actingAs($driver->user)->getJson('/api/driver/chats');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $parentUserIds = collect($response->json('data'))->pluck('parent_user_id');
        $this->assertTrue(
            $parentUserIds->contains($this->parentUser->id),
            "expected parent {$this->parentUser->id} in driver chat list for subscription status [{$status}], got: " . json_encode($parentUserIds)
        );
    }
}
