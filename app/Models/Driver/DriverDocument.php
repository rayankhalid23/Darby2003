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

    public function setAttribute($key, $value)
    {
        if (in_array($key, ['vehicle_id', 'uploaded_at', 'insurance_expiry_date', 'stamp_expiry_date', 'technical_inspection_expiry_date', 'license_expiry_date'], true) 
            && !\Illuminate\Support\Facades\Schema::hasColumn('driver_documents', $key)) {
            if (in_array($key, ['insurance_expiry_date', 'stamp_expiry_date', 'technical_inspection_expiry_date', 'license_expiry_date'], true)
                && \Illuminate\Support\Facades\Schema::hasColumn('driver_documents', 'expires_at')) {
                return parent::setAttribute('expires_at', $value);
            }
            return $this;
        }
        if ($key === 'doc_type' && !\Illuminate\Support\Facades\Schema::hasColumn('driver_documents', 'doc_type') && \Illuminate\Support\Facades\Schema::hasColumn('driver_documents', 'document_type')) {
            $key = 'document_type';
            $map = [
                'LICENSE'               => 'vehicle_license',
                'VEHICLE_LOGBOOK'       => 'vehicle_license',
                'INSURANCE'             => 'insurance',
                'TECHNICAL_INSPECTION'  => 'technical_inspection',
                'STAMP'                 => 'technical_inspection',
                'BOOKLET_PERSONAL_PAGE' => 'national_id',
            ];
            $value = $map[$value] ?? strtolower($value);
        }
        if ($key === 'status') {
            $statusMap = [
                'Verified' => 'approved',
                'Approved' => 'approved',
                'Pending'  => 'pending',
                'Rejected' => 'rejected',
                'Expired'  => 'pending',
            ];
            $value = $statusMap[$value] ?? strtolower($value);
        }
        return parent::setAttribute($key, $value);
    }

    public function getAttribute($key)
    {
        if ($key === 'doc_type' && !\Illuminate\Support\Facades\Schema::hasColumn('driver_documents', 'doc_type') && \Illuminate\Support\Facades\Schema::hasColumn('driver_documents', 'document_type')) {
            return parent::getAttribute('document_type');
        }
        return parent::getAttribute($key);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }
}