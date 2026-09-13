<?php

namespace App\Jobs;

use App\Models\Shared\DriverReview;
use App\Services\Ai\DriverPolicyEngine;
use App\Services\Ai\ReviewClassifierService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * يصنّف تعليق ولي أمر واحد عبر FastAPI، يحفظ النتيجة، ثم يمرّر التعليق لمحرك القرار.
 */
class ClassifyDriverReviewJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(public int $reviewId) {}

    public function handle(
        ReviewClassifierService $classifier,
        DriverPolicyEngine $engine
    ): void {
        $review = DriverReview::with('driver')->find($this->reviewId);
        if (!$review || !$review->driver) {
            return;
        }

        $text = trim((string) $review->comment);
        if ($text === '') {
            return;
        }

        $result = $classifier->classify($text);
        if (!$result) {
            Log::info('AI classifier returned no result; skipping decision', ['review_id' => $review->id]);
            return;
        }

        $review->forceFill([
            'ai_label'         => $result['label'],
            'ai_severity'      => $result['severity'],
            'ai_category'      => $result['category'],
            'ai_classified_at' => now(),
        ])->save();

        $engine->evaluate($review->driver, $review);
    }
}
