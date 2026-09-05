<?php

namespace App\Models\Driver;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Vehicle extends Model
{
    use SoftDeletes;

    protected $table = 'vehicles';
    const DELETED_AT = 'deleted_at';

    // تفعيل الطوابع الزمنية الافتراضية والحذف الناعم القياسي
    public $timestamps = true;

    protected $fillable = [
        'driver_id', 'plate_number', 'brand', 'model', 'year', 'color', 
        'type', 'capacity_manual', 'has_ac', 'status', 'vehicle_image_url'
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function documents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(VehicleDocument::class, 'vehicle_id');
    }
}