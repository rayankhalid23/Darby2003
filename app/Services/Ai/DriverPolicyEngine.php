<?php

namespace App\Services\Ai;

use App\Models\Admin\AdminAlert;
use App\Models\Driver\Driver;
use App\Models\Shared\DriverReview;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * محرك قرار السائقين المبني على نتائج التصنيف.
 *
 * القواعد (حسب المواصفة):
 *  2) أي تعليق Safety + severity=2 → إخفاء + خفض ~10% + تنبيه critical (فوراً بدون شروط).
 *  4) قواعد الأنماط تعتمد نافذة 30 يوم (أو منذ آخر Reset إن كان أحدث).
 *  5) نسبة الأطفال الذين تركوا Negative ÷ الأطفال النشطين ≥ 40% → خفض ~5% + warning.
 *  6) نسبة Positive ÷ الأطفال النشطين ≥ 50% → زيادة ~3%.
 *  7) تغيير التقييم نسبي (ضرب) + clamp بين 0.00 و 5.00.
 *  إذا الأطفال النشطون = 0 → تُتجاهل قواعد 4 و5 تفادياً للقسمة على صفر.
 */
class DriverPolicyEngine
{
    public const DECISION_CRITICAL = 'critical';
    public const DECISION_WARNING  = 'warning';
    public const DECISION_POSITIVE = 'positive';
    public const DECISION_NONE     = 'none';

    private const WINDOW_DAYS               = 30;
    private const NEGATIVE_RATIO_THRESHOLD  = 0.40;
    private const POSITIVE_RATIO_THRESHOLD  = 0.50;
    private const CRITICAL_RATING_FACTOR    = 0.90; // -10%
    private const NEGATIVE_RATING_FACTOR    = 0.95; // -5%
    private const POSITIVE_RATING_FACTOR    = 1.03; // +3%
    private const RATING_MIN                = 0.00;
    private const RATING_MAX                = 5.00;

    /**
     * يعالج قرارات السائق بناءً على آخر مراجعة مصنَّفة + النافذة الزمنية.
     *
     * @return array{decision:string, rating_before:float, rating_after:float, actions:array<int,string>}|null
     */
    public function evaluate(Driver $driver, ?DriverReview $latestReview = null): ?array
    {
        return DB::transaction(function () use ($driver, $latestReview) {
            $driver = Driver::lockForUpdate()->find($driver->id);
            if (!$driver) {
                return null;
            }

            $ratingBefore = (float) ($driver->rating_avg ?? self::RATING_MAX);

            // 1) قاعدة الحرج: تحقّق فوري على أحدث تعليق مصنَّف (يمرَّر من الـ Job).
            if ($latestReview
                && $latestReview->ai_category === ReviewClassifierService::CATEGORY_SAFETY
                && (int) $latestReview->ai_severity === 2
            ) {
                return $this->applyCritical($driver, $latestReview, $ratingBefore);
            }

            // 2) قواعد الأنماط: تحتاج عدد أطفال نشطين > 0.
            $activeChildrenCount = $this->countActiveChildren($driver->id);
            if ($activeChildrenCount === 0) {
                return $this->noAction($ratingBefore);
            }

            $windowStart = $this->windowStartFor($driver);

            [$distinctNegativeChildren, $distinctPositiveChildren] = $this->countDistinctChildVotes(
                $driver->id,
                $windowStart
            );

            $negativeRatio = $distinctNegativeChildren / $activeChildrenCount;
            $positiveRatio = $distinctPositiveChildren / $activeChildrenCount;

            if ($negativeRatio >= self::NEGATIVE_RATIO_THRESHOLD) {
                return $this->applyNegativePattern(
                    $driver,
                    $ratingBefore,
                    $distinctNegativeChildren,
                    $activeChildrenCount,
                    $negativeRatio,
                    $windowStart
                );
            }

            if ($positiveRatio >= self::POSITIVE_RATIO_THRESHOLD) {
                return $this->applyPositivePattern(
                    $driver,
                    $ratingBefore,
                    $distinctPositiveChildren,
                    $activeChildrenCount,
                    $positiveRatio
                );
            }

            return $this->noAction($ratingBefore);
        });
    }

    private function applyCritical(Driver $driver, DriverReview $review, float $ratingBefore): array
    {
        $ratingAfter = $this->clamp($ratingBefore * self::CRITICAL_RATING_FACTOR);

        $driver->update(['rating_avg' => $ratingAfter]);
        if ($driver->user) {
            $driver->user()->update(['is_trusted' => false]);
        }

        AdminAlert::create([
            'driver_id'       => $driver->id,
            'risk_level'      => 'CRITICAL',
            'alert_type'      => 'ai_critical',
            'severity'        => 3,
            'title'           => '⛔ مخالفة سلامة حرجة — سائق مخفي عن البحث',
            'message'         => "تصنيف الذكاء الاصطناعي رصد مخالفة سلامة بأعلى درجة خطورة على تعليق ولي أمر (رقم التعليق #{$review->id}).",
            'action_required' => 'review',
            'is_read'         => false,
            'is_resolved'     => false,
            'metadata'        => [
                'trigger'       => 'safety_severity_2',
                'review_id'     => $review->id,
                'review_text'   => (string) $review->comment,
                'rating_before' => $ratingBefore,
                'rating_after'  => $ratingAfter,
            ],
        ]);

        Log::info('AI policy: critical action applied', [
            'driver_id'    => $driver->id,
            'review_id'    => $review->id,
            'rating_after' => $ratingAfter,
        ]);

        return [
            'decision'      => self::DECISION_CRITICAL,
            'rating_before' => $ratingBefore,
            'rating_after'  => $ratingAfter,
            'actions'       => ['hide_from_search', 'rating_down_10pct', 'alert_critical'],
        ];
    }

