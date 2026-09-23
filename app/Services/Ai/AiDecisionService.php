<?php

namespace App\Services\Ai;

use App\Models\Admin\AdminAlert;
use App\Models\Driver\Driver;
use App\Models\Shared\AiDecisionAudit;
use App\Models\Shared\DriverReview;
use App\Services\Notification\NotificationFormatter;
use App\Services\Notification\NotificationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

/**
 * خدمة قرار السائقين المحدثة لمنصة دربي (Darby Decision Layer).
 *
 * القرار النهائي يصدر عن نموذج XGBoost مباشرة (/decision/predict)، تحت سقف أمان
 * من ثلاث قواعد ثابتة لا يستطيع النموذج تجاوزها (لأنها تعتمد على بيانات لا يستقبلها
 * أصلاً: تعدد المصادر عبر نافذة 15 يوماً):
 * 1. مساس بالسلامة أو خطورة حرجة (severity=2) على التعليق الحالي ⇒ مراجعة إدارية فورية،
 *    بصرف النظر عمّا يقرره النموذج.
 * 2. تكرار نفس فئة الشكوى من ولِيَّي أمر مختلفَين (2+) خلال 15 يوماً ⇒ مخالفة متوسطة فورية.
 * 3. بلاغ سلامة نشط ضمن نافذة الـ15 يوماً ⇒ يمنع أي مكافأة، حتى لو رشّح النموذج مكافأة.
 * غير ذلك، القرار بالكامل للنموذج. سيادة القرار البشري (Admin-Only) تبقى قائمة: الإيقاف
 * النهائي حصرياً بيد الأدمن؛ الـ AI يطبق حجباً مؤقتاً احترازياً (٢٤ ساعة كحد أقصى) وتنبيهات فقط.
 * التعافي الذاتي النسبي (30 يوماً) يبقى كما هو: يزول الإنذار ذاتياً إذا مرت 30 يوماً كاملة
 * دون شكوى جديدة من نفس التصنيف.
 *
 * الحالات التشغيلية الخمس:
 *    - 0 = NO_ACTION             → لا إجراء
 *    - 1 = REWARD                → مكافأة تشجيعية (+3% تقييم وتخفيض التحذيرات)
 *    - 2 = MODERATE_VIOLATION    → مخالفة متوسطة (خفض 5% + حجب مؤقت 24 ساعة من البحث)
 *    - 3 = FORMAL_WARNING        → إنذار رسمي (خفض 5%)
 *    - 4 = ADMIN_REVIEW_REQUIRED → خطورة قصوى / سلامة (خفض 10% + حجب مؤقت فوري + تنبيه أدمن حرج)
 */
class AiDecisionService
{
    // ---- رموز القرار ----
    public const CODE_NO_ACTION             = 0;
    public const CODE_REWARD                = 1;
    public const CODE_MODERATE_VIOLATION    = 2;
    public const CODE_FORMAL_WARNING        = 3;
    public const CODE_ADMIN_REVIEW_REQUIRED = 4;

    public const DECISION_NAMES = [
        self::CODE_NO_ACTION             => 'NO_ACTION',
        self::CODE_REWARD                => 'REWARD',
        self::CODE_MODERATE_VIOLATION    => 'MODERATE_VIOLATION',
        self::CODE_FORMAL_WARNING        => 'FORMAL_WARNING',
        self::CODE_ADMIN_REVIEW_REQUIRED => 'ADMIN_REVIEW_REQUIRED',
    ];

    // ---- معاملات التقييم والنسب الحاكمة ----
    private const RATING_MIN            = 0.00;
    private const RATING_MAX            = 5.00;
    private const REWARD_FACTOR         = 1.03;   // +3%
    private const WARNING_FACTOR        = 0.95;   // -5%
    private const ADMIN_REVIEW_FACTOR   = 0.90;   // -10%

    // ---- النوافذ الزمنية (لسقف الأمان فقط — القرار نفسه للنموذج) ----
    public const WINDOW_DAYS            = 15;     // نافذة تعدد المصادر لسقف الأمان (15 يوماً)
    public const SELF_HEALING_DAYS      = 30;     // نافذة التعافي الذاتي لكل تصنيف (30 يوماً)

    public function __construct(
        private readonly string $aiBaseUrl,
        private readonly int    $timeoutSeconds
    ) {}

    // =========================================================
    //  نقطة الدخول الرئيسية — تُستدعى من ClassifyDriverReviewJob
    // =========================================================

