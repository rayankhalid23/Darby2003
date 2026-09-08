<?php

namespace App\Services\Shared;

use App\Models\Shared\TermsAcceptance;
use App\Models\Shared\TermsArticle;
use App\Models\Shared\TermsVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Exception;

class TermsService
{
    /**
     * النسخة السارية حالياً لجمهور معيّن (parent أو driver)، بمقالاتها مرتّبة.
     */
    public function getCurrentPublished(string $audience): ?TermsVersion
    {
        return TermsVersion::published()
            ->forAudience($audience)
            ->with('articles')
            ->latest('published_at')
            ->first();
    }

    public function getVersion(int $id): TermsVersion
    {
        return TermsVersion::with('articles')->findOrFail($id);
    }

    public function listVersions(?string $audience = null, ?string $status = null)
    {
        return TermsVersion::withCount('articles')
            ->when($audience, fn ($q) => $q->where('audience', $audience))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('id')
            ->paginate(15);
    }

    public function createDraft(array $data, int $adminId): TermsVersion
    {
        return TermsVersion::create([
            'version_number' => $data['version_number'],
            'title'          => $data['title'],
            'audience'       => $data['audience'],
            'status'         => TermsVersion::STATUS_DRAFT,
            'created_by'     => $adminId,
        ]);
    }

    public function updateDraft(TermsVersion $version, array $data): TermsVersion
    {
        if ($version->status !== TermsVersion::STATUS_DRAFT) {
            throw new Exception('لا يمكن تعديل نسخة منشورة أو مؤرشفة، يجب إنشاء نسخة مسودة جديدة منها أولاً.');
        }

        $version->update(array_intersect_key($data, array_flip(['version_number', 'title', 'audience'])));

        return $version->fresh();
    }

    public function deleteDraft(TermsVersion $version): void
    {
        if ($version->status !== TermsVersion::STATUS_DRAFT) {
            throw new Exception('لا يمكن حذف نسخة منشورة أو مؤرشفة؛ يمكن أرشفتها فقط عبر نشر نسخة بديلة.');
        }

        $version->articles()->delete();
        $version->delete();
    }

    public function addArticle(TermsVersion $version, array $data): TermsArticle
    {
        if ($version->status !== TermsVersion::STATUS_DRAFT) {
            throw new Exception('لا يمكن إضافة مواد إلا لنسخة مسودة.');
        }

        return TermsArticle::create([
            'terms_version_id' => $version->id,
            'article_number'   => $data['article_number'],
            'title'            => $data['title'],
            'body'             => $data['body'],
            'sort_order'       => $data['sort_order'] ?? $data['article_number'],
        ]);
    }

    public function updateArticle(TermsArticle $article, array $data): TermsArticle
    {
        if ($article->version->status !== TermsVersion::STATUS_DRAFT) {
            throw new Exception('لا يمكن تعديل مواد نسخة منشورة أو مؤرشفة.');
        }

        $article->update(array_intersect_key($data, array_flip(['article_number', 'title', 'body', 'sort_order'])));

        return $article->fresh();
    }

    public function deleteArticle(TermsArticle $article): void
    {
        if ($article->version->status !== TermsVersion::STATUS_DRAFT) {
            throw new Exception('لا يمكن حذف مواد نسخة منشورة أو مؤرشفة.');
        }

        $article->delete();
    }

