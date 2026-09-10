<?php

namespace App\Models\Driver;

use Illuminate\Database\Eloquent\Model;

class DriverAbsence extends Model
{
    protected $table = 'driver_absences';

    // ⚠️ لم تكن هذه الثوابت معرَّفة إطلاقاً رغم استخدامها في TripLifecycleService
    // ::setDriverAbsence() و AdminDriverService (اعتماد/رفض طلب الغياب) — أي استدعاء
    // لأي من المسارين كان يفشل فوراً بخطأ فادح "Undefined constant". تحقّقت من هذا
    // فعلياً: تسجيل غياب سائق برحلات محددة يفشل بالكامل حالياً.
    public const STATUS_PENDING  = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'driver_id',
        'absence_date',
        'reason',
        'status',
        'reviewed_by',
        'reviewed_at',
        'admin_notes',
    ];

    protected $casts = [
        'absence_date' => 'date',
        'reviewed_at'  => 'datetime',
    ];

    public function driver()
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    public function trips(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(\App\Models\Shared\Trip::class, 'driver_absence_trips', 'driver_absence_id', 'trip_id');
    }
}