<?php

namespace App\Models\Shared;

use App\Models\Admin\Admin;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RechargeRequest extends Model
{
    protected $table = 'recharge_requests';

    protected $fillable = [
        'parent_id',
        'payment_method_id',
        'amount',
        'payment_method',
        'reference_number',
        'transaction_ref',
        'session_token',
        'gateway_payload',
        'status',
        'notes',
        'admin_id',
        'completed_at',
    ];

    protected $casts = [
        'amount'          => 'decimal:2',
        'gateway_payload' => 'array',
        'completed_at'    => 'datetime',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'parent_id');
    }

    /**
     * withTrashed(): نفس منطق DriverRechargeRequest::paymentMethod() — سجل شحن
     * مكتمل يجب أن يبقى مرتبطاً باسم وسيلة الدفع حتى لو حذفها الأدمن لاحقاً.
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id')->withTrashed();
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'admin_id');
    }
}