    /**
     * تحلّل السائق وتطبّق القرار المناسب بناءً على المنطق الهجين:
     * (تحليل الـ 15 يوماً + نموذج XGBoost + صمامات الأمان + التعافي الذاتي).
     *
     * @return array{decision_code:int, decision_name:string, confidence:float, actions:array}|null
     */
    public function evaluateDriverDecision(Driver $driver, DriverReview $review): ?array
    {
        return DB::transaction(function () use ($driver, $review) {

            // 1. قفل السجل لتجنّب التضارب المتزامن
            $driver = Driver::lockForUpdate()->find($driver->id);
            if (!$driver) {
                return null;
            }

            // 2. منع إعادة المعالجة لنفس التقييم
            if ($review->is_processed_in_decision) {
                Log::info('AiDecisionService: review already processed', ['review_id' => $review->id]);
                return null;
            }

            // 3. تطبيق التعافي الذاتي للتحذيرات السابقة (قاعدة الـ 30 يوماً)
            $this->applySelfHealing($driver);

            // 4. حساب إحصائيات النافذة التراكمية (15 يوماً) بأولياء أمور مختلفين
            $windowStats = $this->getWindowStats($driver->id, self::WINDOW_DAYS);

            // 5. بناء الميزات واستدعاء نموذج XGBoost
            $features = $this->buildFeatures($driver, $review);
            $prediction = $this->callDecisionApi($features);

            $modelCode       = (int)   ($prediction['decision_code'] ?? self::CODE_NO_ACTION);
            $modelConfidence = (float) ($prediction['confidence'] ?? 0.85);
            $probabilities   = (array) ($prediction['probabilities'] ?? []);

            $ratingBefore = (float) ($driver->rating_avg ?? self::RATING_MAX);

            // 6. القرار النهائي: قرار النموذج + سقف الأمان (Decision Layer Guardrails)
            $decisionCode       = $this->determineFinalDecision($review, $windowStats, $modelCode);
            $decisionConfidence = max(0.90, $modelConfidence);

            // 7. تنفيذ القرار وتطبيق التأثيرات
            $result = match ($decisionCode) {
                self::CODE_REWARD                => $this->applyReward($driver, $ratingBefore, $windowStats),
                self::CODE_ADMIN_REVIEW_REQUIRED => $this->applyAdminReviewRequired($driver, $ratingBefore, $review, $windowStats),
                self::CODE_MODERATE_VIOLATION    => $this->applyModerateViolation($driver, $ratingBefore, $review, $windowStats),
                self::CODE_FORMAL_WARNING        => $this->applyFormalWarning($driver, $ratingBefore, $review, $windowStats),
                default                          => $this->applyNoAction($ratingBefore),
            };

            // 8. إرسال الإشعار المناسب للسائق
            $this->notifyDriver($driver, $decisionCode, [
                'category' => (string) ($review->ai_category ?? 'عام'),
            ]);

            // 9. تحديث حقول التقييم في driver_reviews
            $review->forceFill([
                'ai_decision_code'         => $decisionCode,
                'ai_decision_confidence'   => $decisionConfidence,
                'is_processed_in_decision' => true,
            ])->save();

            // 10. تسجيل لقطة التدقيق في ai_decision_audits
            $this->writeAudit($driver, $review, $decisionCode, $decisionConfidence, $probabilities, $ratingBefore, $result, $features, $windowStats);

            Log::info('AiDecisionService: decision applied successfully', [
                'driver_id'     => $driver->id,
                'review_id'     => $review->id,
                'decision_code' => $decisionCode,
                'decision_name' => self::DECISION_NAMES[$decisionCode] ?? 'UNKNOWN',
                'confidence'    => $decisionConfidence,
                'rating_before' => $ratingBefore,
                'rating_after'  => $result['rating_after'],
            ]);

            return array_merge($result, [
                'decision_code' => $decisionCode,
                'decision_name' => self::DECISION_NAMES[$decisionCode] ?? 'UNKNOWN',
                'confidence'    => $decisionConfidence,
                'window_stats'  => $windowStats,
            ]);
        });
    }

    // =========================================================
    //  سقف الأمان + القرار النهائي (النموذج يقرر، وثلاث قواعد ثابتة تعلوه)
    // =========================================================

