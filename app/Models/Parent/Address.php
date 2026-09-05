<?php

namespace App\Models\Parent;

use App\Models\User;
use App\Models\Shared\Zone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * نموذج العناوين الموحد لأولياء الأمور والسائقين.
 */
class Address extends Model
{
    use SoftDeletes;

    protected $table = 'addresses';

    public $timestamps = true;

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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Child::class, 'address_id');
    }
}