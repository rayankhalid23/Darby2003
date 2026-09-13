<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * غلاف HTTP رفيع لخدمة تصنيف تعليقات الأولياء (FastAPI منفصلة).
 * POST {base_url}{endpoint}  body: {"text": "..."}
 * response: {"label":"Positive|Negative|Neutral|Mixed|Irrelevant","severity":0|1|2,
 *            "category":"Safety|Punctuality|Behavior|Vehicle_Condition|General|Off_Topic"}
 */
class ReviewClassifierService
{
    public const LABEL_POSITIVE   = 'Positive';
    public const LABEL_NEGATIVE   = 'Negative';
    public const LABEL_NEUTRAL    = 'Neutral';
    public const LABEL_MIXED      = 'Mixed';
    public const LABEL_IRRELEVANT = 'Irrelevant';

    public const CATEGORY_SAFETY = 'Safety';

    private const ALLOWED_LABELS = [
        self::LABEL_POSITIVE,
        self::LABEL_NEGATIVE,
        self::LABEL_NEUTRAL,
        self::LABEL_MIXED,
        self::LABEL_IRRELEVANT,
    ];

    private const ALLOWED_CATEGORIES = [
        self::CATEGORY_SAFETY,
        'Punctuality',
        'Behavior',
        'Vehicle_Condition',
        'General',
        'Off_Topic',
    ];

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $endpoint,
        private readonly int $timeoutSeconds
    ) {}

    /**
     * @return array{label:string, severity:int, category:string}|null
     */
    public function classify(string $text): ?array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return null;
        }

        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->acceptJson()
                ->asJson()
                ->post(rtrim($this->baseUrl, '/') . '/' . ltrim($this->endpoint, '/'), [
                    'text' => $trimmed,
                ]);
        } catch (\Throwable $e) {
            Log::warning('AI classifier connection failed', ['error' => $e->getMessage()]);
            return null;
        }

        if (!$response->successful()) {
            Log::warning('AI classifier non-2xx response', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return null;
        }

        $data = $response->json();
        if (!is_array($data)) {
            return null;
        }

        $label    = (string) ($data['label'] ?? '');
        $severity = $data['severity'] ?? null;
        $category = (string) ($data['category'] ?? '');

        if (!in_array($label, self::ALLOWED_LABELS, true)) {
            return null;
        }
        if (!in_array($category, self::ALLOWED_CATEGORIES, true)) {
            return null;
        }
        if (!is_numeric($severity)) {
            return null;
        }

        $severity = (int) $severity;
        if ($severity < 0 || $severity > 2) {
            return null;
        }

        return [
            'label'    => $label,
            'severity' => $severity,
            'category' => $category,
        ];
    }
}