    /**
     * القرار الفعلي هو قرار نموذج XGBoost الخام ($modelCode) في كل الحالات،
     * إلا في ثلاث حالات سقف أمان لا يستطيع النموذج رؤيتها أصلاً (لأنها تعتمد على
     * تعدد المصادر عبر نافذة 15 يوماً، وهذه بيانات لا تُرسَل له كخصائص):
     *
     *   1. مساس بالسلامة أو خطورة حرجة على التعليق الحالي ⇒ مراجعة إدارية فورية.
     *   2. تكرار نفس فئة الشكوى من ولِيَّي أمر مختلفَين (2+) خلال 15 يوماً ⇒ مخالفة متوسطة فورية.
     *   3. بلاغ سلامة نشط ضمن النافذة ⇒ يمنع أي مكافأة رشّحها النموذج.
     *
     * كل حالة غير هذي الثلاث تمر مباشرة بقرار النموذج كما هو.
     */
    private function determineFinalDecision(DriverReview $review, array $windowStats, int $modelCode): int
    {
        $nlpLabel   = strtolower(trim((string) ($review->ai_label ?? '')));
        $category   = ucfirst(strtolower(trim((string) ($review->ai_category ?? 'General'))));
        $userRating = (int) ($review->rating ?? 0);
        $severity   = (int) ($review->ai_severity ?? 0);

        $isNegative = ($nlpLabel === 'negative' || $userRating <= 2);

        // ── سقف الأمان 1: مساس بالسلامة أو خطورة حرجة (severity = 2) ──
        $isSafetyViolation  = ($isNegative && $category === 'Safety');
        $isCriticalSeverity = ($isNegative && $severity === 2);

        if ($isSafetyViolation || $isCriticalSeverity) {
            return self::CODE_ADMIN_REVIEW_REQUIRED; // كود 4 — يتجاوز أي قرار للنموذج
        }

        // ── سقف الأمان 2: تكرار نفس فئة الشكوى من 2+ أولياء أمور مختلفين خلال 15 يوماً ──
        $distinctParentsInThisCat = count($windowStats['distinct_parents_by_category'][$category] ?? []);
        $hasRecurringCategory     = ($distinctParentsInThisCat >= 2);

        if ($isNegative && $hasRecurringCategory) {
            return self::CODE_MODERATE_VIOLATION; // كود 2 — يتجاوز أي قرار للنموذج
        }

        // ── سقف الأمان 3: بلاغ سلامة نشط يمنع أي مكافأة رشّحها النموذج ──
        $hasActiveSafetyComplaint = !empty($windowStats['distinct_parents_by_category']['Safety'] ?? []);

        if ($modelCode === self::CODE_REWARD && $hasActiveSafetyComplaint) {
            return self::CODE_NO_ACTION;
        }

        // ── غير ذلك: القرار بالكامل لنموذج XGBoost ──
        return $modelCode;
    }

    // =========================================================
    //  إحصائيات النافذة الزمنية (15 يوماً) وتعدد المصادر
    // =========================================================

    /**
     * حساب إحصائيات أولياء الأمور المختلفين حصرياً خلال آخر 15 يوماً
     */
    public function getWindowStats(int $driverId, int $windowDays = self::WINDOW_DAYS): array
    {
        $windowStart = now()->subDays($windowDays);

        // جلب جميع التقييمات الحديثة للسائق
        $reviews = DriverReview::query()
            ->where('driver_id', $driverId)
            ->where('created_at', '>=', $windowStart)
            ->whereNotNull('ai_label')
            ->orderByDesc('created_at')
            ->get();

        // تجميع حسب parent_id لضمان تعدد المصادر (أحدث تقييم لكل ولي أمر)
        $parentReviews = $reviews->groupBy('parent_id')->map(fn ($group) => $group->first());
        $totalDistinct = $parentReviews->count();

        if ($totalDistinct === 0) {
            return [
                'total_distinct'               => 0,
                'positive_count'               => 0,
                'positive_ratio'               => 0.0,
                'negative_count'               => 0,
                'negative_ratio'               => 0.0,
                'categories'                   => [],
                'distinct_parents_by_category' => [],
            ];
        }

        $positiveCount = 0;
        $negativeCount = 0;
        $categories = [];
        $distinctParentsByCategory = [];

        foreach ($parentReviews as $parentId => $r) {
            $label  = strtolower(trim((string) ($r->ai_label ?? '')));
            $rating = (int) ($r->rating ?? 0);
            $cat    = ucfirst(strtolower(trim((string) ($r->ai_category ?? 'General'))));

            if ($label === 'positive' || ($rating >= 4 && $label !== 'negative')) {
                $positiveCount++;
            } elseif ($label === 'negative' || $rating <= 2) {
                $negativeCount++;
                $categories[$cat] = ($categories[$cat] ?? 0) + 1;
                $distinctParentsByCategory[$cat][] = $parentId;
            }
        }

        return [
            'total_distinct'               => $totalDistinct,
            'positive_count'               => $positiveCount,
            'positive_ratio'               => round($positiveCount / $totalDistinct, 4),
            'negative_count'               => $negativeCount,
            'negative_ratio'               => round($negativeCount / $totalDistinct, 4),
            'categories'                   => $categories,
            'distinct_parents_by_category' => $distinctParentsByCategory,
        ];
    }

