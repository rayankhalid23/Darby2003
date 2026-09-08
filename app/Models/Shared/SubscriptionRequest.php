<?php

namespace App\Models\Shared;

use App\Models\Parent\ParentModel;
use App\Models\Driver\Driver;
use App\Models\Parent\School;
use App\Models\Parent\Child;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class SubscriptionRequest extends Model
{
    protected $table = 'requests';
    
    // تم التصحيح إلى true. التعليق السابق ذكر أنه تم التفعيل ولكن القيمة كانت false مما يعطل حفظ تواريخ الإنشاء والتعديل.
    public $timestamps = false;

    // الثوابت لتجنب الخطأ في كتابة النصوص
    const DIRECTION_ONE_WAY_MORNING = 'one_way_morning';
    const DIRECTION_ONE_WAY_EVENING = 'one_way_evening';
    const DIRECTION_TWO_WAY         = 'two_way';

    const TIMING_MORNING = 'MORNING';
    const TIMING_EVENING = 'EVENING';
    const TIMING_BOTH    = 'BOTH';

    const STATUS_PENDING   = 'pending';
    const STATUS_ACQUIRED  = 'acquired';
    const STATUS_ACCEPTED  = 'accepted';
    const STATUS_REJECTED  = 'rejected';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * الحقول المشتركة لكل الطلب (نوع الاشتراك، الاتجاه، المدة، عنوان المنزل) موجودة
     * هنا فقط منذ تطبيع 2026_09 — request_children يحمل فقط ما يختلف فعلاً باختلاف
     * الطفل (المدرسة، المسافة، السعر، التوقيت المفضّل).
     */
    protected $fillable = [
        'parent_id',
        'driver_id',
        'total_price',
        'discount_amount',
        'total_amount_after_discount',
        'max_waiting_time',
        'status',
        'rejection_reason',
        'notes',
        'children_count',
        'pickup_time',
        'dropoff_time',
        'responded_at',
        // الحقول المشتركة على مستوى الطلب (مُضافة في 2026_09_06)
        'subscription_type',
        'trip_direction',
        'start_date',
        'end_date',
        'working_days_count',
        'home_label',
        'home_lat',
        'home_lng',
        'home_address_id',
    ];

    protected $casts = [
        'total_price'                 => 'decimal:2',
        'discount_amount'             => 'decimal:2',
        'total_amount_after_discount' => 'decimal:2',
        'created_at'                  => 'datetime',
        'updated_at'                  => 'datetime',
        'responded_at'                => 'datetime',
        // الحقول المشتركة الجديدة
        'start_date'                  => 'date',
        'end_date'                    => 'date',
        'home_lat'                    => 'float',
        'home_lng'                    => 'float',
    ];

    // ============================================================
    // العلاقات
    // ============================================================

    public function children(): BelongsToMany
    {
        return $this->belongsToMany(Child::class, 'request_children', 'request_id', 'child_id')
                    ->withPivot([
                        'timing',
                        'distance_km',
                        // لقطة اسم وإحداثيات المدرسة لحظة إنشاء الطلب.
                        // مصدرها التلقائي بيانات الطفل (school) في SubscriptionRequestService::createRequest،
                        // وتُقرأ منها لاحقاً بدل العلاقة الحية حتى لا يُعيد تغيير العنوان كتابة تاريخ الطلبات القديمة.
                        // ملاحظة: home_label/home_lat/home_lng انتقلت لتكون فقط على مستوى الطلب (requests)
                        // لأنها متطابقة لكل أطفال نفس الطلب — تُقرأ الآن من $subscriptionRequest مباشرة.
                        'school_label',
                        'school_lat',
                        'school_lng',
                        'price_per_child',
                        'trip_price',
                        'discount_amount',            
                        'total_amount_after_discount', // تم تصحيح الاسم هنا بدقة
                        'driver_net_price'
                    ])
                    ->withTimestamps();
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    /**
     * @deprecated عمود school_id أُزيل من جدول requests (مهاجرة 2026_08_26_131258).
     * ترجع دائماً null الآن. مدرسة كل طفل تُقرأ من $child->school أو من pivot الطلب.
     * أُبقيت مؤقتاً لأن عدة أماكن تستخدمها ضمن سلسلة ?? ولا تتأثر بعودة null.
     */
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'parent_id');
    }

    public function activeSubscriptions(): HasMany
    {
        return $this->hasMany(ActiveSubscription::class, 'subscription_request_id');
    }

    public function routes(): HasMany
    {
        return $this->hasMany(Route::class, 'subscription_request_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class, 'subscription_request_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(DriverReview::class, 'subscription_request_id');
    }

    // ============================================================
    // Scopes للفلترة
    // ============================================================

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeByParent(Builder $query, int $parentId): Builder
    {
        return $query->where('parent_id', $parentId);
    }

    public function scopeByDriver(Builder $query, int $driverId): Builder
    {
        return $query->where('driver_id', $driverId);
    }

    // ============================================================
    // Accessors
    // ============================================================

    public function getSubscriptionNumberAttribute(): string
    {
        $year = $this->created_at ? $this->created_at->format('Y') : now()->format('Y');
        
        // حماية من خطأ القيمة Null في حال استدعاء الدالة قبل عملية الـ Save في قاعدة البيانات
        $id = $this->id ?? 0;

        return 'DRBY-' . $year . '-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }

    public function getStatusTextAttribute(): string
    {
        return match($this->status) {
            self::STATUS_PENDING   => 'معلق — بانتظار رد السائق',
            self::STATUS_ACQUIRED  => 'تم الاستحواذ — السائق قيد المراجعة',
            self::STATUS_ACCEPTED  => 'تم القبول — الاشتراك ساري',
            self::STATUS_REJECTED  => 'تم الرفض من السائق',
            self::STATUS_CANCELLED => 'ملغي تلقائياً',
            default                => 'حالة غير معروفة',
        };
    }

    public function getDirectionTextAttribute(): string
    {
        return match($this->direction) {
            self::DIRECTION_ONE_WAY_MORNING => 'ذهاب صباحي فقط',
            self::DIRECTION_ONE_WAY_EVENING => 'عودة مسائية فقط',
            self::DIRECTION_TWO_WAY         => 'ذهاب وإياب',
            default                         => $this->direction ?? 'غير محدد',
        };
    }

    public function getSubscriptionTypeTextAttribute(): string
    {
        return match($this->subscription_type) {
            'single_day' => 'اشتراك يوم واحد',
            'multi_day'  => 'اشتراك عدة أيام',
            'monthly'    => 'اشتراك شهري',
            'term'       => 'اشتراك فصل دراسي',
            'yearly'     => 'اشتراك سنوي',
            default      => $this->subscription_type ?? 'غير محدد',
        };
    }

    /**
     * احتساب الحالة الفعلية الدقيقة للاشتراك بشكل ديناميكي (ساري، قادم، منتهي، ملغي، متوقف...)
     */
    public function resolveState($child = null, $activeSub = null): array
    {
        $reqStatus = strtolower($this->status ?? 'pending');
        $activeStatus = $activeSub ? strtolower($activeSub->status ?? '') : '';

        $pivot = $child?->pivot ?? null;
        $startDate = $pivot?->start_date ?? $this->start_date ?? null;
        $endDate = $pivot?->end_date ?? $this->end_date ?? null;

        // 1. الحالات الصريحة للإلغاء والرفض والتعليق
        if ($reqStatus === 'rejected') {
            return [
                'state'       => 'rejected',
                'status'      => 'rejected',
                'state_label' => 'مرفوض',
                'status_text' => 'تم الرفض من السائق',
                'is_active'   => false,
            ];
        }

        if ($reqStatus === 'cancelled' || $activeStatus === 'cancelled') {
            return [
                'state'       => 'cancelled',
                'status'      => 'cancelled',
                'state_label' => 'ملغي',
                'status_text' => 'اشتراك ملغي',
                'is_active'   => false,
            ];
        }

        if ($reqStatus === 'pending') {
            return [
                'state'       => 'pending',
                'status'      => 'pending',
                'state_label' => 'قيد الانتظار',
                'status_text' => 'معلق — بانتظار رد السائق',
                'is_active'   => false,
            ];
        }

        if ($activeStatus === 'paused') {
            return [
                'state'       => 'paused',
                'status'      => 'paused',
                'state_label' => 'متوقف مؤقتاً',
                'status_text' => 'اشتراك متوقف مؤقتاً',
                'is_active'   => false,
            ];
        }

        if ($reqStatus === 'completed' || $activeStatus === 'completed') {
            return [
                'state'       => 'completed',
                'status'      => 'completed',
                'state_label' => 'مكتمل',
                'status_text' => 'اشتراك مكتمل',
                'is_active'   => false,
            ];
        }

        // 2. الحالات المقبولة أو النشطة (حساب الحالة وفق التاريخ)
        if (in_array($reqStatus, ['accepted', 'active', 'contract_offered'])) {
            if ($startDate && \Carbon\Carbon::parse($startDate)->startOfDay()->isFuture()) {
                return [
                    'state'       => 'pending_start',
                    'status'      => 'pending_start',
                    'state_label' => 'قادم (لم يبدأ بعد)',
                    'status_text' => 'اشتراك قادم — لم يبدأ بعد',
                    'is_active'   => false,
                ];
            }

            if ($endDate && \Carbon\Carbon::parse($endDate)->endOfDay()->isPast()) {
                return [
                    'state'       => 'completed',
                    'status'      => 'completed',
                    'state_label' => 'منتهي',
                    'status_text' => 'اشتراك منتهي الصلاحية',
                    'is_active'   => false,
                ];
            }

            // فحص هل السائق مسجل غياباً اليوم
            $driverId = $this->driver_id ?? $activeSub?->driver_id;
            if ($driverId && \App\Models\Driver\DriverAbsence::where('driver_id', $driverId)->whereDate('absence_date', \Carbon\Carbon::today()->toDateString())->exists()) {
                return [
                    'state'       => 'driver_absent',
                    'status'      => 'driver_absent',
                    'state_label' => 'غياب السائق (متوقف مؤقتاً)',
                    'status_text' => 'السائق مسجل كغائب اليوم',
                    'is_active'   => false,
                ];
            }

            return [
                'state'       => 'active',
                'status'      => 'active',
                'state_label' => 'ساري ومفعل',
                'status_text' => 'اشتراك نشط وساري',
                'is_active'   => true,
            ];
        }

        return [
            'state'       => $reqStatus,
            'status'      => $reqStatus,
            'state_label' => $reqStatus,
            'status_text' => $reqStatus,
            'is_active'   => $reqStatus === 'active',
        ];
    }
}