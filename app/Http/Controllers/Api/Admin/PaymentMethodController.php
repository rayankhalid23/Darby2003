<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Shared\PaymentMethod;
use App\Services\Admin\PaymentMethodService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PaymentMethodController extends Controller
{
    protected PaymentMethodService $service;

    public function __construct(PaymentMethodService $service)
    {
        $this->service = $service;
    }

    /**
     * شكل العرض الموحّد لوسيلة الدفع بلوحة الأدمن — نفس حقول الإدخال المبسّطة
     * بالضبط (name_ar, code, icon, min_amount, max_amount, is_active, sort_order)
     * زائد id/الطوابع الزمنية. كان index/show/store/update يُرجعون نموذج
     * PaymentMethod الخام بكل أعمدته القديمة (name_en, account_name,
     * account_number, iban, wallet_number, instructions_ar/en, target_audience,
     * processing_type, deleted_at) رغم أن الأدمن لم يعد يُدخلها ولا يعدّلها —
     * حقول ميتة تماماً بالعرض بعد أن أُلغيت من الإدخال.
     */
    private function transform(PaymentMethod $method): array
    {
        return [
            'id'         => $method->id,
            'name_ar'    => $method->name_ar,
            'code'       => $method->code,
            'icon_url'   => $method->icon_url,
            'min_amount' => (float) $method->min_amount,
            'max_amount' => (float) $method->max_amount,
            'is_active'  => (bool) $method->is_active,
            'sort_order' => $method->sort_order,
            'created_at' => $method->created_at,
            'updated_at' => $method->updated_at,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $methods = $this->service->getAll($request->all());

        return response()->json([
            'status'     => true,
            'data'       => $methods->getCollection()->map(fn ($m) => $this->transform($m))->values(),
            'pagination' => [
                'current_page' => $methods->currentPage(),
                'last_page'    => $methods->lastPage(),
                'total'        => $methods->total(),
                'per_page'     => $methods->perPage(),
            ],
        ]);
    }

    /**
     * ⚠️ مبسّطة عمداً: النظام يعتمد فقط على الدفع الفوري (instant_simulation) حالياً
     * — لا بوابة حقيقية ولا تحويل يدوي مُفعَّل. حقول التحويل البنكي اليدوي
     * (account_name/account_number/iban/wallet_number/instructions_*) و processing_type
     * أُزيلت من مدخلات الأدمن لتبسيط الشاشة على الأساسيات فقط؛ تبقى أعمدتها بقاعدة
     * البيانات لأي استخدام مستقبلي لكن الأدمن لا يُطالَب بتعبئتها.
     *
     * ⚠️ target_audience أُزيل أيضاً من الإدخال: وسائل الدفع بالنظام الحالي كلها
     * لشحن محفظة ولي الأمر حصراً (لا يوجد أي مسار شحن فعلي للسائق يعتمد عليها)،
     * فتُثبَّت دائماً على 'parent' بدل ترك الأدمن يختار قيمة لا معنى تشغيلياً لها.
     *
     * أيقونة/شعار الوسيلة (icon): حقل ملف اختياري — رفع صورة فعلي، لا رابط نصي
     * جاهز، ليطابق تجربة رفع الصور المعتمدة بباقي شاشات الأدمن (avatars/documents).
     * تُخزَّن وتُرجَع كـ icon_url رابط مباشر جاهز للعرض بواجهة ولي الأمر.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name_ar'         => 'required|string|max:100',
            'code'            => 'required|string|max:50|unique:payment_methods,code',
            'min_amount'      => 'nullable|numeric|min:0.5',
            'max_amount'      => 'nullable|numeric|gt:min_amount',
            'is_active'       => 'nullable|boolean',
            'sort_order'      => 'nullable|integer',
            'icon'            => 'nullable|image|mimes:jpeg,png,jpg,webp,svg|max:2048',
        ]);

        $validated['processing_type']  = 'instant_simulation';
        $validated['target_audience']  = 'parent';

        if ($request->hasFile('icon')) {
            $path = $request->file('icon')->store('payment_methods/icons', 'public');
            $validated['icon_url'] = asset('storage/' . $path);
        }
        unset($validated['icon']);

        $method = $this->service->create($validated);

        return response()->json([
            'status'  => true,
            'message' => 'تم إنشاء طريقة الدفع بنجاح.',
            'data'    => $this->transform($method),
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $method = $this->service->getById($id);

        return response()->json([
            'status' => true,
            'data'   => $this->transform($method),
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'name_ar'         => 'sometimes|required|string|max:100',
            'code'            => 'sometimes|required|string|max:50|unique:payment_methods,code,' . $id,
            'min_amount'      => 'nullable|numeric|min:0.5',
            'max_amount'      => 'nullable|numeric',
            'is_active'       => 'nullable|boolean',
            'sort_order'      => 'nullable|integer',
            'icon'            => 'nullable|image|mimes:jpeg,png,jpg,webp,svg|max:2048',
        ]);

        // ⚠️ عكس store()، هذه الحقول اختيارية هنا (سياسة "تعديل جزئي")، فلا يمكن
        // فرض gt:min_amount على حقل قد لا يُرسل أصلاً. نتحقق يدوياً من القيمتين
        // الفعليتين (المُرسلة أو القديمة من قاعدة البيانات) لمنع حفظ وسيلة دفع
        // بحد أدنى أكبر من حدها الأقصى — كانت تصبح غير قابلة للاستخدام إطلاقاً
        // لأن أي مبلغ سيفشل بأحد الشرطين دائماً.
        $existing = $this->service->getById($id);
        $effectiveMin = $validated['min_amount'] ?? $existing->min_amount;
        $effectiveMax = $validated['max_amount'] ?? $existing->max_amount;

        if ($effectiveMin !== null && $effectiveMax !== null && (float) $effectiveMin >= (float) $effectiveMax) {
            return response()->json([
                'status'  => false,
                'message' => 'الحد الأدنى للمبلغ يجب أن يكون أقل من الحد الأقصى.',
                'errors'  => ['max_amount' => ['يجب أن يكون الحد الأقصى أكبر من الحد الأدنى.']],
            ], 422);
        }

        if ($request->hasFile('icon')) {
            // نحذف الملف القديم فقط لو كان مخزَّناً محلياً على قرص public (مسار
            // يبدأ بـ storage/) — تفادياً لمحاولة حذف رابط خارجي جاهز كان موجوداً
            // من مرحلة سابقة.
            if ($existing->icon_url && str_contains($existing->icon_url, '/storage/payment_methods/icons/')) {
                $oldPath = 'payment_methods/icons/' . basename($existing->icon_url);
                Storage::disk('public')->delete($oldPath);
            }

            $path = $request->file('icon')->store('payment_methods/icons', 'public');
            $validated['icon_url'] = asset('storage/' . $path);
        }
        unset($validated['icon']);

        $method = $this->service->update($id, $validated);

        return response()->json([
            'status'  => true,
            'message' => 'تم تعديل طريقة الدفع بنجاح.',
            'data'    => $this->transform($method),
        ]);
    }

    public function toggleStatus(int $id): JsonResponse
    {
        $method = $this->service->toggleStatus($id);

        return response()->json([
            'status'  => true,
            'message' => 'تم تغيير حالة تفعيل وسيلة الدفع بنجاح.',
            'data'    => [
                'id'        => $method->id,
                'name_ar'   => $method->name_ar,
                'is_active' => (bool) $method->is_active,
            ],
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->service->delete($id);

        return response()->json([
            'status'  => true,
            'message' => 'تم حذف طريقة الدفع بنجاح.',
        ]);
    }
}
