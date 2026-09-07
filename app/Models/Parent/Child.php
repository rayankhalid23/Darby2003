<?php

namespace App\Models\Parent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use App\Models\User;
use App\Enums\Shared\SchoolStage;

class Child extends Model
{
    use SoftDeletes;

    protected $table = 'children';

    public $timestamps = true;

    protected $fillable = [
        'parent_id',
        'school_id',
        'address_id',
        'full_name',
        'birth_date',
        'gender',
        'grade',
        'photo_url',
        'medical_notes',
        'notification_radius',
        'qr_code_token',
        // الحقول المدمجة من child_logistics
        'preferred_time_slot',
        'pickup_time',
        'dropoff_time',
        'is_active',
    ];

    protected $casts = [
        'birth_date'          => 'date',
        'is_active'           => 'boolean',
        'grade'               => 'integer',
        'notification_radius' => 'integer',
    ];

    /**
     * توليد توكن فريد للـ QR Code تلقائياً عند إنشاء الطفل
     */
    protected static function booted(): void
    {
        parent::booted();

        static::creating(function ($child) {
            if (empty($child->qr_code_token)) {
                $child->qr_code_token = 'CHLD-' . Str::upper(Str::random(6)) . '-' . time();
            }
        });
    }

    /**
     * العلاقات (Relationships)
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Parent\School::class, 'school_id');
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Parent\Address::class, 'address_id');
    }

    public function pickupAddress(): BelongsTo
    {
        return $this->address();
    }

    public function dropoffAddress(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Parent\School::class, 'school_id');
    }

    /**
     * دعم التوافقية العكسية للكود الذي يشحن علاقة logistics
     */
    public function logistics(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(self::class, 'id', 'id');
    }

    /**
     * الملحقات (Attributes)
     */
    public function getNameAttribute(): string
    {
        return (string) ($this->full_name ?? '');
    }

    public function getAgeAttribute(): int
    {
        return $this->birth_date ? $this->birth_date->age : 0;
    }

    /** اشتقاق المرحلة الدراسية من رقم الصف تلقائياً */
    public function getSchoolStageAttribute(): string
    {
        return SchoolStage::fromGrade((int) $this->grade)->value;
    }

    public function getSchoolStageLabelAttribute(): string
    {
        return SchoolStage::fromGrade((int) $this->grade)->label();
    }
}