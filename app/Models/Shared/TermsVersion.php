<?php

namespace App\Models\Shared;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TermsVersion extends Model
{
    protected $table = 'terms_versions';

    const STATUS_DRAFT     = 'draft';
    const STATUS_PUBLISHED = 'published';
    const STATUS_ARCHIVED  = 'archived';

    const AUDIENCE_PARENT = 'parent';
    const AUDIENCE_DRIVER = 'driver';
    const AUDIENCE_BOTH   = 'both';

    protected $fillable = [
        'version_number',
        'title',
        'audience',
        'status',
        'published_at',
        'created_by',
        'published_by',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function articles(): HasMany
    {
        return $this->hasMany(TermsArticle::class, 'terms_version_id')->orderBy('sort_order');
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(TermsAcceptance::class, 'terms_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * نسخة "لهذا الجمهور" تشمل النسخ المخصصة له بالاسم + النسخ المشتركة (both)
     */
    public function scopeForAudience(Builder $query, string $audience): Builder
    {
        return $query->where(function (Builder $q) use ($audience) {
            $q->where('audience', $audience)->orWhere('audience', self::AUDIENCE_BOTH);
        });
    }
}
