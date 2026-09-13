<?php

namespace App\Services\Admin;

use App\Models\Shared\PaymentMethod;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class PaymentMethodService
{
    public function getAll(array $filters = []): LengthAwarePaginator
    {
        $query = PaymentMethod::latest('sort_order')->latest('id');

        // ⚠️ فلتر target_audience أُزيل: كل وسائل الدفع الآن لولي الأمر حصراً
        // (target_audience = 'parent' ثابتة من PaymentMethodController::store())،
        // فلا معنى تشغيلياً لفلترة قائمة كل عناصرها متطابقة بالفعل.

        if (!empty($filters['processing_type'])) {
            $query->where('processing_type', $filters['processing_type']);
        }

        if (isset($filters['is_active']) && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name_ar', 'like', $search)
                  ->orWhere('name_en', 'like', $search)
                  ->orWhere('code', 'like', $search)
                  ->orWhere('account_name', 'like', $search)
                  ->orWhere('account_number', 'like', $search);
            });
        }

        $perPage = (int) ($filters['per_page'] ?? 15);
        return $query->paginate($perPage);
    }

    public function getActiveFor(string $audience = 'both'): Collection
    {
        return PaymentMethod::active()
            ->where(function ($q) use ($audience) {
                if ($audience === 'parent') {
                    $q->whereIn('target_audience', ['parent', 'both']);
                } elseif ($audience === 'driver') {
                    $q->whereIn('target_audience', ['driver', 'both']);
                }
            })
            ->orderBy('sort_order')
            ->get();
    }

    public function getById(int $id): PaymentMethod
    {
        return PaymentMethod::findOrFail($id);
    }

    public function create(array $data): PaymentMethod
    {
        // ⚠️ fresh() إلزامي: لو الأدمن ترك min_amount/max_amount/is_active/
        // sort_order فارغة (كلها اختيارية)، القيم الافتراضية (1.00/50000.00/
        // true/0) تُطبَّق فقط على مستوى عمود قاعدة البيانات نفسها — النموذج
        // المُرجَع من create() مباشرة لا يحمل هذه القيم إطلاقاً (الخاصية غير
        // معرَّفة بمصفوفة $attributes للكائن أصلاً)، فتُقرأ null ضمنياً وتظهر
        // بالرد كـ 0/0/false/null رغم أن المخزَّن فعلياً بقاعدة البيانات صحيح
        // تماماً. تحقّقت من هذا التناقض فعلياً (رد الـ API خاطئ، صف قاعدة
        // البيانات سليم) قبل الإصلاح.
        return PaymentMethod::create($data)->fresh();
    }

    public function update(int $id, array $data): PaymentMethod
    {
        $method = PaymentMethod::findOrFail($id);
        $method->update($data);
        return $method->fresh();
    }

    public function toggleStatus(int $id): PaymentMethod
    {
        $method = PaymentMethod::findOrFail($id);
        $method->is_active = !$method->is_active;
        $method->save();
        return $method;
    }

    public function delete(int $id): bool
    {
        $method = PaymentMethod::findOrFail($id);
        return (bool) $method->delete();
    }
}
