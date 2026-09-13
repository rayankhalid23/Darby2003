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
    ];

    protected $casts = [
        'rating'           => 'integer',
        'ai_severity'      => 'integer',
        'ai_classified_at' => 'datetime',
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
