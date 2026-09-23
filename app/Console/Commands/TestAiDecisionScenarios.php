<?php

namespace App\Console\Commands;

use App\Jobs\ClassifyDriverReviewJob;
use App\Models\Admin\AdminAlert;
use App\Models\Driver\Driver;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\DriverReview;
use App\Models\Shared\SubscriptionRequest;
use App\Models\User;
use App\Services\Shared\SubscriptionRequestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * أداة اختبار حيّة (end-to-end) لمحرك "تحليل التعليقات واتخاذ القرار" (AiDecisionService)
 * تستدعي خدمة الذكاء الاصطناعي الحقيقية (ai-service/inference_api.py) على المنفذ 8001
 * وتبني تاريخاً مختلفاً لكل سائق لإثبات أن نفس نوع التعليق يمكن أن يقود لقرارات مختلفة
 * حسب تاريخ السائق (تحذيرات سابقة، بلاغات سلامة، إلخ).
 *
 * تشغيل: php artisan ai:test-decision-scenarios
 */
class TestAiDecisionScenarios extends Command
{
    protected $signature = 'ai:test-decision-scenarios {--cleanup : حذف كل بيانات الاختبار التي أنشأها هذا الأمر مسبقاً قبل البدء}';

    protected $description = 'يشغّل سيناريوهات حقيقية عبر محرك تصنيف/قرار الذكاء الاصطناعي (driver_reviews) ويطبع النتائج';

    private const SAFETY_TEXT = 'السائق كان يقود بسرعة جنونية ويستخدم الهاتف أثناء القيادة، كدنا نتعرض لحادث خطير جداً مع الأطفال.';
    private const POSITIVE_TEXT = 'السائق ممتاز جداً، دائماً في الموعد ومحترم مع الأطفال. شكراً لكم.';
    private const PUNCTUALITY_NEG_TEXT = 'السائق يتأخر كثيراً عن موعد الوصول تقريباً كل يوم، وأحياناً يتأخر أكثر من نصف ساعة.';
    private const VEHICLE_NEG_TEXT = 'السيارة وسخة جداً من الداخل ورائحتها سيئة، والمقاعد ممزقة وغير آمنة.';

    private const TAG = 'ai_scenario_test_v1';

    public function handle(SubscriptionRequestService $subs): int
    {
        DB::table('roles')->insertOrIgnore([
            ['id' => 1, 'name' => 'super_admin', 'display_name' => 'مدير النظام العام', 'kind' => 'staff', 'is_super' => 1],
            ['id' => 7, 'name' => 'parent',      'display_name' => 'ولي أمر',            'kind' => 'account', 'is_super' => 0],
            ['id' => 8, 'name' => 'driver',      'display_name' => 'سائق حافلة/فان',      'kind' => 'account', 'is_super' => 0],
        ]);

        if ($this->option('cleanup')) {
            $this->cleanupPreviousRuns();
            $this->info('تم تنظيف بيانات التشغيلات السابقة.');
        }

        $this->info('=== 0) التحقق من بوابة "الاشتراك نشط/مكتمل" قبل السماح بالتعليق ===');
        $this->checkSubscriptionGate($subs);

        $scenarios = $this->buildScenarios();

        $rows = [];
        foreach ($scenarios as $scenario) {
            $rows[] = $this->runScenario($scenario);
        }

        $this->newLine();
        $this->info('=== ملخص جميع السيناريوهات ===');
        $this->table(
            ['السيناريو', 'AI: تصنيف/فئة/خطورة', 'القرار', 'التقييم قبل → بعد', 'تحذيرات', 'حجب مؤقت؟', 'تنبيه أدمن؟'],
            $rows
        );

        return self::SUCCESS;
    }