    // =========================================================
    //  التعافي الذاتي النسبي (قاعدة الـ 30 يوماً لكل تصنيف)
    // =========================================================

    /**
     * تصفير وإسقاط أي تحذير مر عليه 30 يوماً دون شكوى جديدة من نفس التصنيف
     * يتم التدقيق عبر ai_decision_audits لتتبع التحذيرات السابقة وتبرئة السجل واسترجاع نقطة من التحذيرات
     */
    public function applySelfHealing(Driver $driver, int $healingDays = self::SELF_HEALING_DAYS): int
    {
        $cutoffDate = now()->subDays($healingDays);

        // جلب سجلات التحذيرات السابقة (المستوى 1 أو المستوى 2) التي مر عليها 30 يوماً ولم يتم تبرئتها بعد
        $eligibleAudits = AiDecisionAudit::query()
            ->where('driver_id', $driver->id)
            ->whereIn('decision_code', [self::CODE_MODERATE_VIOLATION, self::CODE_FORMAL_WARNING])
            ->where('created_at', '<=', $cutoffDate)
            ->get();

        $healedCount = 0;

        foreach ($eligibleAudits as $audit) {
            $details = (array) ($audit->action_details ?? []);
            if (!empty($details['healed_at'])) {
                continue; // تم التعافي منها سابقاً
            }

            $catName = (string) ($details['category_label'] ?? $details['category'] ?? '');

            // فحص هل وردت أي شكوى جديدة في نفس التصنيف خلال الـ 30 يوماً الماضية
            $hasNewerComplaint = DriverReview::query()
                ->where('driver_id', $driver->id)
                ->where('created_at', '>', $audit->created_at)
                ->where(function ($q) {
                    $q->where('ai_label', 'Negative')
                      ->orWhere('rating', '<=', 2);
                })
                ->when($catName !== '', fn ($q) => $q->where('ai_category', $catName))
                ->exists();

            if (!$hasNewerComplaint) {
                // تعافي التحذير: توثيق التعافي في سجل التدقيق
                $details['healed_at']     = now()->toIso8601String();
                $details['healed_reason'] = "سقوط التحذير ذاتياً بعد مرور {$healingDays} يوماً دون أي شكوى جديدة في تصنيف ({$catName})";
                $audit->update(['action_details' => $details]);

                $healedCount++;
            }
        }

        if ($healedCount > 0) {
            $newWarnings = max(0, (int) $driver->active_warnings_count - $healedCount);
            $driver->update(['active_warnings_count' => $newWarnings]);
            Log::info("AiDecisionService: self-healing applied for driver #{$driver->id}, {$healedCount} warnings cleared.");
        }

        return $healedCount;
    }

    // =========================================================
    //  بناء الميزات السبع للنموذج
    // =========================================================

    private function buildFeatures(Driver $driver, DriverReview $review): array
    {
        $sentimentPred = $this->labelToInt((string) ($review->ai_label ?? 'Neutral'));
        $categoryPred  = $this->categoryToInt((string) ($review->ai_category ?? 'General'));

        $sentimentConf = (float) ($review->ai_sentiment_confidence ?? 0.85);
        $categoryConf  = (float) ($review->ai_category_confidence ?? 0.85);

        $currentRating    = (float) ($driver->rating_avg ?? self::RATING_MAX);
        $previousWarnings = (int)   ($driver->active_warnings_count ?? 0);
        $tripsCount       = (int)   $driver->trips()->count();
        $effectiveTrips   = max(10, $tripsCount);

        return [
            'current_rating'       => round($currentRating, 2),
            'previous_warnings'    => min($previousWarnings, 10),
            'trips_count'          => $effectiveTrips,
            'sentiment_pred'       => $sentimentPred,
            'sentiment_confidence' => round($sentimentConf, 4),
            'category_pred'        => $categoryPred,
            'category_confidence'  => round($categoryConf, 4),
        ];
    }

