<?php

namespace App\Models\Parent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Shared\Zone;

class School extends Model
{
    // تحديد اسم الجدول الفعلي
    protected $table = 'schools'; 

    public $timestamps = true;

    /**
     * الحقول القابلة للتعبئة (Mass Assignable)
     */
    protected $fillable = [
        'name',
        'lat',
        'lng',
        'address',
        'address_text',
        'status',
        'zone_id', // لربط المدرسة بمنطقة جغرافية دقيقة
    ];

    /**
     * 🗺️ علاقة المدرسة بالمنطقة الجغرافية الدقيقة (BelongsTo)
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class, 'zone_id');
    }

    /**
     * علاقة المدرسة بالأطفال (المدرسة الواحدة بها العديد من الأطفال)
     */
    public function children(): HasMany
    {
        return $this->hasMany(\App\Models\Parent\Child::class, 'school_id');
    }
}