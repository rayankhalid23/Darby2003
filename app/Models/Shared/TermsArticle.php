<?php

namespace App\Models\Shared;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TermsArticle extends Model
{
    protected $table = 'terms_articles';

    protected $fillable = [
        'terms_version_id',
        'article_number',
        'title',
        'body',
        'sort_order',
    ];

    public function version(): BelongsTo
    {
        return $this->belongsTo(TermsVersion::class, 'terms_version_id');
    }
}
