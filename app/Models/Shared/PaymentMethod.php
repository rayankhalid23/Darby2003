<?php

namespace App\Models\Shared;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class PaymentMethod extends Model
{
    use SoftDeletes;

    protected $table = 'payment_methods';

    protected $fillable = [
        'name_ar',
        'name_en',
        'code',
        'target_audience', // parent, driver, both
        'processing_type', // instant_simulation, manual_proof
        'account_name',
        'account_number',
        'iban',
        'wallet_number',
        'icon_url',
        'min_amount',
        'max_amount',
        'instructions_ar',
        'instructions_en',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForParents(Builder $query): Builder
    {
        return $query->whereIn('target_audience', ['parent', 'both']);
    }

    public function scopeForDrivers(Builder $query): Builder
    {
        return $query->whereIn('target_audience', ['driver', 'both']);
    }

    public function getIconUrlAttribute(?string $value): ?string
    {
        // 1. إذا وُجدت قيمة مخصصة محفوظة للوسيلة
        if (!empty($value) && trim($value) !== '') {
            $trimmed = trim($value);

            // لو كان رابطاً خارجياً بحتاً (CDN خارجي لا يتبع storage أو api/media)
            if ((str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://'))
                && !str_contains($trimmed, 'storage/')
                && !str_contains($trimmed, 'api/media/')
                && !str_contains($trimmed, 'payment_methods/')) {
                return $trimmed;
            }

            return \App\Http\Controllers\Api\Shared\MediaController::urlFor($trimmed) ?: asset($trimmed);
        }

        // 2. إذا لم تُرفع أيقونة، نرجع الأيقونة الافتراضية المناسبة حسب رمز وسيلة الدفع (code)
        $code = strtolower(trim((string) $this->code));
        $availableIcons = ['sadad', 'mobicash', 'sahara_bank', 'aljumhouria_bank', 'tadawul', 'moamalat'];
        $iconFile = in_array($code, $availableIcons, true) ? "{$code}.png" : 'default.png';

        return url("api/media/payment_methods/icons/{$iconFile}");
    }

    public function driverRecharges(): HasMany
    {
        return $this->hasMany(\App\Models\Driver\DriverRechargeRequest::class, 'payment_method_id');
    }

    public function parentRecharges(): HasMany
    {
        return $this->hasMany(\App\Models\Shared\RechargeRequest::class, 'payment_method_id');
    }
}
