<?php

namespace App\Models\Driver;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * نموذج طلبات ومراجعات السائقين الموحد.
 * يدمج طلبات التسجيل الجديد وتعديل الملف الشخصي وتحديث المركبة في سجل واحد موحد.
 */
class DriverApproval extends Model
{
    protected $table = 'driver_approvals';

    public $timestamps = true;

    protected $fillable = [
        'driver_id',
        'request_type',     // 'Registration', 'ProfileChange', 'VehicleUpdate'
        'status',           // 'Pending', 'Approved', 'Rejected'
        'old_values',
        'new_values',
        'admin_id',
        'rejection_reason',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values'  => 'array',
            'new_values'  => 'array',
            'reviewed_at' => 'datetime',
            'created_at'  => 'datetime',
            'updated_at'  => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    // Scopes
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'Pending');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'Approved');
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', 'Rejected');
    }

    public function scopeRegistrations(Builder $query): Builder
    {
        return $query->where('request_type', 'Registration');
    }

    public function scopeProfileChanges(Builder $query): Builder
    {
        return $query->where('request_type', 'ProfileChange');
    }
}