<?php

namespace App\Models\Shared;

use App\Models\Admin\Admin;
use App\Models\Driver\Driver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiDecisionAudit extends Model
{
    protected $table = 'ai_decision_audits';

    protected $fillable = [
        'driver_id',
        'review_id',
        'current_rating',
        'previous_warnings',
        'trips_count',
        'sentiment_pred',
        'sentiment_confidence',
        'category_pred',
        'category_confidence',
        'decision_code',
        'decision_name',
        'decision_confidence',
        'probabilities',
        'action_applied',
        'action_details',
        'suspended_until',
        'admin_override',
        'admin_id',
    ];

    protected $casts = [
        'current_rating'       => 'float',
        'previous_warnings'    => 'integer',
        'trips_count'          => 'integer',
        'sentiment_pred'       => 'integer',
        'sentiment_confidence' => 'float',
        'category_pred'        => 'integer',
        'category_confidence'  => 'float',
        'decision_code'        => 'integer',
        'decision_confidence'  => 'float',
        'probabilities'        => 'array',
        'action_details'       => 'array',
        'suspended_until'      => 'datetime',
        'admin_override'       => 'boolean',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(DriverReview::class, 'review_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
