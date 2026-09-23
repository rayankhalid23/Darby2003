<?php

namespace App\Models\Shared;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripDispute extends Model
{
    protected $table = 'trip_disputes';

    protected $fillable = [
        'trip_id',
        'parent_id',
        'driver_id',
        'reason',
        'status',
        'resolution_notes',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class, 'trip_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'parent_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Driver\Driver::class, 'driver_id');
    }
}
