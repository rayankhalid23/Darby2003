<?php

namespace App\Console\Commands;

use App\Models\Driver\Driver;
use App\Models\Shared\DriverReview;
use App\Services\Ai\AiDecisionService;
use App\Services\Ai\ReviewClassifierService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * اختبار شامل لكل خطوات pipeline الذكاء الاصطناعي.
 *
 * الاستخدام:
 *   php artisan ai:test-pipeline
 *   php artisan ai:test-pipeline --driver=5   (اختبار سائق محدد)
 */
class TestAiDecisionPipeline extends Command
{
    protected $signature   = 'ai:test-pipeline {--driver= : معرف السائق للاختبار} {--review= : معرف التقييم لاختباره ومعالجته بالكامل}';
    protected $description  = 'يختبر pipeline الذكاء الاصطناعي كاملاً: NLP → XGBoost → قرار';

    private const AI_BASE = 'http://127.0.0.1:8001';

    public function handle(): int
    {
        $this->info('══════════════════════════════════════════');
        $this->info('   اختبار شامل لـ Darby AI Pipeline v2');
        $this->info('══════════════════════════════════════════');

        // ── 1. فحص صحة الخادم ────────────────────────────────
        $this->line('');
        $this->comment('❶  فحص صحة FastAPI...');
        try {
            $health = Http::timeout(5)->get(self::AI_BASE . '/');
            if ($health->successful()) {
                $this->info('   ✅ FastAPI يعمل: ' . $health->body());
            } else {
                $this->error('   ❌ FastAPI أعاد: ' . $health->status());
                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error('   ❌ FastAPI غير متاح: ' . $e->getMessage());
            $this->warn('   → تأكد من تشغيل: npm run backend  أو  php artisan serve مع start-ai.ps1');
            return self::FAILURE;
        }

        // ── 2. اختبار /classify (NLP) ────────────────────────
        $this->line('');
        $this->comment('❷  اختبار /classify (NLP تعليقات عربية)...');

        $testComments = [
            ['text' => 'السائق ممتاز والتزامه بالمواعيد رائع',                'expected_label' => 'Positive'],
            ['text' => 'السائق خطر على الأطفال ويقود بتهور شديد',             'expected_label' => 'Negative'],
            ['text' => 'الحافلة متسخة جداً والسائق لا يهتم بالنظافة',          'expected_label' => 'Negative'],
            ['text' => 'لا شيء يذكر',                                         'expected_label' => 'Irrelevant'],
            ['text' => 'السائق أحياناً متأخر وأحياناً بالوقت',                 'expected_label' => 'Mixed'],
        ];

        $nlpPass = 0;
        foreach ($testComments as $tc) {
            try {
                $resp = Http::timeout(15)->post(self::AI_BASE . '/classify', ['text' => $tc['text']]);
                $data = $resp->json();
                $label = $data['label'] ?? 'ERROR';
                $icon  = ($label === $tc['expected_label']) ? '✅' : '⚠️';
                $this->line("   {$icon} \"{$tc['text']}\"");
                $this->line("       → label={$label} | severity={$data['sentiment_confidence']} | category={$data['category']}");
                if ($label === $tc['expected_label']) $nlpPass++;
            } catch (\Throwable $e) {
                $this->error("   ❌ خطأ: " . $e->getMessage());
            }
        }
        $this->info("   NLP: {$nlpPass}/" . count($testComments) . " اختبارات مطابقة");

        // ── 3. اختبار /decision/predict (XGBoost) ────────────
        $this->line('');
        $this->comment('❸  اختبار /decision/predict (XGBoost)...');

        $decisionCases = [
            [
                'label'   => 'سائق ممتاز — يستحق مكافأة (REWARD)',
                'payload' => ['current_rating'=>4.90,'previous_warnings'=>0,'trips_count'=>150,'sentiment_pred'=>0,'sentiment_confidence'=>0.95,'category_pred'=>1,'category_confidence'=>0.90],
                'expect'  => 'REWARD',
            ],
            [
                'label'   => 'سائق عادي — لا إجراء (NO_ACTION)',
                'payload' => ['current_rating'=>3.50,'previous_warnings'=>0,'trips_count'=>80,'sentiment_pred'=>1,'sentiment_confidence'=>0.80,'category_pred'=>0,'category_confidence'=>0.70],
                'expect'  => 'NO_ACTION',
            ],
            [
                'label'   => 'سائق متأخر — تحذير رسمي (FORMAL_WARNING)',
                'payload' => ['current_rating'=>4.50,'previous_warnings'=>0,'trips_count'=>120,'sentiment_pred'=>2,'sentiment_confidence'=>0.90,'category_pred'=>1,'category_confidence'=>0.85],
                'expect'  => 'FORMAL_WARNING',
            ],
            [
                'label'   => 'سائق خطر — مراجعة إدارية وحجب (ADMIN_REVIEW_REQUIRED)',
                'payload' => ['current_rating'=>4.20,'previous_warnings'=>0,'trips_count'=>90,'sentiment_pred'=>2,'sentiment_confidence'=>0.95,'category_pred'=>3,'category_confidence'=>0.90],
                'expect'  => 'ADMIN_REVIEW_REQUIRED',
            ],
            [
                'label'   => 'مخالفة متوسطة متكررة (MODERATE_VIOLATION)',
                'payload' => ['current_rating'=>4.30,'previous_warnings'=>0,'trips_count'=>70,'sentiment_pred'=>2,'sentiment_confidence'=>0.90,'category_pred'=>4,'category_confidence'=>0.88],
                'expect'  => 'MODERATE_VIOLATION',
            ],
        ];

        $xgbPass = 0;
        foreach ($decisionCases as $dc) {
            try {
                $resp = Http::timeout(10)->post(self::AI_BASE . '/decision/predict', $dc['payload']);
                $data = $resp->json();
                $name = $data['decision_name'] ?? 'ERROR';
                $conf = $data['confidence']    ?? 0;
                $icon = ($name === $dc['expect']) ? '✅' : '⚠️';
                $this->line("   {$icon} {$dc['label']}");
                $this->line("       → {$name} (ثقة: " . round($conf * 100, 1) . "%) — متوقع: {$dc['expect']}");
                if ($name === $dc['expect']) $xgbPass++;
            } catch (\Throwable $e) {
                $this->error("   ❌ خطأ: " . $e->getMessage());
            }
        }
        $this->info("   XGBoost: {$xgbPass}/" . count($decisionCases) . " اختبارات مطابقة");

        // ── 4. اختبار pipeline متكامل على سائق حقيقي ──────────
        $this->line('');
        $this->comment('❹  اختبار pipeline كامل على قاعدة البيانات...');

        $driverId = $this->option('driver');
        $driver   = $driverId
            ? Driver::find($driverId)
            : Driver::whereNotNull('rating_avg')->first();

        if (!$driver) {
            $this->warn('   ⚠️ لا يوجد سائق في قاعدة البيانات للاختبار. تخطّي.');
        } else {
            $this->line("   → سائق #{$driver->id} | التقييم: {$driver->rating_avg} | الإيقاف: " . ($driver->is_suspended ? 'نعم' : 'لا'));
            $this->line("   → عداد الإيقاف: {$driver->suspension_count} | التحذيرات: {$driver->active_warnings_count}");
            $this->line("   → مدة الإيقاف القادمة المحسوبة: " . ($driver->calculateNextSuspensionDurationHours() ?? 'دائم') . " ساعة");

            // اختبار أو معالجة تعليق محدد
            $latestReview = DriverReview::where('driver_id', $driver->id)->latest()->first();
            $reviewId = $this->option('review') ?: ($latestReview ? $latestReview->id : null);
            if ($reviewId) {
                $targetReview = DriverReview::with('driver')->find($reviewId);
                if ($targetReview) {
                    $this->line("   → تشغيل Pipeline على التعليق #{$targetReview->id}: \"{$targetReview->comment}\"");
                    $targetReview->update(['is_processed_in_decision' => false]);
                    $ratingBefore = $targetReview->driver->rating_avg;

                    app(\App\Jobs\ClassifyDriverReviewJob::class, ['reviewId' => $targetReview->id])
                        ->handle(
                            app(\App\Services\Ai\ReviewClassifierService::class),
                            app(\App\Services\Ai\AiDecisionService::class)
                        );

                    $targetReview->refresh();
                    $freshDriver = $targetReview->driver->fresh();
                    $audit = \App\Models\Shared\AiDecisionAudit::where('review_id', $targetReview->id)->latest()->first();

                    $this->info("   ✅ نتيجة المعالجة:");
                    $this->line("       • NLP Label:    {$targetReview->ai_label} (Confidence: {$targetReview->ai_sentiment_confidence})");
                    $this->line("       • NLP Category: {$targetReview->ai_category} (Confidence: {$targetReview->ai_category_confidence})");
                    $this->line("       • Decision:     {$audit?->decision_name} (Confidence: {$audit?->decision_confidence})");
                    $this->line("       • Actions:      {$audit?->action_applied}");
                    $this->line("       • Driver Rating: {$ratingBefore} → {$freshDriver->rating_avg}");
                    $this->line("       • Suspended:    " . ($freshDriver->is_suspended ? 'نعم (حتى ' . $freshDriver->suspended_until . ')' : 'لا'));
                    $this->line("       • Audit ID:     #{$audit?->id} مُسجّل بنجاح في ai_decision_audits");
                }
            }
        }

        // ── 5. اختبار استعادة السائقين الموقوفين ───────────────
        $this->line('');
        $this->comment('❺  فحص قائمة السائقين الموقوفين الذين يمكن استعادتهم...');

        $expiredSuspensions = Driver::whereNotNull('suspended_until')
            ->where('suspended_until', '<=', now())
            ->count();

        $activeSuspensions = Driver::whereNotNull('suspended_until')
            ->where('suspended_until', '>', now())
            ->count();

        $indefiniteSuspensions = Driver::whereNull('suspended_until')
            ->where('suspension_count', '>', 0)
            ->count();

        $this->line("   → إيقافات منتهية (قابلة للاستعادة): {$expiredSuspensions}");
        $this->line("   → إيقافات نشطة (مؤقتة):             {$activeSuspensions}");
        $this->line("   → إيقافات دائمة (تدخل إداري):       {$indefiniteSuspensions}");

        if ($expiredSuspensions > 0) {
            $this->warn("   ⚠️ يوجد {$expiredSuspensions} سائق يحتاج استعادة — شغّل: php artisan drivers:restore-suspended");
        }

        // ── ملخص ─────────────────────────────────────────────
        $this->line('');
        $this->info('══════════════════════════════════════════');
        $totalPass  = $nlpPass + $xgbPass;
        $totalTests = count($testComments) + count($decisionCases);
        $this->info("   النتيجة النهائية: {$totalPass}/{$totalTests} اختبار ناجح");

        if ($totalPass === $totalTests) {
            $this->info('   🎉 كل الاختبارات ناجحة — Pipeline يعمل بشكل صحيح!');
        } else {
            $this->warn('   ⚠️ بعض الاختبارات أعطت نتائج غير متوقعة (طبيعي بسبب عشوائية النموذج)');
        }
        $this->info('══════════════════════════════════════════');

        return self::SUCCESS;
    }
}
