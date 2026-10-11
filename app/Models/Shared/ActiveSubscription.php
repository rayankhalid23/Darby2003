<?php

namespace App\Models\Shared;

use App\Models\Parent\Child;
use App\Models\Driver\Driver;
use App\Models\User;
use App\Models\Parent\School;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class ActiveSubscription extends Model
{
    protected $table = 'active_subscriptions';

    protected $fillable = [
        'subscription_request_id',
        'request_child_id',
        'route_id',
        'pickup_lat',
        'pickup_lng',
        'pickup_label',
        'dropoff_lat',
        'dropoff_lng',
        'dropoff_label',
        'pickup_time',
        'dropoff_time',
        'sort_order',
        'status',
        'cancelled_at',
        'cancelled_by',
        'cancellation_reason',
    ];

    protected $casts = [
        'pickup_lat'  => 'float',
        'pickup_lng'  => 'float',
        'dropoff_lat' => 'float',
        'dropoff_lng' => 'float',
    ];

    // ============================================================
    // العلاقات
    // ============================================================

    /**
     * للتوافقية مع الاستدعاءات القديمة: يعيد طلب الاشتراك كعقد معتمد
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(SubscriptionRequest::class, 'subscription_request_id');
    }

    /**
     * طلب الاشتراك الأصلي — مصدر كل بيانات الاتفاق (المدة، السعر، الفترة، الاتجاه،
     * driver_id، parent_id).
     */
    public function subscriptionRequest(): BelongsTo
    {
        return $this->belongsTo(SubscriptionRequest::class, 'subscription_request_id');
    }

    /**
     * صف الطفل داخل الطلب — مصدر child_id ومدرسته ومسافته وسعره.
     */
    public function requestChild(): BelongsTo
    {
        return $this->belongsTo(RequestChild::class, 'request_child_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class, 'route_id');
    }

    /**
     * العلاقات التوافقية مع الجداول المرتبطة عبر HasOneThrough
     * لتسهيل الاستعلام بـ whereHas و with دون اشتراط وجود الأعمدة مباشرة في جدول active_subscriptions.
     */
    public function child(): HasOneThrough
    {
        return $this->hasOneThrough(
            Child::class,
            RequestChild::class,
            'id',               // Foreign key on request_children (id)
            'id',               // Foreign key on children (id)
            'request_child_id', // Local key on active_subscriptions
            'child_id'          // Local key on request_children
        );
    }

    public function driver(): HasOneThrough
    {
        return $this->hasOneThrough(
            Driver::class,
            SubscriptionRequest::class,
            'id',                      // Foreign key on subscription_requests (id)
            'id',                      // Foreign key on drivers (id)
            'subscription_request_id', // Local key on active_subscriptions
            'driver_id'                // Local key on subscription_requests
        );
    }

    public function parent(): HasOneThrough
    {
        return $this->hasOneThrough(
            User::class,
            SubscriptionRequest::class,
            'id',                      // Foreign key on subscription_requests (id)
            'id',                      // Foreign key on users (id)
            'subscription_request_id', // Local key on active_subscriptions
            'parent_id'                // Local key on subscription_requests
        );
    }

    public function school(): HasOneThrough
    {
        return $this->hasOneThrough(
            School::class,
            RequestChild::class,
            'id',               // Foreign key on request_children (id)
            'id',               // Foreign key on schools (id)
            'request_child_id', // Local key on active_subscriptions
            'school_id'         // Local key on request_children
        );
    }

    // ============================================================
    // Accessors — تعويض عمود child_id/driver_id/parent_id/school_id المحذوفة.
    // كل قراءة مباشرة ($sub->child_id) وكل eager-load (->with(['child'])) تمر من
    // هنا تلقائياً لأن BelongsTo يستخدم getAttribute() لا القيمة الخام.
    // ============================================================

    public function getChildIdAttribute(): ?int
    {
        return $this->requestChild?->child_id;
    }

    public function getDriverIdAttribute(): ?int
    {
        return $this->subscriptionRequest?->driver_id;
    }

    public function getParentIdAttribute(): ?int
    {
        return $this->subscriptionRequest?->parent_id;
    }

    public function getSchoolIdAttribute(): ?int
    {
        return $this->requestChild?->school_id;
    }

    // ============================================================
    // Scopes — بديل الاستعلام المباشر ::where('driver_id'|'child_id'|'parent_id', ...)
    // ============================================================

    public function scopeForDriver(Builder $query, int $driverId): Builder
    {
        return $query->whereHas('subscriptionRequest', fn (Builder $q) => $q->where('driver_id', $driverId));
    }

    public function scopeForParent(Builder $query, int $parentId): Builder
    {
        return $query->whereHas('subscriptionRequest', fn (Builder $q) => $q->where('parent_id', $parentId));
    }

    public function scopeForChild(Builder $query, int $childId): Builder
    {
        return $query->whereHas('requestChild', fn (Builder $q) => $q->where('child_id', $childId));
    }

    public function scopeForChildren(Builder $query, array $childIds): Builder
    {
        return $query->whereHas('requestChild', fn (Builder $q) => $q->whereIn('child_id', $childIds));
    }

    public function scopeExcludingChildren(Builder $query, array $childIds): Builder
    {
        return $query->whereDoesntHave('requestChild', fn (Builder $q) => $q->whereIn('child_id', $childIds));
    }

    /**
     * احتساب الحالة الفعلية الدقيقة للاشتراك
     */
    public function resolveState(): array
    {
        $req = $this->subscriptionRequest;
        if ($req) {
            return $req->resolveState($this->child, $this);
        }

        $status = strtolower($this->status ?? 'active');

        if ($status === 'active' && $this->driver_id && \App\Models\Driver\DriverAbsence::where('driver_id', $this->driver_id)->whereDate('absence_date', \Carbon\Carbon::today()->toDateString())->exists()) {
            return [
                'state'       => 'driver_absent',
                'status'      => 'driver_absent',
                'state_label' => 'غياب السائق (متوقف مؤقتاً)',
                'status_text' => 'السائق مسجل كغائب اليوم',
                'is_active'   => false,
            ];
        }

        return [
            'state'       => $status,
            'status'      => $status,
            'state_label' => match($status) {
                'active'    => 'ساري ومفعل',
                'paused'    => 'متوقف مؤقتاً',
                'cancelled' => 'ملغي',
                'completed' => 'مكتمل',
                default     => $status,
            },
            'status_text' => $status,
            'is_active'   => $status === 'active',
        ];
    }
}
