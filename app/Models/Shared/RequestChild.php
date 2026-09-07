<?php

namespace App\Models\Shared;

use App\Models\Parent\Child;
use App\Models\Parent\School;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * تمثيل Eloquent حقيقي لجدول request_children — يُستخدم في العلاقات الجديدة
 * (مثل ActiveSubscription::requestChild()) بدل الوصول له فقط عبر pivot الـ
 * belongsToMany في SubscriptionRequest::children().
 */
class RequestChild extends Model
{
    protected $table = 'request_children';

    public function request(): BelongsTo
    {
        return $this->belongsTo(SubscriptionRequest::class, 'request_id');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class, 'child_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }
}
