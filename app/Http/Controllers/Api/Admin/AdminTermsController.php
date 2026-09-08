<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\StoreTermsArticleRequest;
use App\Http\Requests\Api\Admin\StoreTermsVersionRequest;
use App\Http\Requests\Api\Admin\UpdateTermsArticleRequest;
use App\Http\Requests\Api\Admin\UpdateTermsVersionRequest;
use App\Http\Resources\Api\Shared\TermsVersionResource;
use App\Models\Shared\TermsArticle;
use App\Models\Shared\TermsVersion;
use App\Services\Admin\AdminAuditLogService;
use App\Services\Shared\TermsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * إدارة نسخ الشروط والأحكام: عرض، إنشاء مسودة، تعديلها، إضافة/تعديل/حذف موادها،
 * ثم نشرها لتصبح النسخة السارية. كل إجراء يُسجَّل في سجل تدقيق المشرفين.
 */
class AdminTermsController extends Controller
{
    protected TermsService $termsService;
    protected AdminAuditLogService $auditLog;

    public function __construct(TermsService $termsService, AdminAuditLogService $auditLog)
    {
        $this->termsService = $termsService;
        $this->auditLog = $auditLog;
    }

    public function index(Request $request): JsonResponse
    {
        $versions = $this->termsService->listVersions(
            $request->query('audience'),
            $request->query('status')
        );

        return response()->json([
            'success' => true,
            'message' => 'تم جلب نسخ الشروط بنجاح.',
            'data'    => $versions,
        ], 200);
    }

    public function show(int $id): JsonResponse
    {
        try {
            $version = $this->termsService->getVersion($id);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => 'النسخة غير موجودة.'], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'تم جلب تفاصيل النسخة بنجاح.',
            'data'    => new TermsVersionResource($version),
        ], 200);
    }

    public function store(StoreTermsVersionRequest $request): JsonResponse
    {
        $version = $this->termsService->createDraft($request->validated(), $request->user()->id);

        $this->auditLog->record(
            action: 'create',
            entityType: 'terms_version',
            entityId: $version->id,
            entityName: $version->title,
            result: 'success',
            reason: "إنشاء نسخة مسودة جديدة من الشروط والأحكام (v{$version->version_number})"
        );

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء نسخة مسودة جديدة بنجاح، يمكنك الآن إضافة موادها.',
            'data'    => new TermsVersionResource($version->fresh('articles')),
        ], 201);
    }

    public function update(UpdateTermsVersionRequest $request, int $id): JsonResponse
    {
        $version = TermsVersion::findOrFail($id);
        $before = $version->only(['title', 'version_number', 'audience']);

        try {
            $version = $this->termsService->updateDraft($version, $request->validated());
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $this->auditLog->record(
            action: 'update',
            entityType: 'terms_version',
            entityId: $version->id,
            entityName: $version->title,
            result: 'success',
            changes: $this->auditLog->diff($before, $version->only(['title', 'version_number', 'audience']))
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث بيانات النسخة بنجاح.',
            'data'    => new TermsVersionResource($version->fresh('articles')),
        ], 200);
    }

    public function destroy(int $id): JsonResponse
    {
        $version = TermsVersion::findOrFail($id);

        try {
            $this->termsService->deleteDraft($version);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $this->auditLog->record(
            action: 'delete',
            entityType: 'terms_version',
            entityId: $id,
            result: 'success',
            reason: 'حذف نسخة مسودة من الشروط والأحكام'
        );

        return response()->json([
            'success' => true,
            'message' => 'تم حذف النسخة المسودة بنجاح.',
        ], 200);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        $version = TermsVersion::findOrFail($id);

        try {
            $version = $this->termsService->publish($version, $request->user()->id);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $this->auditLog->record(
            action: 'publish',
            entityType: 'terms_version',
            entityId: $version->id,
            entityName: $version->title,
            result: 'success',
            reason: "نشر النسخة v{$version->version_number} لتصبح النسخة السارية لجمهور ({$version->audience})"
        );

        return response()->json([
            'success' => true,
            'message' => 'تم نشر النسخة بنجاح وأصبحت هي النسخة السارية.',
            'data'    => new TermsVersionResource($version->fresh('articles')),
        ], 200);
    }

    public function acceptanceStats(int $id): JsonResponse
    {
        $version = TermsVersion::findOrFail($id);

        return response()->json([
            'success' => true,
            'message' => 'تم جلب إحصاءات الموافقة بنجاح.',
            'data'    => $this->termsService->acceptanceStats($version),
        ], 200);
    }

    public function storeArticle(StoreTermsArticleRequest $request, int $id): JsonResponse
    {
        $version = TermsVersion::findOrFail($id);

        try {
            $article = $this->termsService->addArticle($version, $request->validated());
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $this->auditLog->record(
            action: 'create',
            entityType: 'terms_article',
            entityId: $article->id,
            entityName: $article->title,
            result: 'success',
            reason: "إضافة المادة رقم {$article->article_number} لنسخة الشروط #{$version->id}"
        );

        return response()->json([
            'success' => true,
            'message' => 'تمت إضافة المادة بنجاح.',
            'data'    => $article,
        ], 201);
    }

    public function updateArticle(UpdateTermsArticleRequest $request, int $versionId, int $articleId): JsonResponse
    {
        $article = TermsArticle::where('terms_version_id', $versionId)->findOrFail($articleId);
        $before = $article->only(['title', 'body', 'article_number', 'sort_order']);

        try {
            $article = $this->termsService->updateArticle($article, $request->validated());
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $this->auditLog->record(
            action: 'update',
            entityType: 'terms_article',
            entityId: $article->id,
            entityName: $article->title,
            result: 'success',
            changes: $this->auditLog->diff($before, $article->only(['title', 'body', 'article_number', 'sort_order']))
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث المادة بنجاح.',
            'data'    => $article,
        ], 200);
    }

    public function destroyArticle(int $versionId, int $articleId): JsonResponse
    {
        $article = TermsArticle::where('terms_version_id', $versionId)->findOrFail($articleId);

        try {
            $this->termsService->deleteArticle($article);
        } catch (Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $this->auditLog->record(
            action: 'delete',
            entityType: 'terms_article',
            entityId: $articleId,
            result: 'success',
            reason: "حذف مادة من نسخة الشروط #{$versionId}"
        );

        return response()->json([
            'success' => true,
            'message' => 'تم حذف المادة بنجاح.',
        ], 200);
    }
}
