<?php

namespace App\Models\Driver;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DriverProfileChange (توافقية): تم دمج هذا النموذج وجدوله في جدول driver_approvals الموحد.
 */
class DriverProfileChange extends Model
{
    protected $table = 'driver_approvals';

    public $timestamps = true;

    protected $fillable = [
        'driver_id',
        'request_type',
        'old_values',
        'new_values',
        'status',
        'rejection_reason',
        'admin_id',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values'  => 'array',
            'new_values'  => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope('profile_change_only', function (Builder $builder) {
            $builder->where('request_type', 'ProfileChange');
        });

        static::creating(function ($model) {
            $model->request_type = 'ProfileChange';
        });
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}