    private function checkSubscriptionGate(SubscriptionRequestService $subs): void
    {
        $driverUser = $this->makeUser('driver', 'سائق بوابة الاشتراك');
        $driver = $this->makeDriver($driverUser);

        $parentNoSub = $this->makeUser('parent', 'ولي أمر بلا اشتراك');
        $eligibleWithoutSub = $subs->parentHasActiveOrCompletedSubscriptionWithDriver($parentNoSub->id, $driver->id);
        $this->line('  - ولي أمر بلا أي اشتراك مع السائق → مؤهل؟ ' . ($eligibleWithoutSub ? 'نعم (خطأ!)' : 'لا (صحيح، سيُمنع)'));

        $parentCancelled = $this->makeUser('parent', 'ولي أمر باشتراك ملغي');
        $this->makeSubscription($parentCancelled, $driver, 'cancelled');
        $eligibleCancelled = $subs->parentHasActiveOrCompletedSubscriptionWithDriver($parentCancelled->id, $driver->id);
        $this->line('  - ولي أمر باشتراك "ملغي" فقط → مؤهل؟ ' . ($eligibleCancelled ? 'نعم (خطأ!)' : 'لا (صحيح، سيُمنع)'));

        $parentActive = $this->makeUser('parent', 'ولي أمر باشتراك نشط');
        $this->makeSubscription($parentActive, $driver, 'active');
        $eligibleActive = $subs->parentHasActiveOrCompletedSubscriptionWithDriver($parentActive->id, $driver->id);
        $this->line('  - ولي أمر باشتراك "نشط" → مؤهل؟ ' . ($eligibleActive ? 'نعم (صحيح)' : 'لا (خطأ!)'));

        $parentCompleted = $this->makeUser('parent', 'ولي أمر باشتراك مكتمل');
        $this->makeSubscription($parentCompleted, $driver, 'completed');
        $eligibleCompleted = $subs->parentHasActiveOrCompletedSubscriptionWithDriver($parentCompleted->id, $driver->id);
        $this->line('  - ولي أمر باشتراك "مكتمل" → مؤهل؟ ' . ($eligibleCompleted ? 'نعم (صحيح)' : 'لا (خطأ!)'));

        $this->newLine();
    }

    /**
     * @return array<int, array{
     *     name:string, driver_label:string, history:array<int,array>, comment:string,
     *     rating:int, check:string, expected_code:?int, note:string
     * }>
     *
     * 'check' يحدد نوع الفحص:
     *   - 'exact'  → يجب أن يطابق decision_code = expected_code بالضبط (سقف أمان ثابت، مضمون).
     *   - 'not'    → يجب ألا يساوي decision_code = expected_code (سقف أمان يمنع قيمة معينة فقط).
     *   - 'info'   → لا يوجد ضمان؛ القرار بالكامل لنموذج XGBoost الخام، نعرضه للعلم فقط.
     */
    private function buildScenarios(): array
    {
        return [
            [
                'name'          => 'A) سائق نظيف السجل + تعليق يمس السلامة (خطورة قصوى)',
                'driver_label'  => 'سائق أ - نظيف',
                'history'       => [],
                'comment'       => self::SAFETY_TEXT,
                'rating'        => 1,
                'check'         => 'exact',
                'expected_code' => 4, // ADMIN_REVIEW_REQUIRED
                'note'          => '[سقف أمان ثابت] مضمون: مراجعة إدارية عاجلة فوراً — بلاغ سلامة يتجاوز أي قرار للنموذج',
            ],
            [
                'name'          => 'B) سائق نظيف السجل + تعليق إيجابي',
                'driver_label'  => 'سائق ب - نظيف',
                'history'       => [],
                'comment'       => self::POSITIVE_TEXT,
                'rating'        => 5,
                'check'         => 'info',
                'expected_code' => 1, // REWARD (تخمين، غير مضمون)
                'note'          => 'إعلامي: القرار بالكامل للنموذج الخام الآن — لا يوجد ضمان أنه REWARD',
            ],
            [
                'name'          => 'C) سائق لديه بلاغ سلامة سابق + نفس التعليق الإيجابي بالضبط (مقارنة مباشرة مع B)',
                'driver_label'  => 'سائق ج - لديه بلاغ سلامة',
                'history'       => [
                    ['label' => 'Negative', 'category' => 'Safety', 'severity' => 2, 'rating' => 1, 'days_ago' => 5],
                ],
                'comment'       => self::POSITIVE_TEXT,
                'rating'        => 5,
                'check'         => 'not',
                'expected_code' => 1, // يجب ألا يكون REWARD
                'note'          => '[سقف أمان ثابت] مضمون: ليس REWARD أبداً — بلاغ سلامة نشط يمنع أي مكافأة رشّحها النموذج',
            ],
            [
                'name'          => 'D) شكاوى تأخير متكررة من ولَيّي أمر مختلفين (مخالفة متوسطة متكررة)',
                'driver_label'  => 'سائق د - تأخير متكرر',
                'history'       => [
                    ['label' => 'Negative', 'category' => 'Punctuality', 'severity' => 1, 'rating' => 2, 'days_ago' => 3],
                    ['label' => 'Negative', 'category' => 'Punctuality', 'severity' => 1, 'rating' => 2, 'days_ago' => 7],
                ],
                'comment'       => self::PUNCTUALITY_NEG_TEXT,
                'rating'        => 2,
                'check'         => 'exact',
                'expected_code' => 2, // MODERATE_VIOLATION
                'note'          => '[سقف أمان ثابت] مضمون: مخالفة متوسطة فورية — نفس فئة الشكوى من 3 أولياء أمور مختلفين الآن، يتجاوز قرار النموذج',
            ],
            [
                'name'          => 'E) شكوى سابقة بفئة مختلفة + شكوى تأخير جديدة',
                'driver_label'  => 'سائق هـ - شكوى متفرقة',
                'history'       => [
                    ['label' => 'Negative', 'category' => 'Vehicle_Condition', 'severity' => 1, 'rating' => 2, 'days_ago' => 4],
                    ['label' => 'Neutral',  'category' => 'General',           'severity' => 0, 'rating' => 3, 'days_ago' => 6],
                ],
                'comment'       => self::PUNCTUALITY_NEG_TEXT,
                'rating'        => 2,
                'check'         => 'info',
                'expected_code' => 3, // تخمين، غير مضمون
                'note'          => 'إعلامي: لا سقف أمان يتدخل هنا (لا تكرار فئة، لا سلامة) — القرار بالكامل للنموذج',
            ],
            [
                'name'          => 'F) شكوى تأخير واحدة وسط أغلبية إيجابية',
                'driver_label'  => 'سائق و - أغلبية إيجابية',
                'history'       => [
                    ['label' => 'Positive', 'category' => 'General', 'severity' => 0, 'rating' => 5, 'days_ago' => 2],
                    ['label' => 'Positive', 'category' => 'General', 'severity' => 0, 'rating' => 5, 'days_ago' => 3],
                    ['label' => 'Positive', 'category' => 'General', 'severity' => 0, 'rating' => 5, 'days_ago' => 4],
                    ['label' => 'Positive', 'category' => 'General', 'severity' => 0, 'rating' => 5, 'days_ago' => 5],
                ],
                'comment'       => self::PUNCTUALITY_NEG_TEXT,
                'rating'        => 2,
                'check'         => 'info',
                'expected_code' => 0, // تخمين، غير مضمون
                'note'          => 'إعلامي: لا سقف أمان يتدخل هنا — القرار بالكامل للنموذج الخام لهذا التعليق منفرداً',
            ],
        ];
    }

