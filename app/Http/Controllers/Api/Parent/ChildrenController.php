<?php

namespace App\Http\Controllers\Api\Parent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Parent\StoreChildRequest;
use App\Http\Requests\Api\Parent\UpdateChildRequest;
use App\Models\Parent\Child;
use App\Services\Parent\ChildService;
use App\Http\Resources\Api\Parent\ChildResource;
use App\Http\Resources\Api\Parent\ActiveSubscribedChildResource;
use Illuminate\Http\JsonResponse;
use App\Http\Resources\Api\Parent\SubscriptionResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChildrenController extends Controller
{
    protected ChildService $childService;

    public function __construct(ChildService $childService)
    {
        $this->childService = $childService;
    }

    /**
     * جلب معرف الولي الفعلي المقابل للمستخدم الحالي
     */
    private function getActualParentId(): ?int
    {
        return auth()->id() ? (int) auth()->id() : null;
    }

    /**
     * دالة 1: التحقق مما إذا كان ولي الأمر عنده أطفال مضافين أم لا (ترجع true / false)
     * GET /api/parent/children/has-children
     */
    public function checkHasChildren(): JsonResponse
    {
        $userId = auth()->id();
        $parentId = $this->getActualParentId();

        $hasChildren = $this->childService->hasChildren($userId, $parentId);

        return response()->json([
            'success'      => true,
            'has_children' => $hasChildren
        ], 200);
    }

    /**
     * دالة 2: جلب الأطفال الذين لديهم اشتراكات نشطة فقط لولي الأمر (id, name, photo_url)
     * GET /api/parent/children/active-subscribed
     */
    public function getActiveSubscribedChildren(): JsonResponse
    {
        $userId = auth()->id();
        $parentId = $this->getActualParentId();

        $children = $this->childService->getActiveSubscribedChildren($userId, $parentId);

        return response()->json([
            'success' => true,
            'data'    => ActiveSubscribedChildResource::collection($children)
        ], 200);
    }

    public function index(): JsonResponse
    {
        $parentId = $this->getActualParentId();

        if (!$parentId) {
            return response()->json([
                'success' => false,
                'message' => 'عذراً، لم يتم العثور على ملف ولي أمر مرتبط بهذا الحساب.'
            ], 404);
        }

        // الاستعلام الصحيح باستخدام parent_id الفعلي وليس user_id
        $children = Child::where('parent_id', $parentId)
            ->with(['school', 'address', 'logistics']) 
            ->get();

        return response()->json([
            'success' => true,
            'data'    => ChildResource::collection($children)
        ], 200);
    }

    public function store(StoreChildRequest $request): JsonResponse
    {
        $userId = auth()->id();
        $parentId = $this->getActualParentId();
        
        // 1. حالة فشل: عدم العثور على حساب ولي الأمر
        if (!$parentId) {
            Log::warning('Failed to add child: Parent profile not found', [
                'user_id' => $userId,
                'input_data' => $request->validated()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'لا يمكن إضافة طفل لعدم وجود ملف ولي أمر نشط.'
            ], 400);
        }

        try {
            // دمج الـ parent_id الصحيح داخل البيانات
            $data = $request->validated();
            $data['parent_id'] = $parentId;

            // محاولة إنشاء الطفل عبر السيرفس
            $child = $this->childService->createChild($data);

            // سجل النجاح
            Log::info('Parent Added New Child Successfully', [
                'parent_id'  => $parentId,
                'child_id'   => $child->id,
                'child_name' => $child->full_name,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'تم إضافة بيانات الطفل بنجاح.',
                'data'    => new ChildResource($child->load(['logistics', 'school', 'address']))
            ], 201);

        } catch (\Throwable $e) {
            // 2. حالة فشل: حدوث استثناء (Exception / Query Error / Runtime Error)
            Log::error('Failed to add child: Exception occurred', [
                'user_id'       => $userId,
                'parent_id'     => $parentId,
                'error_message' => $e->getMessage(), // نص الخطأ المباشر
                'file'          => $e->getFile(),    // اسم الملف الذي وقع فيه الخطأ
                'line'          => $e->getLine(),    // رقم السطر
                'payload'       => $request->validated() // البيانات التي أرسلها المستخدم
            ]);

            return response()->json([
                'success' => false,
                'message' => 'حدث خطأ أثناء حفظ بيانات الطفل، يرجى المحاولة لاحقاً.',
                'error'   => config('app.debug') ? $e->getMessage() : null // إظهار التفاصيل للبيئة التطويرية فقط
            ], 500);
        }
    }

    public function show($id): JsonResponse
    {
        $parentId = $this->getActualParentId();
    
        // البحث عن الطفل بناءً على معرف الولي الصحيح
        $child = Child::where('id', $id)
            ->where('parent_id', $parentId) 
            ->with(['school', 'address', 'logistics'])
            ->first();
    
        if (!$child) {
            return response()->json([
                'success' => false,
                'message' => 'عذراً، هذا السجل غير موجود أو لا تملك صلاحية الوصول إليه.'
            ], 404);
        }
    
        return response()->json([
            'success' => true,
            'data'    => new ChildResource($child)
        ], 200);
    }

    public function getSubscription($id): JsonResponse
    {
        $parentId = $this->getActualParentId();
    
        $child = Child::where('id', $id)
            ->where('parent_id', $parentId)
            ->with(['logistics']) 
            ->first();

        if (!$child) {
            return response()->json([
                'success' => false,
                'message' => 'السجل غير موجود أو لا تملك صلاحية الوصول إليه.'
            ], 404);
        }
    
        return response()->json([
            'success' => true,
            'data'    => new SubscriptionResource($child->logistics) 
        ], 200);
    }

/**
     * تحديث بيانات الطفل بشكل جزئي
     * PUT/PATCH /api/parent/children/{id}
     */
public function update(UpdateChildRequest $request, $id): JsonResponse
{
    $userId = auth()->id();
    $parentId = $this->getActualParentId();

    if (!$parentId) {
        return response()->json([
            'success' => false,
            'message' => 'عذراً، لم يتم العثور على ملف ولي أمر مرتبط بهذا الحساب.'
        ], 404);
    }

    $child = Child::where('id', $id)->where('parent_id', $parentId)->first();

    if (!$child) {
        return response()->json([
            'success' => false,
            'message' => 'عذراً، هذا السجل غير موجود أو لا تملك صلاحية تعديله.'
        ], 404);
    }

    try {
        $updatedChild = $this->childService->updateChild($child, $request->validated());

        Log::info('Parent Updated Child Successfully', [
            'parent_id'      => $parentId,
            'child_id'       => $child->id,
            'updated_fields' => array_keys($request->validated()),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث بيانات الطفل بنجاح.',
            'data'    => new ChildResource($updatedChild->load(['logistics', 'school', 'address']))
        ], 200);

    } catch (\Throwable $e) {
        Log::error('Failed to update child: Exception occurred', [
            'user_id'       => $userId,
            'parent_id'     => $parentId,
            'child_id'      => $id,
            'error_message' => $e->getMessage(),
            'file'          => $e->getFile(),
            'line'          => $e->getLine(),
            'payload'       => $request->validated()
        ]);

        return response()->json([
            'success' => false,
            'message' => 'حدث خطأ أثناء تحديث بيانات الطفل، يرجى المحاولة لاحقاً.',
            'error'   => config('app.debug') ? $e->getMessage() : null
        ], 500);
    }
}

    public function destroy($id): JsonResponse
    {
        $parentId = $this->getActualParentId();

        $child = Child::where('id', $id)->where('parent_id', $parentId)->first();

        if (!$child) {
            return response()->json([
                'success' => false,
                'message' => 'عذراً، هذا السجل غير موجود أو لا تملك صلاحية حذفه.'
            ], 404);
        }

        $this->childService->deleteChild($child);

        return response()->json([
            'success' => true,
            'message' => 'تم حذف بيانات الطفل وإلغاء اشتراكه بنجاح.'
        ], 200);
    }
}