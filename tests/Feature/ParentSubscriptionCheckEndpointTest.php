<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use App\Models\User;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\SubscriptionRequest;

class ParentSubscriptionCheckEndpointTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * التحقق من أن ولي الأمر الذي لا يمتلك أي اشتراك يرجع FALSE
     */
    public function test_returns_false_when_parent_has_no_subscriptions(): void
    {
        $userNoSub = User::whereNotIn('id', function ($q) {
            $q->select('parent_id')->from('requests');
        })->first();

        $this->assertNotNull($userNoSub);

        $response = $this->actingAs($userNoSub)->getJson('/api/parent/subscriptions/check');

        $response->assertStatus(200)
            ->assertJson([
                'success'          => true,
                'has_subscription' => false,
            ]);
    }

    /**
     * التحقق من أن ولي الأمر الذي لديه اشتراكات منتهية فقط يرجع FALSE
     */
    public function test_returns_false_when_parent_has_only_completed_subscriptions(): void
    {
        $parentCompleted = User::find(2820);
        $this->assertNotNull($parentCompleted);

        $response = $this->actingAs($parentCompleted)->getJson('/api/parent/subscriptions/check');

        $response->assertStatus(200)
            ->assertJson([
                'success'          => true,
                'has_subscription' => false,
            ]);
    }

    /**
     * التحقق من أن ولي الأمر الذي لديه اشتراك نشط واحد على الأقل يرجع TRUE
     */
    public function test_returns_true_when_parent_has_active_subscription(): void
    {
        $parentActive = User::find(2819);
        $this->assertNotNull($parentActive);

        $response = $this->actingAs($parentActive)->getJson('/api/parent/subscriptions/check');

        $response->assertStatus(200)
            ->assertJson([
                'success'          => true,
                'has_subscription' => true,
            ]);
    }

    /**
     * التحقق من أن ولي الأمر الذي لديه اشتراكات منتهية واشتراكات نشطة يرجع TRUE (لوجود نشط واحد على الأقل)
     */
    public function test_returns_true_when_parent_has_both_completed_and_active_subscriptions(): void
    {
        $parentWithBoth = User::find(7);
        $this->assertNotNull($parentWithBoth);

        $response = $this->actingAs($parentWithBoth)->getJson('/api/parent/subscriptions/check');

        $response->assertStatus(200)
            ->assertJson([
                'success'          => true,
                'has_subscription' => true,
            ]);
    }

    /**
     * التحقق من فلترة السائق ?driver_id=
     */
    public function test_driver_filter_respects_active_status_for_specific_driver(): void
    {
        // ولي الأمر 7 لديه اشتراك نشط وساري
        $activeSub = ActiveSubscription::query()
            ->where('status', 'active')
            ->whereHas('subscriptionRequest', function ($q) {
                $q->where('parent_id', 7)
                  ->where(function ($dateQ) {
                      $dateQ->whereNull('end_date')
                            ->orWhereDate('end_date', '>=', now()->toDateString());
                  });
            })
            ->with('subscriptionRequest')
            ->first();

        $this->assertNotNull($activeSub);
        $driverId = $activeSub->subscriptionRequest->driver_id;

        // مع السائق الفعلي للاشتراك النشط -> TRUE
        $resMatching = $this->actingAs(User::find(7))
            ->getJson("/api/parent/subscriptions/check?driver_id={$driverId}");
        $resMatching->assertStatus(200)->assertJson(['has_subscription' => true]);

        // مع سائق عشوائي آخر ليس لديه معه أي اشتراك نشط -> FALSE
        $resOtherDriver = $this->actingAs(User::find(7))
            ->getJson("/api/parent/subscriptions/check?driver_id=999999");
        $resOtherDriver->assertStatus(200)->assertJson(['has_subscription' => false]);
    }
}