    private function runScenario(array $scenario): array
    {
        $this->info('--- ' . $scenario['name'] . ' ---');
        $this->line('  ' . $scenario['note']);

        $driverUser = $this->makeUser('driver', $scenario['driver_label']);
        $driver = $this->makeDriver($driverUser);

        // بناء تاريخ السائق: تعليقات سابقة "مصنّفة ومعالَجة" فعلاً من أولياء أمور مختلفين
        foreach ($scenario['history'] as $i => $h) {
            $histParent = $this->makeUser('parent', $scenario['driver_label'] . ' - والد سابق #' . ($i + 1));
            $this->makeSubscription($histParent, $driver, 'completed');

            DriverReview::create([
                'parent_id'                => $histParent->id,
                'driver_id'                => $driver->id,
                'rating'                   => $h['rating'],
                'comment'                  => '[تاريخ اختبار] تعليق سابق فئة ' . $h['category'],
                'status'                   => 'active',
                'ai_label'                 => $h['label'],
                'ai_category'              => $h['category'],
                'ai_severity'              => $h['severity'],
                'ai_classified_at'         => now()->subDays($h['days_ago']),
                'ai_sentiment_confidence'  => 0.95,
                'ai_category_confidence'   => 0.95,
                'is_processed_in_decision' => true,
                'created_at'               => now()->subDays($h['days_ago']),
                'updated_at'               => now()->subDays($h['days_ago']),
            ]);
        }

        // ولي الأمر الذي يكتب التعليق الجديد الآن — يجب أن يملك اشتراكاً نشطاً/مكتملاً (البوابة الجديدة)
        $newParent = $this->makeUser('parent', $scenario['driver_label'] . ' - والد التعليق الجديد');
        $this->makeSubscription($newParent, $driver, 'active');

        $ratingBefore = (float) $driver->rating_avg;
        $warningsBefore = (int) $driver->active_warnings_count;

        $review = DriverReview::create([
            'parent_id' => $newParent->id,
            'driver_id' => $driver->id,
            'rating'    => $scenario['rating'],
            'comment'   => $scenario['comment'],
            'status'    => 'active',
        ]);

        // نفس ما يفعله DriverReviewController::store() — يستدعي محرك التصنيف الحقيقي
        // (FastAPI على المنفذ 8001) ثم محرك القرار (AiDecisionService) بشكل متزامن هنا
        // بدل عبر الطابور، لضمان نتيجة فورية وحتمية أثناء الاختبار.
        ClassifyDriverReviewJob::dispatchSync($review->id);

        $review->refresh();
        $driver->refresh();

        $alert = AdminAlert::where('driver_id', $driver->id)
            ->where('created_at', '>=', now()->subMinute())
            ->latest('id')
            ->first();

        $decisionCode = $review->ai_decision_code;

        $check = $scenario['check'] ?? 'info';
        $decisionOk = match ($check) {
            'exact' => ($decisionCode === $scenario['expected_code']),
            'not'   => ($decisionCode !== $scenario['expected_code']),
            default => true, // info: لا يوجد فشل، للعلم فقط
        };

        $verdictLabel = match (true) {
            $check === 'exact' && $decisionOk  => '✅ مضمون ومطابق (سقف أمان)',
            $check === 'exact' && !$decisionOk => '❌ خرق سقف الأمان — كان يجب أن يكون ' . $this->decisionName($scenario['expected_code']),
            $check === 'not' && $decisionOk    => '✅ مضمون — لم يقع في المحظور',
            $check === 'not' && !$decisionOk   => '❌ خرق سقف الأمان — وقع بالضبط بالقرار الممنوع ' . $this->decisionName($scenario['expected_code']),
            default                            => 'ℹ️ إعلامي (قرار النموذج الخام، لا ضمان)',
        };

        $this->line(sprintf(
            '  AI classify → label=%s | category=%s | severity=%s (ثقة %.2f)',
            $review->ai_label ?? '—',
            $review->ai_category ?? '—',
            $review->ai_severity ?? '—',
            (float) ($review->ai_sentiment_confidence ?? 0)
        ));
        $this->line(sprintf(
            '  القرار: %d (%s) — %s',
            $decisionCode ?? -1,
            $this->decisionName($decisionCode),
            $verdictLabel
        ));
        $this->line(sprintf(
            '  التقييم: %.2f → %.2f | التحذيرات: %d → %d | حجب مؤقت: %s',
            $ratingBefore,
            (float) $driver->rating_avg,
            $warningsBefore,
            (int) $driver->active_warnings_count,
            $driver->suspended_until ? $driver->suspended_until : '—'
        ));
        $this->line('  تنبيه أدمن: ' . ($alert ? $alert->alert_type . ' (' . $alert->risk_level . ')' : '—'));
        $this->newLine();

        $rowTag = match (true) {
            $check === 'info'  => ' ℹ️',
            $decisionOk        => ' ✅',
            default            => ' ❌',
        };

        return [
            $scenario['driver_label'],
            sprintf('%s/%s/%s', $review->ai_label, $review->ai_category, $review->ai_severity),
            $this->decisionName($decisionCode) . $rowTag,
            sprintf('%.2f → %.2f', $ratingBefore, (float) $driver->rating_avg),
            $warningsBefore . ' → ' . (int) $driver->active_warnings_count,
            $driver->suspended_until ? 'نعم' : 'لا',
            $alert ? $alert->alert_type : '—',
        ];
    }

