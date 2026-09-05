<?php

namespace App\Http\Controllers\Api\Parent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Parent\SearchDriversRequest;
use App\Services\Parent\DriverMatchingService;
use App\Http\Resources\Api\Parent\DriverMatchResource;
use App\Models\ParentModel; // أو موديل الـ Parent الخاص بمشروعك (مثل ParentProfile أو Parent)
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DriverSearchController extends Controller
{
    public function __construct(
        protected DriverMatchingService $matchingService
    ) {}

    public function search(SearchDriversRequest $request): JsonResponse
    {
        try {
            // 1. استخدام المعرف الموحد لولي الأمر من حساب المستخدم الحالي
            $parentId = (int) auth()->id();
            $filters  = $request->validated();

            // 2. التحقق من أمان ملكية الأطفال في حال تم إرسال child_ids
            if (!empty($filters['child_ids'])) {
                $validChildrenCount = DB::table('children')
                    ->where('parent_id', $parentId)
                    ->whereIn('id', $filters['child_ids'])
                    ->count();

                if ($validChildrenCount !== count($filters['child_ids'])) {
                    return response()->json([
                        'status'  => false,
                        'message' => 'عذراً، أحد الأطفال المحددين غير ينتمي إلى حسابك.'
                    ], Response::HTTP_FORBIDDEN);
                }
            }

            // 3. تشغيل محرك الفلترة والتسعير بالمعرف الصحيح
            $drivers = $this->matchingService->matchDrivers($filters, $parentId);

            $isEmpty = $drivers->isEmpty();

            return response()->json([
                'status'  => true,
                'message' => $isEmpty
                    ? 'لم يتم العثور على سائقين مطابقين للبحث.'
                    : 'تمت الفلترة وجلب السائقين بنجاح.',

                'meta' => [
                    'current_page' => $drivers->currentPage(),
                    'last_page'    => $drivers->lastPage(),
                    'per_page'     => $drivers->perPage(),
                    'total'        => $drivers->total(),
                ],

                'data' => DriverMatchResource::collection($drivers->items()),

            ], Response::HTTP_OK);

        } catch (\Throwable $e) {
            Log::error('Driver Search Error: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace'   => $e->getTraceAsString()
            ]);

            return response()->json([
                'status'     => false,
                'error_code' => 'FILTER_ERROR',
                'message'    => 'حدث خطأ غير متوقع أثناء معالجة طلب البحث: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}