    /**
     * نشر نسخة مسودة: تصبح هي النسخة السارية لجمهورها، وتُؤرشف تلقائياً كل نسخة
     * منشورة سابقاً تتقاطع معها في الجمهور (parent/driver/both)، بحيث يبقى هناك
     * نسخة سارية واحدة فقط لكل جمهور — هي وحدها ما تُحتسب في hasAccepted().
     */
    public function publish(TermsVersion $version, int $adminId): TermsVersion
    {
        if ($version->status === TermsVersion::STATUS_PUBLISHED) {
            throw new Exception('هذه النسخة منشورة بالفعل.');
        }

        if ($version->articles()->count() === 0) {
            throw new Exception('لا يمكن نشر نسخة لا تحتوي على أي مادة.');
        }

        return DB::transaction(function () use ($version, $adminId) {
            $audiencesToArchive = $version->audience === TermsVersion::AUDIENCE_BOTH
                ? [TermsVersion::AUDIENCE_PARENT, TermsVersion::AUDIENCE_DRIVER, TermsVersion::AUDIENCE_BOTH]
                : [$version->audience, TermsVersion::AUDIENCE_BOTH];

            TermsVersion::where('status', TermsVersion::STATUS_PUBLISHED)
                ->where('id', '!=', $version->id)
                ->whereIn('audience', $audiencesToArchive)
                ->update(['status' => TermsVersion::STATUS_ARCHIVED]);

            $version->update([
                'status'       => TermsVersion::STATUS_PUBLISHED,
                'published_at' => now(),
                'published_by' => $adminId,
            ]);

            return $version->fresh('articles');
        });
    }

    /**
     * هل وافق هذا المستخدم على النسخة السارية حالياً لدوره؟ هذا هو الفحص الذي
     * تعتمد عليه middleware EnsureTermsAccepted لحجب أي إجراء حتى تُسجَّل الموافقة.
     */
    public function hasAccepted(User $user, string $role): bool
    {
        $current = $this->getCurrentPublished($role);
        if (!$current) {
            // لا توجد نسخة منشورة أصلاً لهذا الجمهور — لا يُحجب المستخدم بسبب غياب المحتوى
            return true;
        }

        return TermsAcceptance::where('user_id', $user->id)
            ->where('terms_version_id', $current->id)
            ->exists();
    }

    /**
     * تسجيل موافقة المستخدم على النسخة السارية حالياً لدوره. هذا السجل (بالـ IP
     * ووقت الطلب) هو الدليل القانوني القابل للاستخراج عند أي نزاع لاحق.
     */
    public function recordAcceptance(User $user, string $role, ?string $ip = null, ?string $userAgent = null): TermsAcceptance
    {
        $current = $this->getCurrentPublished($role);

        if (!$current) {
            throw new Exception('لا توجد نسخة منشورة من الشروط والأحكام حالياً لتسجيل الموافقة عليها.');
        }

        return TermsAcceptance::firstOrCreate(
            [
                'user_id'          => $user->id,
                'terms_version_id' => $current->id,
            ],
            [
                'role'        => $role,
                'accepted_at' => now(),
                'ip_address'  => $ip,
                'user_agent'  => $userAgent ? substr($userAgent, 0, 512) : null,
            ]
        );
    }

    /**
     * إحصاء نسبة موافقة المستخدمين المؤهَّلين على نسخة معيّنة — الأداة التي
     * يتحقق بها الأدمن كم من المستخدمين الحاليين لم يوافقوا بعد على نسخة سارية.
     */
    public function acceptanceStats(TermsVersion $version): array
    {
        $totalAccepted = TermsAcceptance::where('terms_version_id', $version->id)->count();

        $eligibleQuery = User::query();
        if ($version->audience === TermsVersion::AUDIENCE_PARENT) {
            $eligibleQuery->where('role_id', 3);
        } elseif ($version->audience === TermsVersion::AUDIENCE_DRIVER) {
            $eligibleQuery->where('role_id', 4);
        } else {
            $eligibleQuery->whereIn('role_id', [3, 4]);
        }

        $totalEligible = $eligibleQuery->count();

        return [
            'total_eligible_users' => $totalEligible,
            'total_accepted'       => $totalAccepted,
            'total_pending'        => max(0, $totalEligible - $totalAccepted),
            'acceptance_rate'      => $totalEligible > 0 ? round(($totalAccepted / $totalEligible) * 100, 1) : 0.0,
        ];
    }
}
