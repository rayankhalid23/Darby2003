<?php

namespace App\Models\Driver;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleDocument extends Model
{
    protected $table = 'vehicle_documents';

    protected $fillable = [
        'vehicle_id',
        'doc_type',
        'file_url',
        'expiry_date',
        'is_verified',
    ];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date',
            'is_verified' => 'boolean',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'vehicle_id');
    }

    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }
}
