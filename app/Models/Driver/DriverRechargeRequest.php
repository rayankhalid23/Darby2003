<?php

namespace App\Models\Driver;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Shared\PaymentMethod;
use App\Models\User;

class DriverRechargeRequest extends Model
{
    protected $table = 'driver_recharge_requests';

    const STATUS_PENDING  = 'pending';
    const STATUS_APPROVED = 'approved';
    const STATUS_REJECTED = 'rejected';

    protected $fillable = [
        'driver_id',
        'payment_method_id',
        'amount',
        'proof_image_url',
        'reference_number',
        'status',
        'admin_id',
        'rejection_reason',
        'notes',
        'approved_at',
        'rejected_at',
    ];

    protected $casts = [
        'amount'      => 'decimal:2',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'driver_id');
    }

    /**
     * withTrashed(): طلب الشحن مستند تاريخي — لو الأدمن حذف وسيلة الدفع لاحقاً
     * (حذف ناعم) يجب أن يبقى اسمها ظاهراً في مراجعة الطلبات القديمة، لا أن
     * تختفي فجأة ويظهر "بلا وسيلة دفع" لطلب دُفع بالفعل عبرها.
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method_id')->withTrashed();
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}
