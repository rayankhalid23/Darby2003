<?php

namespace App\Models\Shared;

use App\Models\Parent\Child;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * صف تفصيلي واحد داخل طلب تغيير موقع مُجمَّع:
 *  - طفل واحد
 *  - اتجاه واحد (ذهاب أو إياب)
 *  - اشتراك نشط واحد
 *  - محطة رحلة اختيارية (تُحلّ عند الموافقة)
 *
 * ⚠️ الرسم والمسافة على مستوى الطلب الأب لا هنا، لأن التعديل هو انحراف واحد للسائق
 *    يخدم كل الأطفال في نفس المجموعة.
 */
class LocationChangeRequestChild extends Model
{
    protected $table = 'location_change_request_children';

    protected $fillable = [
        'location_change_request_id',
        'child_id',
        'active_subscription_id',
        'trip_id',
        'direction',
        'previous_lat',
        'previous_lng',
        'previous_label',
        'trip_stop_id',
        'applied',
        'skip_reason',
    ];

    protected $casts = [
        'previous_lat' => 'float',
        'previous_lng' => 'float',
        'applied'      => 'boolean',
    ];

    const DIRECTION_TO_SCHOOL = 'to_school';
    const DIRECTION_TO_HOME   = 'to_home';

    public function request(): BelongsTo
    {
        return $this->belongsTo(LocationChangeRequest::class, 'location_change_request_id');
    }

    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class, 'child_id');
    }

    public function activeSubscription(): BelongsTo
    {
        return $this->belongsTo(ActiveSubscription::class, 'active_subscription_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }

    public function tripStop(): BelongsTo
    {
        return $this->belongsTo(TripStop::class, 'trip_stop_id');
    }
}
