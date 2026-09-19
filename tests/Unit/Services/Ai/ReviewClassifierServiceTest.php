<?php

namespace Tests\Unit\Services\Ai;

use App\Services\Ai\ReviewClassifierService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReviewClassifierServiceTest extends TestCase
{
    public function test_classify_returns_parsed_data_on_success(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/classify' => Http::response([
                'label'    => 'Positive',
                'severity' => 0,
                'category' => 'Safety',
            ], 200),
        ]);

        $service = new ReviewClassifierService(
            baseUrl: 'http://127.0.0.1:8001',
            endpoint: '/classify',
            timeoutSeconds: 5
        );

        $result = $service->classify('السائق ممتاز وملتزم');

        $this->assertNotNull($result);
        $this->assertSame('Positive', $result['label']);
        $this->assertSame(0, $result['severity']);
        $this->assertSame('Safety', $result['category']);
    }

    public function test_classify_returns_null_for_empty_text(): void
    {
        $service = new ReviewClassifierService(
            baseUrl: 'http://127.0.0.1:8001',
            endpoint: '/classify',
            timeoutSeconds: 5
        );

        $this->assertNull($service->classify('   '));
    }

    public function test_classify_returns_null_on_api_failure(): void
    {
        Http::fake([
            'http://127.0.0.1:8001/classify' => Http::response('Internal Server Error', 500),
        ]);

        $service = new ReviewClassifierService(
            baseUrl: 'http://127.0.0.1:8001',
            endpoint: '/classify',
            timeoutSeconds: 5
        );

        $this->assertNull($service->classify('سائق غير جيد'));
    }
}