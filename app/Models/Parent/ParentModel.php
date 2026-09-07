<?php

namespace App\Models\Parent;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * نموذج ولي الأمر المتوافق مع التطبيع V2.
 * 
 * لم يعد ولي الأمر جدولاً منفصلاً (1:1)، بل هو مستخدم مسجل في جدول users بدور 'parent' (role_id = 3).
 * هذا الكلاس يعمل كـ Proxy / Subclass فوق جدول users للحفاظ على توافق كل الخدمات والـ Controllers السابقة.
 */
class ParentModel extends User
{
    protected $table = 'users';

    protected static function booted(): void
    {
        static::addGlobalScope('parent_role', function (Builder $builder) {
            $builder->where(function ($query) {
                $query->where('role_id', 3)
                      ->orWhereHas('role', fn ($q) => $q->where('name', 'parent'));
            });
        });
    }

    public function newEloquentBuilder($query)
    {
        return new class($query) extends Builder {
            public function where($column, $operator = null, $value = null, $boolean = 'and')
            {
                if (is_string($column) && in_array($column, ['user_id', 'users.user_id', 'parents.user_id'], true)) {
                    $column = $this->qualifyColumn('id');
                }
                return parent::where($column, $operator, $value, $boolean);
            }

            public function whereIn($column, $values, $boolean = 'and', $not = false)
            {
                if (is_string($column) && in_array($column, ['user_id', 'users.user_id', 'parents.user_id'], true)) {
                    $column = $this->qualifyColumn('id');
                }
                return parent::whereIn($column, $values, $boolean, $not);
            }
        };
    }

    /**
     * للتوافق التام: إذا كان كود قديم يستدعي $parent->user، نعيد نفس السجل
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
     * للتوافق مع المحفظة والعلاقات المتعددة الأشكال: يعتبر ككائن User موحد
     */
    public function getMorphClass(): string
    {
        return \App\Models\User::class;
    }

    /**
     * جدول addresses يربط العنوان بصاحبه عبر user_id لا parent_id —
     * المفتاح الخاطئ كان يُسقط كل استعلام للعلاقة بخطأ «Unknown column».
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class, 'user_id');
    }

    /**
     * العنوان الرئيسي المفعّل لولي الأمر (واحد فقط، وكل الأطفال مسنَدون إليه).
     */
    public function defaultAddress(): HasOne
    {
        return $this->hasOne(Address::class, 'user_id')->where('is_default', true);
    }

    public function children(): HasMany
    {
        return $this->hasMany(Child::class, 'parent_id');
    }
}