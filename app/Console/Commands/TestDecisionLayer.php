<?php

namespace App\Console\Commands;

use App\Models\Admin\AdminAlert;
use App\Models\Driver\Driver;
use App\Models\Shared\AiDecisionAudit;
use App\Models\Shared\DriverReview;
use App\Models\User;
use App\Services\Ai\AiDecisionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TestDecisionLayer extends Command
{
    protected $signature = 'ai:test-decision-layer';
    protected $description = 'اختبار شامل ومفصل لطبقة القرار وحالاتها التشغيلية الخمس وقاعدة التعافي الذاتي';

    public function handle(AiDecisionService $decisionService): int
    {
        $this->info('========================================================');
        $this->info('   اختبار طبقة القرار الذكية لمنصة دربي (Darby Decision Layer)');
        $this->info('========================================================');

        // 1. تجهيز أولياء الأمور وحساب السائق التجريبي
        $parentIds = [];
        for ($i = 1; $i <= 10; $i++) {
            $email = "test_parent_{$i}@darby-test.local";
            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'full_name'    => "ولي أمر تجريبي {$i}",
                    'phone_number' => "09109990" . str_pad($i, 2, '0', STR_PAD_LEFT),
                    'password'     => Hash::make('password123'),
                    'role_id'      => 7,
                ]
            );
            $parentIds[$i] = $user->id;
        }

        $driverUser = User::firstOrCreate(
            ['email' => 'test_driver_decision@darby-test.local'],
            [
                'full_name'    => 'سائق تجريبي لطبقة القرار',
                'phone_number' => '0929999888',
                'password'     => Hash::make('password123'),
                'role_id'      => 8,
            ]
        );

        $testDriver = Driver::firstOrCreate(
            ['user_id' => $driverUser->id],
            [
                'rating_avg'            => 4.00,
                'active_warnings_count' => 0,
                'suspension_count'      => 0,
                'suspended_until'       => null,
            ]
        );
        $testDriverId = $testDriver->id;

        $cleanup = function () use ($testDriverId) {
            DriverReview::where('driver_id', $testDriverId)->delete();
            AdminAlert::where('driver_id', $testDriverId)->delete();
            AiDecisionAudit::where('driver_id', $testDriverId)->delete();
            Driver::where('id', $testDriverId)->update([
                'rating_avg'            => 4.00,
                'active_warnings_count' => 0,
                'suspension_count'      => 0,
                'suspended_until'       => null,
            ]);
        };

        $allPassed = true;

        // -------------------------------------------------------------
        // الحالة الأولى: REWARD (كود 1) — الآن قرار النموذج الخام، إعلامي وليس صارماً
        // -------------------------------------------------------------
        $this->line("\n❶ الحالة الأولى: المكافأة والتشجيع (REWARD - كود 1)");
        $this->line("   لا توجد قاعدة نسب هنا بعد الآن — القرار بالكامل لنموذج XGBoost الخام.");
        $this->line("   هذا الفحص إعلامي (لا يُسقط الاختبار)، يعرض فقط ماذا يقرر النموذج فعلياً لتعليق إيجابي على سائق نظيف السجل.");
        $cleanup();
        $testDriver->update(['rating_avg' => 4.00, 'active_warnings_count' => 2]);

        $r1 = DriverReview::create([
            'driver_id'               => $testDriverId,
            'parent_id'               => $parentIds[1],
            'rating'                  => 5,
            'comment'                 => 'سائق رائع وممتاز وملتزم جدا بالمواعيد وأسلوبه محترم',
            'ai_label'                => 'Positive',
            'ai_category'             => 'Punctuality',
            'ai_severity'             => 0,
            'ai_sentiment_confidence' => 0.96,
            'ai_category_confidence'  => 0.98,
            'is_processed_in_decision'=> false,
            'created_at'              => now(),
        ]);

        $res1 = $decisionService->evaluateDriverDecision($testDriver->fresh(), $r1);
        $testDriver->refresh();

        $this->comment("   ℹ️ قرار النموذج: {$res1['decision_name']} | التقييم: 4.00 ➔ {$testDriver->rating_avg} | التحذيرات: 2 ➔ {$testDriver->active_warnings_count} | الحجب: " . ($testDriver->suspended_until ? 'نعم' : 'لا'));

        // -------------------------------------------------------------
        // الحالة الثانية: ADMIN_REVIEW_REQUIRED (كود 4)
        // -------------------------------------------------------------
        $this->line("\n❷ الحالة الثانية: التدخل الإداري الحرج (ADMIN_REVIEW_REQUIRED - كود 4)");
        $this->line("   [سقف أمان ثابت] الشرط: بلاغ سلامة حرج (Safety) أو خطورة قصوى (severity=2) ➔ حجب فوري وتدخل الأدمن، يتجاوز أي قرار للنموذج.");
        $cleanup();
        $testDriver->update(['rating_avg' => 4.00]);

        $r2 = DriverReview::create([
            'driver_id'               => $testDriverId,
            'parent_id'               => $parentIds[2],
            'rating'                  => 1,
            'comment'                 => 'السائق يقود بسرعة جنونية وتجاوز الإشارة الحمراء وكاد يتسبب في حادث للطلاب',
            'ai_label'                => 'Negative',
            'ai_category'             => 'Safety',
            'ai_severity'             => 2,
            'ai_sentiment_confidence' => 0.98,
            'ai_category_confidence'  => 0.99,
            'is_processed_in_decision'=> false,
            'created_at'              => now(),
        ]);

        $res2 = $decisionService->evaluateDriverDecision($testDriver->fresh(), $r2);
        $testDriver->refresh();
        $alert2 = AdminAlert::where('driver_id', $testDriverId)->where('risk_level', 'CRITICAL')->first();

        $t2_pass = ($res2['decision_code'] === AiDecisionService::CODE_ADMIN_REVIEW_REQUIRED)
            && (abs($testDriver->rating_avg - 3.60) < 0.02)
            && ($testDriver->suspended_until !== null && $testDriver->suspended_until->isFuture())
            && ($alert2 !== null);

        if ($t2_pass) {
            $this->info("   ✅ نجاح: القرار ADMIN_REVIEW_REQUIRED (-10%) | التقييم: 4.00 ➔ {$testDriver->rating_avg} | حجب وقائي 24 ساعة حتى: {$testDriver->suspended_until->toDateTimeString()} | تنبيه إدارة عاجل: نعم");
        } else {
            $this->error("   ❌ فشل: القرار كود {$res2['decision_code']} | التقييم: {$testDriver->rating_avg}");
            $allPassed = false;
        }

        // -------------------------------------------------------------
        // الحالة الثالثة: MODERATE_VIOLATION (كود 2)
        // -------------------------------------------------------------
        $this->line("\n❸ الحالة الثالثة: مخالفة متوسطة متكررة (MODERATE_VIOLATION - كود 2)");
        $this->line("   [سقف أمان ثابت] الشرط: تكرار الشكوى في نفس التصنيف من أكثر من ولي أمر مختلف (>= 2) في 15 يوماً، يتجاوز أي قرار للنموذج.");
        $cleanup();
        $testDriver->update(['rating_avg' => 4.00, 'active_warnings_count' => 0]);

        // ولي أمر 3 بالأمس
        DriverReview::create([
            'driver_id'               => $testDriverId,
            'parent_id'               => $parentIds[3],
            'rating'                  => 2,
            'comment'                 => 'السائق أسلوبه جاف ويدخن أثناء القيادة',
            'ai_label'                => 'Negative',
            'ai_category'             => 'Behavior',
            'ai_severity'             => 1,
            'ai_sentiment_confidence' => 0.90,
            'ai_category_confidence'  => 0.88,
            'is_processed_in_decision'=> true,
            'created_at'              => now()->subDay(),
        ]);

        // ولي أمر 4 اليوم في نفس التصنيف (Behavior)
        $r3_2 = DriverReview::create([
            'driver_id'               => $testDriverId,
            'parent_id'               => $parentIds[4],
            'rating'                  => 2,
            'comment'                 => 'معاملة السائق سيئة ويتشاجر مع أولياء الأمور',
            'ai_label'                => 'Negative',
            'ai_category'             => 'Behavior',
            'ai_severity'             => 1,
            'ai_sentiment_confidence' => 0.92,
            'ai_category_confidence'  => 0.91,
            'is_processed_in_decision'=> false,
            'created_at'              => now(),
        ]);

        $res3 = $decisionService->evaluateDriverDecision($testDriver->fresh(), $r3_2);
        $testDriver->refresh();
        $alert3 = AdminAlert::where('driver_id', $testDriverId)->where('risk_level', 'HIGH')->first();

        $t3_pass = ($res3['decision_code'] === AiDecisionService::CODE_MODERATE_VIOLATION)
            && (abs($testDriver->rating_avg - 3.80) < 0.02)
            && ($testDriver->suspended_until !== null && $testDriver->suspended_until->isFuture())
            && ($alert3 !== null);

        if ($t3_pass) {
            $this->info("   ✅ نجاح: القرار MODERATE_VIOLATION (-5%) | التقييم: 4.00 ➔ {$testDriver->rating_avg} | حجب بحث تأديبي 24 ساعة: نعم | تنبيه إدارة عالي: نعم");
        } else {
            $this->error("   ❌ فشل: القرار كود {$res3['decision_code']} | التقييم: {$testDriver->rating_avg}");
            $allPassed = false;
        }

        // -------------------------------------------------------------
        // الحالة الرابعة: FORMAL_WARNING (كود 3)
        // -------------------------------------------------------------
        $this->line("\n❹ الحالة الرابعة: إنذار رسمي (FORMAL_WARNING - كود 3)");
        $this->line("   لا توجد قاعدة نسب هنا بعد الآن — القرار بالكامل لنموذج XGBoost الخام (دون تكرار تصنيف ولا مساس بالسلامة، فلا سقف أمان يتدخل).");
        $this->line("   هذا الفحص إعلامي (لا يُسقط الاختبار).");
        $cleanup();
        $testDriver->update(['rating_avg' => 4.00, 'active_warnings_count' => 0]);

        // ولي أمر 5: إيجابي
        DriverReview::create([
            'driver_id'               => $testDriverId,
            'parent_id'               => $parentIds[5],
            'rating'                  => 5,
            'comment'                 => 'خدمة جيدة عموما',
            'ai_label'                => 'Positive',
            'ai_category'             => 'General',
            'ai_severity'             => 0,
            'ai_sentiment_confidence' => 0.85,
            'ai_category_confidence'  => 0.80,
            'is_processed_in_decision'=> true,
            'created_at'              => now()->subDays(2),
        ]);

        // ولي أمر 6: شكوى تأخير (Punctuality) -> 1 سلبي من أصل 2 = 50% (>= 40%)
        $r4_2 = DriverReview::create([
            'driver_id'               => $testDriverId,
            'parent_id'               => $parentIds[6],
            'rating'                  => 2,
            'comment'                 => 'السائق تأخر ربع ساعة صباح اليوم دون إبلاغ مسبق',
            'ai_label'                => 'Negative',
            'ai_category'             => 'Punctuality',
            'ai_severity'             => 0,
            'ai_sentiment_confidence' => 0.80,
            'ai_category_confidence'  => 0.90,
            'is_processed_in_decision'=> false,
            'created_at'              => now(),
        ]);

        $res4 = $decisionService->evaluateDriverDecision($testDriver->fresh(), $r4_2);
        $testDriver->refresh();

        $this->comment("   ℹ️ قرار النموذج: {$res4['decision_name']} | التقييم: 4.00 ➔ {$testDriver->rating_avg} | التحذيرات: 0 ➔ {$testDriver->active_warnings_count} | الحجب: " . ($testDriver->suspended_until ? 'نعم' : 'لا'));

        // -------------------------------------------------------------
        // الحالة الخامسة: NO_ACTION (كود 0)
        // -------------------------------------------------------------
        $this->line("\n❺ الحالة الخامسة: الاستقرار / لا إجراء (NO_ACTION - كود 0)");
        $this->line("   لا توجد قاعدة نسب هنا بعد الآن — القرار بالكامل لنموذج XGBoost الخام لتعليق سلبي بسيط وسط أغلبية إيجابية.");
        $this->line("   هذا الفحص إعلامي (لا يُسقط الاختبار).");
        $cleanup();
        $testDriver->update(['rating_avg' => 4.00, 'active_warnings_count' => 0]);

        // 3 إيجابي
        foreach ([7, 8, 9] as $idx) {
            DriverReview::create([
                'driver_id'               => $testDriverId,
                'parent_id'               => $parentIds[$idx],
                'rating'                  => 5,
                'comment'                 => 'سائق ممتاز ملتزم',
                'ai_label'                => 'Positive',
                'ai_category'             => 'General',
                'ai_severity'             => 0,
                'ai_sentiment_confidence' => 0.9,
                'ai_category_confidence'  => 0.9,
                'is_processed_in_decision'=> true,
                'created_at'              => now()->subDays(3),
            ]);
        }

        // 1 سلبي بسيط (1 من 4 = 25% < 40%)
        $r5 = DriverReview::create([
            'driver_id'               => $testDriverId,
            'parent_id'               => $parentIds[10],
            'rating'                  => 2,
            'comment'                 => 'تأخير بسيط جدا دقائق معدودة',
            'ai_label'                => 'Negative',
            'ai_category'             => 'Punctuality',
            'ai_severity'             => 0,
            'ai_sentiment_confidence' => 0.8,
            'ai_category_confidence'  => 0.8,
            'is_processed_in_decision'=> false,
            'created_at'              => now(),
        ]);

        $res5 = $decisionService->evaluateDriverDecision($testDriver->fresh(), $r5);
        $testDriver->refresh();

        $this->comment("   ℹ️ قرار النموذج: {$res5['decision_name']} | التقييم: 4.00 ➔ {$testDriver->rating_avg} | التحذيرات: 0 ➔ {$testDriver->active_warnings_count} | الحجب: " . ($testDriver->suspended_until ? 'نعم' : 'لا'));

        // -------------------------------------------------------------
        // الحالة السادسة: التعافي الذاتي (30 Days Rule)
        // -------------------------------------------------------------
        $this->line("\n❻ قاعدة التعافي الذاتي: إسقاط التحذيرات بعد 30 يوماً (Self-Healing)");
        $this->line("   الشرط: مرور 30 يوماً كاملة دون أي شكوى جديدة في نفس التصنيف الذي نال عليه السائق تحذيراً.");
        $cleanup();

        $oldReviewId = DB::table('driver_reviews')->insertGetId([
            'driver_id'               => $testDriverId,
            'parent_id'               => $parentIds[1],
            'rating'                  => 2,
            'comment'                 => 'شكوى قديمة سابقة من 35 يوماً',
            'ai_label'                => 'Negative',
            'ai_category'             => 'Punctuality',
            'ai_severity'             => 1,
            'is_processed_in_decision'=> true,
            'created_at'              => now()->subDays(35),
            'updated_at'              => now()->subDays(35),
        ]);

        DB::table('ai_decision_audits')->insert([
            'driver_id'       => $testDriverId,
            'review_id'       => $oldReviewId,
            'current_rating'  => 4.00,
            'decision_code'   => AiDecisionService::CODE_FORMAL_WARNING,
            'decision_name'   => 'FORMAL_WARNING',
            'action_applied'  => 'warning_incremented',
            'action_details'  => json_encode(['category_label' => 'Punctuality']),
            'created_at'      => now()->subDays(35),
            'updated_at'      => now()->subDays(35),
        ]);

        $testDriver->update(['active_warnings_count' => 1]);

        $healedCount = $decisionService->applySelfHealing($testDriver->fresh(), 30);
        $testDriver->refresh();

        $t6_pass = ($healedCount === 1) && ($testDriver->active_warnings_count === 0);

        if ($t6_pass) {
            $this->info("   ✅ نجاح: سقط التحذير تلقائياً لمرور 35 يوماً دون شكاوى جديدة في (Punctuality) | عداد التحذيرات استُرجع إلى: {$testDriver->active_warnings_count}");
        } else {
            $this->error("   ❌ فشل التعافي الذاتي: تم تعافي {$healedCount} | العداد: {$testDriver->active_warnings_count}");
            $allPassed = false;
        }

        // -------------------------------------------------------------
        // الحالة السابعة: بلاغ سلامة نشط يمنع المكافأة (سقف أمان جديد)
        // -------------------------------------------------------------
        $this->line("\n❼ [سقف أمان ثابت] بلاغ سلامة نشط يمنع أي مكافأة رشّحها النموذج");
        $this->line("   الشرط: بلاغ Safety ضمن نافذة 15 يوماً + تعليق جديد إيجابي جداً ⇒ لا يمكن أن يكون القرار REWARD.");
        $cleanup();
        $testDriver->update(['rating_avg' => 4.00, 'active_warnings_count' => 0]);

        // بلاغ سلامة سابق قبل 5 أيام (لم يُحل بعد)
        DriverReview::create([
            'driver_id'               => $testDriverId,
            'parent_id'               => $parentIds[1],
            'rating'                  => 1,
            'comment'                 => 'السائق كان يقود بتهور شديد وكاد يتسبب بحادث',
            'ai_label'                => 'Negative',
            'ai_category'             => 'Safety',
            'ai_severity'             => 2,
            'ai_sentiment_confidence' => 0.97,
            'ai_category_confidence'  => 0.95,
            'is_processed_in_decision'=> true,
            'created_at'              => now()->subDays(5),
        ]);

        // تعليق إيجابي جداً اليوم من ولي أمر آخر
        $r7 = DriverReview::create([
            'driver_id'               => $testDriverId,
            'parent_id'               => $parentIds[2],
            'rating'                  => 5,
            'comment'                 => 'سائق رائع جداً واليوم كان ممتازاً بكل شيء',
            'ai_label'                => 'Positive',
            'ai_category'             => 'General',
            'ai_severity'             => 0,
            'ai_sentiment_confidence' => 0.97,
            'ai_category_confidence'  => 0.95,
            'is_processed_in_decision'=> false,
            'created_at'              => now(),
        ]);

        $res7 = $decisionService->evaluateDriverDecision($testDriver->fresh(), $r7);
        $testDriver->refresh();

        $t7_pass = ($res7['decision_code'] !== AiDecisionService::CODE_REWARD);

        if ($t7_pass) {
            $this->info("   ✅ نجاح: القرار {$res7['decision_name']} (ليس REWARD رغم أن التعليق إيجابي 100%) | التقييم: {$testDriver->rating_avg}");
        } else {
            $this->error("   ❌ فشل: النموذج كافأ سائقاً رغم بلاغ سلامة نشط لم يُحل بعد | التقييم: {$testDriver->rating_avg}");
            $allPassed = false;
        }

        // تنظيف البيانات المؤقتة
        $cleanup();

        $this->line("\n========================================================");
        if ($allPassed) {
            $this->info("   🌟 اكتمل الاختبار بنجاح 100%: جميع الحالات والقيود تعمل بدقة متناهية!");
            $this->line("========================================================\n");
            return self::SUCCESS;
        }

        $this->error("   ⚠️ بعض الحالات لم تنجح.");
        $this->line("========================================================\n");
        return self::FAILURE;
    }
}