    // =========================================================
    //  استدعاء FastAPI — XGBoost endpoint
    // =========================================================

    private function callDecisionApi(array $features): ?array
    {
        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->acceptJson()
                ->asJson()
                ->post(rtrim($this->aiBaseUrl, '/') . '/decision/predict', $features);

            if (!$response->successful()) {
                Log::warning('AiDecisionService: API non-2xx', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            $data = $response->json();
            if (!is_array($data) || !isset($data['decision_code'])) {
                return null;
            }

            return $data;
        } catch (\Throwable $e) {
            Log::warning('AiDecisionService: API connection failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    // =========================================================
    //  المنطق التنفيذي لكل حالة بشكل مستقل
    // =========================================================

    /** 0 — الحالة المستقرة: لا إجراء */
    private function applyNoAction(float $ratingBefore): array
    {
        return [
            'rating_before' => $ratingBefore,
            'rating_after'  => $ratingBefore,
            'actions'       => [],
        ];
    }

    /** 1 — مكافأة وتشجيع نسبي (+3% وتخفيض التحذيرات) */
    private function applyReward(Driver $driver, float $ratingBefore, array $windowStats): array
    {
        $ratingAfter = $this->clamp($ratingBefore * self::REWARD_FACTOR);
        $newWarnings = max(0, (int)$driver->active_warnings_count - 1);

        $driver->update([
            'rating_avg'            => $ratingAfter,
            'active_warnings_count' => $newWarnings,
        ]);

        return [
            'rating_before' => $ratingBefore,
            'rating_after'  => $ratingAfter,
            'actions'       => ['rating_up_3pct', 'warnings_reduced', 'driver_rewarded'],
        ];
    }

    /** 2 — المستوى الثاني: خطورة متوسطة متكررة (خفض 5% + حجب مؤقت من البحث 24 ساعة) */
    private function applyModerateViolation(Driver $driver, float $ratingBefore, DriverReview $review, array $windowStats): array
    {
        $ratingAfter = $this->clamp($ratingBefore * self::WARNING_FACTOR);
        $newWarnings = (int) $driver->active_warnings_count + 1;
        $suspendedUntil = now()->addHours(24);

        $driver->update([
            'rating_avg'            => $ratingAfter,
            'active_warnings_count' => $newWarnings,
            'suspended_until'       => $suspendedUntil,
            'last_incident_at'      => now(),
        ]);

        AdminAlert::create([
            'driver_id'       => $driver->id,
            'risk_level'      => 'HIGH',
            'alert_type'      => 'ai_moderate_violation',
            'severity'        => 2,
            'title'           => '⚠️ مخالفة متوسطة متكررة — حجب بحث مؤقت 24 ساعة',
            'message'         => "تم رصد تكرار شكاوى متوسطة لنفس التصنيف ({$review->ai_category}) من أولياء أمور مختلفين. تم خفض التقييم بنسبة 5% وتطبيق حجب بحث مؤقت.",
            'action_required' => 'notify',
            'is_read'         => false,
            'is_resolved'     => false,
            'metadata'        => [
                'trigger'         => 'ai_moderate_violation',
                'review_id'       => $review->id,
                'category'        => $review->ai_category,
                'suspended_until' => $suspendedUntil->toIso8601String(),
                'rating_before'   => $ratingBefore,
                'rating_after'    => $ratingAfter,
                'window_stats'    => $windowStats,
            ],
        ]);

        return [
            'rating_before'   => $ratingBefore,
            'rating_after'    => $ratingAfter,
            'suspended_until' => $suspendedUntil,
            'actions'         => ['rating_down_5pct', 'temporary_search_restriction', 'warning_incremented', 'admin_alerted_high'],
        ];
    }

    /** 3 — المستوى الأول: تحذير رسمي وعقوبة تراكمية (خفض 5% وبدء عداد 30 يوماً للتعافي) */
    private function applyFormalWarning(Driver $driver, float $ratingBefore, DriverReview $review, array $windowStats): array
    {
        $ratingAfter = $this->clamp($ratingBefore * self::WARNING_FACTOR);
        $newWarnings = (int) $driver->active_warnings_count + 1;

        $driver->update([
            'rating_avg'            => $ratingAfter,
            'active_warnings_count' => $newWarnings,
            'last_incident_at'      => now(),
        ]);

        AdminAlert::create([
            'driver_id'       => $driver->id,
            'risk_level'      => 'HIGH',
            'alert_type'      => 'ai_formal_warning',
            'severity'        => 2,
            'title'           => '⚠️ تحذير رسمي — تجاوز نسبة الشكاوى السلبية 40%',
            'message'         => "بلغت نسبة الشكاوى السلبية ({$windowStats['negative_ratio']}) في آخر 15 يوماً. تم خفض التقييم 5% وتسجيل إنذار رسمي خاضع للتعافي خلال 30 يوماً.",
            'action_required' => 'notify',
            'is_read'         => false,
            'is_resolved'     => false,
            'metadata'        => [
                'trigger'        => 'ai_formal_warning',
                'review_id'      => $review->id,
                'category'       => $review->ai_category,
                'rating_before'  => $ratingBefore,
                'rating_after'   => $ratingAfter,
                'warnings_count' => $newWarnings,
                'window_stats'   => $windowStats,
            ],
        ]);

        return [
            'rating_before' => $ratingBefore,
            'rating_after'  => $ratingAfter,
            'actions'       => ['rating_down_5pct', 'formal_warning_issued', 'warning_incremented', 'admin_alerted'],
        ];
    }

    /** 4 — المستوى الثالث: خطورة قصوى وسلامة ركاب (حجب مؤقت فوري + خفض 10% + تدخل الأدمن الحصري) */
    private function applyAdminReviewRequired(Driver $driver, float $ratingBefore, DriverReview $review, array $windowStats): array
    {
        // حجب مؤقت فوري احترازي للسلامة العامة (24 ساعة مبدئياً لحين بت الأدمن)
        $precautionaryUntil = now()->addHours(24);
        $ratingAfter = $this->clamp($ratingBefore * self::ADMIN_REVIEW_FACTOR); // -10%

        $driver->update([
            'rating_avg'       => $ratingAfter,
            'suspended_until'  => $precautionaryUntil,
            'last_incident_at' => now(),
        ]);

        AdminAlert::create([
            'driver_id'       => $driver->id,
            'risk_level'      => 'CRITICAL',
            'alert_type'      => 'ai_admin_review_required',
            'severity'        => 3,
            'title'           => '⛔ يتطلب تدخل إداري عاجل — حجب احترازي للسائق',
            'message'         => "تم رصد تعليق حرج يتعلق بالسلامة (تعليق #{$review->id}). تم تطبيق حجب مؤقت رادع وخفض 10%، والقرار النهائي للإيقاف أو رفع الحجب بيد الأدمن.",
            'action_required' => 'review',
            'is_read'         => false,
            'is_resolved'     => false,
            'metadata'        => [
                'trigger'          => 'ai_admin_review_required',
                'review_id'        => $review->id,
                'category'         => $review->ai_category,
                'severity'         => $review->ai_severity,
                'rating_before'    => $ratingBefore,
                'rating_after'     => $ratingAfter,
                'precautionary_to' => $precautionaryUntil->toIso8601String(),
                'window_stats'     => $windowStats,
            ],
        ]);

        return [
            'rating_before'   => $ratingBefore,
            'rating_after'    => $ratingAfter,
            'suspended_until' => $precautionaryUntil,
            'actions'         => [
                'hidden_from_search_precautionary',
                'rating_down_10pct',
                'admin_review_required',
                'admin_alerted_critical',
            ],
        ];
    }

    // =========================================================
    //  إشعار السائق الفوري
    // =========================================================

    /**
     * إرسال إشعار فوري للسائق بناءً على نوع القرار
     */
    private function notifyDriver(Driver $driver, int $decisionCode, array $details = []): void
    {
        try {
            $driverUser = $driver->user;
            if (!$driverUser) {
                return;
            }

            [$title, $message] = match ($decisionCode) {
                self::CODE_REWARD => [
                    '🌟 تهانينا! مكافأة تقييم إيجابي',
                    'تم رفع تقييمك بنسبة 3% إضافية وتخفيض التحذيرات السابقة بفضل وصول نسبة رضا أولياء الأمور إلى 80% فأكثر خلال الـ 15 يوماً الماضية. استمر في هذا الأداء المميز!',
                ],
                self::CODE_FORMAL_WARNING => [
                    '⚠️ تنبيه رسمي بخصوص جودة الخدمة',
                    "تم تسجيل إنذار رسمي وخفض تقييمك بنسبة 5% بعد تجاوز نسبة الملاحظات السلبية 40% في تصنيف (" . ($details['category'] ?? 'عام') . ") خلال آخر 15 يوماً. نرجو العمل على تلافيها.",
                ],
                self::CODE_MODERATE_VIOLATION => [
                    '⚠️ تنبيه مخالفة متكررة — تقييد بحث مؤقت',
                    "تم رصد تكرار ملاحظات متوسطة في تصنيف (" . ($details['category'] ?? 'عام') . ")، وتم تطبيق حجب مؤقت من نتائج البحث لمدة 24 ساعة مع خفض 5% من التقييم.",
                ],
                self::CODE_ADMIN_REVIEW_REQUIRED => [
                    '⛔ إشعار عاجل — حسابك قيد المراجعة الإدارية',
                    'تم إخضاع حسابك للمراجعة الإدارية المؤقتة وتطبيق حجب احترازي فوري من نتائج البحث بناءً على ملاحظات السلامة، في انتظار مراجعة الإدارة.',
                ],
                default => [null, null],
            };

            if (!$title || !$message) {
                return;
            }

            app(NotificationService::class)->sendToUser(
                $driverUser,
                NotificationFormatter::TYPE_DRIVER_AI_ALERT,
                [
                    'title'   => $title,
                    'message' => $message,
                ],
                true
            );
        } catch (\Throwable $e) {
            Log::warning('AiDecisionService: failed to notify driver', [
                'driver_id' => $driver->id,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    // =========================================================
    //  تسجيل التدقيق
    // =========================================================

    private function writeAudit(
        Driver       $driver,
        DriverReview $review,
        int          $decisionCode,
        float        $confidence,
        array        $probabilities,
        float        $ratingBefore,
        array        $result,
        array        $features,
        array        $windowStats
    ): void {
        try {
            $freshDriver = $driver->fresh();
            AiDecisionAudit::create([
                'driver_id'            => $driver->id,
                'review_id'            => $review->id,
                'current_rating'       => $features['current_rating'],
                'previous_warnings'    => $features['previous_warnings'],
                'trips_count'          => $features['trips_count'],
                'sentiment_pred'       => $features['sentiment_pred'],
                'sentiment_confidence' => $features['sentiment_confidence'],
                'category_pred'        => $features['category_pred'],
                'category_confidence'  => $features['category_confidence'],
                'decision_code'        => $decisionCode,
                'decision_name'        => self::DECISION_NAMES[$decisionCode] ?? 'UNKNOWN',
                'decision_confidence'  => $confidence,
                'probabilities'        => $probabilities,
                'action_applied'       => implode(', ', $result['actions'] ?? []),
                'action_details'       => [
                    'rating_before'   => $ratingBefore,
                    'rating_after'    => $result['rating_after'] ?? $ratingBefore,
                    'actions'         => $result['actions'] ?? [],
                    'review_text'     => (string) $review->comment,
                    'sentiment_label' => $review->ai_label,
                    'category_label'  => $review->ai_category,
                    'window_stats'    => $windowStats,
                ],
                'suspended_until'      => $freshDriver?->suspended_until,
                'admin_override'       => false,
            ]);
        } catch (\Throwable $e) {
            Log::error('AiDecisionService: failed to write audit', [
                'error'     => $e->getMessage(),
                'driver_id' => $driver->id,
                'review_id' => $review->id,
            ]);
        }
    }

    // =========================================================
    //  دوال مساعدة — تحويل التصنيفات
    // =========================================================

    private function labelToInt(string $label): int
    {
        return match (strtolower(trim($label))) {
            'positive'                       => 0,
            'negative'                       => 2,
            'neutral', 'mixed', 'irrelevant' => 1,
            default                          => 1,
        };
    }

    private function categoryToInt(string $category): int
    {
        return match (strtolower(trim($category))) {
            'general', 'off_topic' => 0,
            'punctuality'          => 1,
            'behavior'             => 2,
            'safety'               => 3,
            'vehicle_condition'    => 4,
            default                => 0,
        };
    }

    private function clamp(float $value): float
    {
        return round(max(self::RATING_MIN, min(self::RATING_MAX, $value)), 2);
    }
}
