<?php

namespace App\Models\Shared;

use App\Models\Driver\Driver;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\Parent\ParentModel;

class DriverReview extends Model
{
    use SoftDeletes;

    protected $table = 'driver_reviews';

    protected $fillable = [
        'parent_id',
        'driver_id',
        'subscription_request_id',
        'rating',
        'comment',
        'status',
        'ai_label',
        'ai_category',
        'ai_severity',
        'ai_classified_at',
        'ai_sentiment_pred',
        'ai_sentiment_confidence',
        'ai_category_pred',
        'ai_category_confidence',
        'ai_decision_code',
        'ai_decision_confidence',
        'is_processed_in_decision',
        'is_flagged_malicious',
    ];

    protected $casts = [
        'rating'                  => 'integer',
        'ai_severity'             => 'integer',
        'ai_classified_at'        => 'datetime',
        'ai_sentiment_pred'       => 'integer',
        'ai_sentiment_confidence' => 'float',
        'ai_category_pred'        => 'integer',
        'ai_category_confidence'  => 'float',
        'ai_decision_code'        => 'integer',
        'ai_decision_confidence'  => 'float',
        'is_processed_in_decision'=> 'boolean',
        'is_flagged_malicious'    => 'boolean',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    public function subscriptionRequest(): BelongsTo
    {
        return $this->belongsTo(SubscriptionRequest::class, 'subscription_request_id');
    }
}
