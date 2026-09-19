<?php

namespace App\Jobs;

use App\Models\Shared\DriverReview;
use App\Services\Ai\AiDecisionService;
use App\Services\Ai\ReviewClassifierService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * يصنّف تعليق ولي أمر عبر FastAPI (NLP) ثم يستدعي AiDecisionService (XGBoost) لاتخاذ القرار.
 */
class ClassifyDriverReviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $backoff = 30;

    public function __construct(public int $reviewId) {}

    public function handle(
        ReviewClassifierService $classifier,
        AiDecisionService       $decisionService
    ): void {
        $review = DriverReview::with('driver')->find($this->reviewId);
        if (!$review || !$review->driver) {
            return;
        }

        $text = trim((string) $review->comment);
        if ($text === '') {
            return;
        }

        // ── 1. تصنيف NLP عبر MARBERTv2 ──────────────────────────────
        $result = $classifier->classify($text);
        if (!$result) {
            Log::info('ClassifyDriverReviewJob: NLP returned no result; skipping', [
                'review_id' => $review->id,
            ]);
            return;
        }

        // ── 2. حفظ نتائج NLP (بما فيها confidence + pred indices) ────
        $review->forceFill([
            'ai_label'                  => $result['label'],
            'ai_severity'               => $result['severity'],
            'ai_category'               => $result['category'],
            'ai_classified_at'          => now(),
            // حقول AI الجديدة للقرار
            'ai_sentiment_pred'         => $result['sentiment_pred']        ?? null,
            'ai_sentiment_confidence'   => $result['sentiment_confidence']  ?? null,
            'ai_category_pred'          => $result['category_pred']         ?? null,
            'ai_category_confidence'    => $result['category_confidence']   ?? null,
        ])->save();

        // ── 3. قرار XGBoost ───────────────────────────────────────────
        $decision = $decisionService->evaluateDriverDecision($review->driver, $review);

        Log::info('ClassifyDriverReviewJob: pipeline complete', [
            'review_id'     => $review->id,
            'driver_id'     => $review->driver->id,
            'nlp_label'     => $result['label'],
            'decision_code' => $decision['decision_code'] ?? null,
            'decision_name' => $decision['decision_name'] ?? null,
        ]);
    }
}

