<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Driver\Driver;
use App\Models\Shared\SubscriptionRequest;
use App\Models\Shared\ActiveSubscription;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * اختبار قوائم المحادثات (GET /api/parent/chats و GET /api/driver/chats).
 *
 * غلط #1 (مُصلَح): كلا الدالتين (SubscriptionRequestService::getParentChats/
 * getDriverChats) كانتا تجلبان صفوف جدول الاشتراكات (requests) بشرط
 * ->whereIn('status', ['accepted', 'contract_offered', 'pending', 'active'])
 * — أي أن أي اشتراك حالته 'cancelled' أو 'rejected' (وليس له بعد صف في
 * active_subscriptions) كان يختفي تماماً من قائمة المحادثات، رغم وجود علاقة
 * اشتراك فعلية سابقة بين ولي الأمر والسائق. تم حذف شرط whereIn هذا بالكامل
 * ليعود أي اشتراك — بغض النظر عن حالته — ضمن قائمة المحادثات، بما يتفق مع
 * SubscriptionRequest::existsForParentAndDriver() المستخدمة في تقييمات
 * السائقين والشكاوى (نفس المبدأ: العلاقة موجودة، بصرف النظر عن حالتها).
 *
 * غلط #2 (مُصلَح): حقل "can_chat" لصفوف active_subscriptions كان محسوباً بـ
 * in_array(strtolower($sub->status), ['active', 'approved']) — فيرجع false
 * لأي اشتراك حالته 'completed' (أو 'paused'/'cancelled'...) رغم أن المطلوب
 * أن يُسمح بالمحادثة طالما هناك اشتراك مسجَّل، بصرف النظر عن حالته. تم توحيده
 * ليرجع true دائماً، مطابقاً لسلوك صفوف requests التي كانت أصلاً ترجع true دوماً.
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

    #[DataProvider('subscriptionStatusProvider')]
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

    #[DataProvider('subscriptionStatusProvider')]
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

    public static function activeSubscriptionStatusProvider(): array
    {
        return [
            'active'    => ['active'],
            'completed' => ['completed'],
            'cancelled' => ['cancelled'],
            'paused'    => ['paused'],
        ];
    }

    #[DataProvider('activeSubscriptionStatusProvider')]
    public function test_parent_chat_can_chat_is_true_regardless_of_active_subscription_status(string $status): void
    {
        $driver = $this->makeDriver('active_sub_' . $status);

        $subscriptionRequest = SubscriptionRequest::create([
            'parent_id' => $this->parentUser->id,
            'driver_id' => $driver->id,
            'status'    => SubscriptionRequest::STATUS_ACCEPTED,
        ]);

        ActiveSubscription::create([
            'subscription_request_id' => $subscriptionRequest->id,
            'status'                  => $status,
        ]);

        $response = $this->actingAs($this->parentUser)->getJson('/api/parent/chats');

        $response->assertStatus(200);

        $chat = collect($response->json('data'))->firstWhere('driver_id', $driver->id);
        $this->assertNotNull($chat, "expected driver {$driver->id} in parent chat list");
        $this->assertTrue(
            $chat['can_chat'],
            "expected can_chat=true for active_subscriptions status [{$status}], got: " . json_encode($chat)
        );
    }

    #[DataProvider('activeSubscriptionStatusProvider')]
    public function test_driver_chat_can_chat_is_true_regardless_of_active_subscription_status(string $status): void
    {
        $driver = $this->makeDriver('driver_active_sub_' . $status);

        $subscriptionRequest = SubscriptionRequest::create([
            'parent_id' => $this->parentUser->id,
            'driver_id' => $driver->id,
            'status'    => SubscriptionRequest::STATUS_ACCEPTED,
        ]);

        ActiveSubscription::create([
            'subscription_request_id' => $subscriptionRequest->id,
            'status'                  => $status,
        ]);

        $response = $this->actingAs($driver->user)->getJson('/api/driver/chats');

        $response->assertStatus(200);

        $chat = collect($response->json('data'))->firstWhere('parent_user_id', $this->parentUser->id);
        $this->assertNotNull($chat, "expected parent {$this->parentUser->id} in driver chat list");
        $this->assertTrue(
            $chat['can_chat'],
            "expected can_chat=true for active_subscriptions status [{$status}], got: " . json_encode($chat)
        );
    }
}
