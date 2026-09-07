<?php

namespace App\Models\Driver;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleDocument extends Model
{
    protected $table = 'vehicle_documents';

    public const STATE_PENDING  = 'pending';
    public const STATE_ACTIVE   = 'active';
    public const STATE_EXPIRED  = 'expired';
    public const STATE_REJECTED = 'rejected';

    protected $fillable = [
        'vehicle_id',
        'doc_type',
        'file_url',
        'expiry_date',
        'is_verified',
        'state',
    ];

    protected $appends = [
        'state_label',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'is_verified' => 'boolean',
        ];
    }

    public function getStateLabelAttribute(): string
    {
        return match ($this->state) {
            self::STATE_ACTIVE   => 'مفعلة',
            self::STATE_EXPIRED  => 'منتهية الصلاحية',
            self::STATE_REJECTED => 'مرفوضة',
            default              => 'معلقة',
        };
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    public function scopePending($query)
    {
        return $query->where('state', self::STATE_PENDING);
    }

    public function scopeActive($query)
    {
        return $query->where('state', self::STATE_ACTIVE);
    }

    public function scopeExpired($query)
    {
        return $query->where('state', self::STATE_EXPIRED);
    }

    public function scopeRejected($query)
    {
        return $query->where('state', self::STATE_REJECTED);
    }
}
