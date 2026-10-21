<?php

namespace App\Models\Driver;

use App\Models\User;
use App\Models\Shared\Zone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

class Address extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'addresses';

    protected $fillable = [
        'user_id',
        'zone_id',
        'label',
        'lat',
        'lng',
        'is_default',
    ];

    protected $casts = [
        'lat'        => 'float',
        'lng'        => 'float',
        'is_default' => 'boolean',
    ];

    /**
     * علاقة العنوان بالمستخدم (سواء كان سائقاً أو ولي أمر)
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * علاقة العنوان بالمنطقة الجغرافية
     */
    public function zone()
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }
}
