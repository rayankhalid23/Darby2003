<?php

namespace App\Models\Shared;

use App\Models\Driver\Driver;
use App\Models\Parent\Address;
use App\Models\Parent\Child;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocationChangeRequest extends Model
{
    protected $table = 'location_change_requests';

    const POINT_TYPE_PICKUP  = 'pickup';
    const POINT_TYPE_DROPOFF = 'dropoff';

    const STATUS_PENDING   = 'pending';
    const STATUS_APPROVED  = 'approved';
    const STATUS_REJECTED  = 'rejected';
    const STATUS_CANCELLED = 'cancelled';

    const DIRECTION_TO_SCHOOL = 'to_school';
    const DIRECTION_TO_HOME   = 'to_home';
    const DIRECTION_BOTH      = 'both';

    const DEFAULT_FEE = 5.00;

    protected $fillable = [
        // ⚠️ active_subscription_id و child_id يبقيان للتوافقية مع الطلبات القديمة
        // (سجل واحد لطفل واحد). الطلبات الجديدة المُجمَّعة تتركهما null وتعتمد
        // على جدول location_change_request_children.
        'active_subscription_id',
        'child_id',
        'parent_id',
        'driver_id',
        'point_type',
        'direction',
        'change_date',
        'is_single_day',
        'new_address_id',
        'new_lat',
        'new_lng',
        'new_label',
        'distance_km',
        'fee_tier',
        'fee_amount',
        'commission_rate',
        'platform_commission_amount',
        'driver_net_fee',
        'status',
        'rejection_reason',
        'responded_at',
        'is_settled',
    ];

    protected $casts = [
        'change_date'   => 'date',
        'is_single_day' => 'boolean',
        'new_lat'       => 'float',
        'new_lng'       => 'float',
        'distance_km'   => 'decimal:2',
        'fee_amount'    => 'decimal:2',
        'commission_rate'            => 'decimal:2',
        'platform_commission_amount' => 'decimal:2',
        'driver_net_fee'             => 'decimal:2',
        'is_settled'    => 'boolean',
        'responded_at'  => 'datetime',
    ];

    public function activeSubscription(): BelongsTo
    {
        return $this->belongsTo(ActiveSubscription::class, 'active_subscription_id');
    }

    /**
     * الطفل المفرد للتوافقية مع الطلبات القديمة — لا تستخدمه للطلبات المُجمَّعة.
     * الأفضل استخدام $request->targets أو $request->children.
     */
    public function child(): BelongsTo
    {
        return $this->belongsTo(Child::class, 'child_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    public function newAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'new_address_id');
    }

    /**
     * صفوف الأطفال ضمن هذا الطلب المُجمَّع (مع الاتجاه والاشتراك والمحطة).
     */
    public function targets(): HasMany
    {
        return $this->hasMany(LocationChangeRequestChild::class, 'location_change_request_id');
    }

    /**
     * الأطفال المعنيّون بهذا الطلب عبر جدول الربط. يتضمن الطلبات القديمة أيضاً
     * (بعد الترحيل الضمني في الـ Resource).
     */
    public function children(): BelongsToMany
    {
        return $this->belongsToMany(
            Child::class,
            'location_change_request_children',
            'location_change_request_id',
            'child_id'
        )->withPivot(['active_subscription_id', 'trip_id', 'direction', 'trip_stop_id', 'applied', 'skip_reason'])
         ->withTimestamps();
    }
}