    private function applyNegativePattern(
        Driver $driver,
        float $ratingBefore,
        int $negativeChildren,
        int $activeChildren,
        float $ratio,
        Carbon $windowStart
    ): array {
        $ratingAfter = $this->clamp($ratingBefore * self::NEGATIVE_RATING_FACTOR);
        $driver->update(['rating_avg' => $ratingAfter]);

        AdminAlert::create([
            'driver_id'       => $driver->id,
            'risk_level'      => 'HIGH',
            'alert_type'      => 'ai_warning',
            'severity'        => 2,
            'title'           => '⚠️ نمط سلبي متكرر — تخفيض تقييم السائق',
            'message'         => sprintf(
                'نسبة الأطفال الذين تركوا تعليقات سلبية %.0f%% (%d/%d) خلال آخر 30 يوماً.',
                $ratio * 100,
                $negativeChildren,
                $activeChildren
            ),
            'action_required' => 'notify',
            'is_read'         => false,
            'is_resolved'     => false,
            'metadata'        => [
                'trigger'                    => 'negative_ratio_threshold',
                'window_start'               => $windowStart->toIso8601String(),
                'distinct_negative_children' => $negativeChildren,
                'active_children_total'      => $activeChildren,
                'negative_ratio'             => round($ratio, 4),
                'rating_before'              => $ratingBefore,
                'rating_after'               => $ratingAfter,
            ],
        ]);

        return [
            'decision'      => self::DECISION_WARNING,
            'rating_before' => $ratingBefore,
            'rating_after'  => $ratingAfter,
            'actions'       => ['rating_down_5pct', 'alert_warning'],
        ];
    }

    private function applyPositivePattern(
        Driver $driver,
        float $ratingBefore,
        int $positiveChildren,
        int $activeChildren,
        float $ratio
    ): array {
        $ratingAfter = $this->clamp($ratingBefore * self::POSITIVE_RATING_FACTOR);
        $driver->update(['rating_avg' => $ratingAfter]);

        return [
            'decision'      => self::DECISION_POSITIVE,
            'rating_before' => $ratingBefore,
            'rating_after'  => $ratingAfter,
            'actions'       => ['rating_up_3pct'],
        ];
    }

    private function noAction(float $ratingBefore): array
    {
        return [
            'decision'      => self::DECISION_NONE,
            'rating_before' => $ratingBefore,
            'rating_after'  => $ratingBefore,
            'actions'       => [],
        ];
    }

    /**
     * SELECT COUNT(*)
     * FROM active_subscriptions a
     * JOIN request_children rc ON rc.id = a.request_child_id
     * JOIN requests r          ON r.id  = a.subscription_request_id
     * WHERE r.driver_id = :id AND a.status = 'active';
     */
    private function countActiveChildren(int $driverId): int
    {
        return (int) DB::table('active_subscriptions as a')
            ->join('request_children as rc', 'rc.id', '=', 'a.request_child_id')
            ->join('requests as r', 'r.id', '=', 'a.subscription_request_id')
            ->where('r.driver_id', $driverId)
            ->where('a.status', 'active')
            ->count();
    }

    /**
     * تعدّ الأطفال المتميّزين الذين ينتمي كل منهم إلى ولي أمر ترك تعليقاً بالتصنيف
     * المطلوب خلال النافذة. كل طفل نشط تحت ولي الأمر المعلِّق يحتسب صوتاً واحداً.
     *
     * @return array{0:int,1:int}
     */
    private function countDistinctChildVotes(int $driverId, Carbon $windowStart): array
    {
        $parentLabels = DriverReview::query()
            ->where('driver_id', $driverId)
            ->where('ai_classified_at', '>=', $windowStart)
            ->whereIn('ai_label', [
                ReviewClassifierService::LABEL_NEGATIVE,
                ReviewClassifierService::LABEL_POSITIVE,
            ])
            ->select('parent_id', 'ai_label', 'created_at')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('parent_id')
            ->map(fn ($rows) => $rows->first()->ai_label)
            ->all();

        if (empty($parentLabels)) {
            return [0, 0];
        }

        $parentIds = array_keys($parentLabels);

        $parentToChildren = DB::table('active_subscriptions as a')
            ->join('request_children as rc', 'rc.id', '=', 'a.request_child_id')
            ->join('requests as r', 'r.id', '=', 'a.subscription_request_id')
            ->where('r.driver_id', $driverId)
            ->where('a.status', 'active')
            ->whereIn('r.parent_id', $parentIds)
            ->select('r.parent_id', 'rc.child_id')
            ->distinct()
            ->get()
            ->groupBy('parent_id');

        $negativeChildren = 0;
        $positiveChildren = 0;

        foreach ($parentLabels as $parentId => $label) {
            $childrenForParent = $parentToChildren->get($parentId, collect())->count();
            if ($childrenForParent === 0) {
                continue;
            }
            if ($label === ReviewClassifierService::LABEL_NEGATIVE) {
                $negativeChildren += $childrenForParent;
            } elseif ($label === ReviewClassifierService::LABEL_POSITIVE) {
                $positiveChildren += $childrenForParent;
            }
        }

        return [$negativeChildren, $positiveChildren];
    }

    private function windowStartFor(Driver $driver): Carbon
    {
        $default = now()->subDays(self::WINDOW_DAYS);
        $reset   = $driver->ai_last_reset_at;

        if (!$reset) {
            return $default;
        }

        $resetAt = $reset instanceof Carbon ? $reset : Carbon::parse($reset);
        return $resetAt->greaterThan($default) ? $resetAt : $default;
    }

    private function clamp(float $value): float
    {
        return round(max(self::RATING_MIN, min(self::RATING_MAX, $value)), 2);
    }
}
