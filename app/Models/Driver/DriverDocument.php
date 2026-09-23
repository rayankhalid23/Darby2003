<?php

namespace App\Models\Driver;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class DriverDocument extends Model
{
    protected $table = 'driver_documents';

    // إيقاف التعامل التلقائي مع أي طوابع زمنية
    public $timestamps = false;

    protected $fillable = [
        'driver_id', 'vehicle_id', 'doc_type', 'document_type', 'file_url',
        'license_expiry_date', 'insurance_expiry_date',
        'stamp_expiry_date', 'technical_inspection_expiry_date',
        'expiry_notified_milestone', 'status',
        'reviewed_by', 'feedback', 'uploaded_at', 'expires_at'
    ];

    // تحديث uploaded_at تلقائياً عند إنشاء سجل جديد في حال كان العمود موجوداً
    protected static function booted()
    {
        static::creating(function ($model) {
            if (\Illuminate\Support\Facades\Schema::hasColumn('driver_documents', 'uploaded_at')) {
                $model->uploaded_at = Carbon::now();
            }
        });
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}