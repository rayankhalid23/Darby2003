<?php

namespace App\Models\Driver;

use Illuminate\Database\Eloquent\Model;

class DriverAbsence extends Model
{
    protected $table = 'driver_absences';

    protected $fillable = [
        'driver_id',
        'absence_date',
        'reason',
    ];

    protected $casts = [
        'absence_date' => 'date',
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