    private function decisionName(?int $code): string
    {
        return match ($code) {
            0 => 'NO_ACTION',
            1 => 'REWARD',
            2 => 'MODERATE_VIOLATION',
            3 => 'FORMAL_WARNING',
            4 => 'ADMIN_REVIEW_REQUIRED',
            default => 'UNKNOWN',
        };
    }

    private function makeUser(string $role, string $label): User
    {
        $roleId = $role === 'driver' ? 8 : 7;

        return User::create([
            'full_name'     => self::TAG . ' - ' . $label,
            'email'         => 'ai.scenario.' . Str::random(8) . '@darby.test',
            'phone_number'  => '09' . rand(10000000, 99999999),
            'password_hash' => bcrypt('password123'),
            'role_id'       => $roleId,
            'is_active'     => 1,
            'is_trusted'    => 1,
        ]);
    }

    private function makeDriver(User $driverUser): Driver
    {
        return Driver::create([
            'user_id'        => $driverUser->id,
            'national_id'    => 'NAT' . rand(1000000, 9999999),
            'license_number' => 'LIC' . rand(1000000, 9999999),
            'license_expiry' => now()->addYears(2)->format('Y-m-d'),
            'status'         => 'Approved',
            'rating_avg'     => 5.00,
        ]);
    }

    private function makeSubscription(User $parentUser, Driver $driver, string $status): ActiveSubscription
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

    private function cleanupPreviousRuns(): void
    {
        $userIds = User::where('full_name', 'like', self::TAG . '%')->pluck('id');
        if ($userIds->isEmpty()) {
            return;
        }

        $driverIds = Driver::whereIn('user_id', $userIds)->pluck('id');

        AdminAlert::whereIn('driver_id', $driverIds)->delete();
        DriverReview::whereIn('driver_id', $driverIds)->orWhereIn('parent_id', $userIds)->forceDelete();
        ActiveSubscription::whereHas('subscriptionRequest', fn ($q) => $q->whereIn('parent_id', $userIds))->delete();
        SubscriptionRequest::whereIn('parent_id', $userIds)->delete();
        Driver::whereIn('id', $driverIds)->delete();
        User::whereIn('id', $userIds)->delete();
    }
}
