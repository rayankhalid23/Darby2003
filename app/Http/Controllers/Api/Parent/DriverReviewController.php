<?php

namespace App\Http\Controllers\Api\Parent;

use App\Models\Shared\DriverReview;
use Illuminate\Http\JsonResponse;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;
use App\Http\Requests\Api\Parent\StoreDriverReviewRequest;
use Illuminate\Http\Request;
use Exception;

class DriverReviewController extends Controller
{
    /**
     * 📊 جلب تقييمات سائق معين (تُستخدم من واجهة ولي الأمر قبل اختيار سائق)
     */
    public function index(int $driverId): JsonResponse
    {
        $reviews = DriverReview::with(['parent', 'driver.user'])
            ->where('driver_id', $driverId)
            ->latest()
            ->paginate(10);

        return response()->json([
            'status'  => true,
            'data'    => \App\Http\Resources\Api\Parent\DriverReviewResource::collection($reviews),
            'pagination' => [
                'current_page' => $reviews->currentPage(),
                'last_page'    => $reviews->lastPage(),
                'total'        => $reviews->total(),
                'per_page'     => $reviews->perPage(),
            ],
        ]);
    }

    /**
     * ➕ إضافة تقييم أو تعليق جديد للسائق من قبل ولي الأمر
     */
    public function store(StoreDriverReviewRequest $request): JsonResponse
    {
        try {
            $user = auth()->user();

            // إنشاء تقييم جديد
            $review = DriverReview::create([
                'parent_id' => $user->id,
                'driver_id' => $request->driver_id,
                'rating'    => $request->rating,
                'comment'   => $request->comment,
                'status'    => 'active',
            ]);

            // تحميل العلاقات لعرضها بشكل كامل في الاستجابة
            $review->load(['parent', 'driver.user']);

            // 🤖 كل تعليق يمر على محرك التصنيف الذكي، ثم على محرك القرار
            if (!empty(trim((string) $review->comment))) {
                \App\Jobs\ClassifyDriverReviewJob::dispatch($review->id);
            }

            Log::info("DriverReview [Store] - تمت إضافة تقييم جديد بنجاح.", ['review_id' => $review->id]);

            return response()->json([
                'status'  => true,
                'message' => 'تم إضافة تقييمك وتعليقك بنجاح.',
                'data'    => new \App\Http\Resources\Api\Parent\DriverReviewResource($review)
            ], 201);

        } catch (Exception $e) {
            Log::error("DriverReview [Store] - خطأ أثناء حفظ التقييم: " . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'حدث خطأ أثناء حفظ التعليق، يرجى المحاولة لاحقاً.',
            ], 500);
        }
    }

    /**
     * ✏️ تعديل تقييم أو تعليق سابق لولي الأمر
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            $user = auth()->user();

            // البحث عن التقييم والتأكد أنه يتبع لولي الأمر نفسه لضمان الأمان
            $review = DriverReview::where('id', $id)
                ->where('parent_id', $user->id)
                ->first();

            if (!$review) {
                return response()->json([
                    'status'  => false,
                    'message' => 'التقييم غير موجود أو ليس لديك صلاحية لتعديله.',
                ], 404);
            }

            // التحقق من المدخلات الخاصة بالتعديل
            $validatedData = $request->validate([
                'rating'  => 'sometimes|required|integer|min:1|max:5',
                'comment' => 'nullable|string|max:1000',
            ]);

            // تحديث البيانات
            $review->update($validatedData);

            // إعادة تحميل العلاقات
            $review->load(['parent', 'driver.user']);

            Log::info("DriverReview [Update] - تم تعديل التقييم بنجاح.", ['review_id' => $review->id]);

            return response()->json([
                'status'  => true,
                'message' => 'تم تعديل التقييم بنجاح.',
                'data'    => new \App\Http\Resources\Api\Parent\DriverReviewResource($review)
            ]);

        } catch (Exception $e) {
            Log::error("DriverReview [Update] - خطأ أثناء تعديل التقييم رقم $id: " . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'حدث خطأ أثناء تعديل التعليق، يرجى المحاولة لاحقاً.',
            ], 500);
        }
    }

    /**
     * 🗑️ حذف ولي الأمر لتقييمه الخاص فقط (حذف نهائي)
     * ملاحظة: حذف أي تقييم من أي ولي أمر هو صلاحية أدمن حصرياً
     * عبر DELETE /api/admin/driver-reviews/{id}، وليس هذا المسار.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $user = auth()->user();

            $review = DriverReview::where('id', $id)
                ->where('parent_id', $user->id)
                ->first();

            if (!$review) {
                return response()->json([
                    'status'  => false,
                    'message' => 'التقييم غير موجود أو ليس لديك صلاحية لحذفه.',
                ], 404);
            }

            $review->forceDelete();

            Log::info("DriverReview [Destroy] - ولي الأمر حذف تقييمه بنجاح.", ['review_id' => $id, 'parent_id' => $user->id]);

            return response()->json([
                'status'  => true,
                'message' => 'تم حذف تقييمك بنجاح.',
            ]);

        } catch (Exception $e) {
            Log::error("DriverReview [Destroy] - فشل حذف التقييم رقم $id: " . $e->getMessage());
            return response()->json([
                'status'  => false,
                'message' => 'حدث خطأ أثناء حذف التقييم.',
            ], 500);
        }
    }
}