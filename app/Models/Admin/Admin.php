<?php

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * نموذج المشرف/الأدمن المتوافق مع التطبيع V2.
 *
 * لم يعد المشرف جدولاً منفصلاً (1:1)، بل هو مستخدم مسجل في جدول users بأحد الأدوار الإدارية (staff).
 * وحقل created_by صار مفتاحاً ذاتياً أصيلاً داخل جدول users.
 * هذا الكلاس يعمل كـ Proxy / Subclass فوق جدول users للحفاظ على توافق كل الخدمات والـ Controllers السابقة.
 */
class Admin extends User
{
    protected $table = 'users';

    protected static function booted(): void
    {
        static::addGlobalScope('staff_role', function (Builder $builder) {
            $builder->where(function ($query) {
                $query->whereIn('role_id', [1, 2, 5, 6, 7, 8])
                      ->orWhereHas('role', fn ($q) => $q->where('kind', 'staff'));
            });
        });
    }

    /**
     * للتوافق التام: إذا استدعى الكود $admin->user، نعيد نفس السجل
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'id');
    }

    /**
     * للتوافق التام: استرجاع user_id يعيد المعرف الأساسي للمستخدم
     */
    public function getUserIdAttribute(): int
    {
        return (int) $this->id;
    }

    /**
     * علاقة المشرف بالشخص الذي قام بإنشائه (مرجع ذاتي في users.created_by)
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}