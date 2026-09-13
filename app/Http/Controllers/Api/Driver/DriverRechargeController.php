<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Services\Driver\DriverRechargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverRechargeController extends Controller
{
    protected DriverRechargeService $service;

    public function __construct(DriverRechargeService $service)
    {
        $this->service = $service;
    }

    /**
     * ⚠️ نفس تنظيف WalletController@paymentMethods — الحقول القديمة (name_en,
     * account_name, account_number, iban, wallet_number, instructions_ar,
     * instructions_en, target_audience, processing_type) لم تعد مستخدمة بعد
     * تبسيط شاشة الأدمن. icon_url مُبقىً عليه (يُرفع فعلياً من شاشة الأدمن).
     */
    public function paymentMethods(): JsonResponse
    {
        $methods = $this->service->getActivePaymentMethods()
            ->map(fn ($method) => [
                'id'         => (int) $method->id,
                'name_ar'    => $method->name_ar,
                'code'       => $method->code,
                'icon_url'   => $method->icon_url,
                'min_amount' => (float) $method->min_amount,
                'max_amount' => (float) $method->max_amount,
            ])
            ->values();

        return response()->json([
            'status' => true,
            'data'   => $methods,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount'            => 'required|numeric|min:1',
            'payment_method_id' => 'nullable|integer|exists:payment_methods,id',
            'reference_number'  => 'nullable|string|max:100',
            'proof_image'       => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120', // 5MB max
            'notes'             => 'nullable|string|max:500',
        ], [
            'amount.required'   => 'يرجى إدخال مبلغ الشحن.',
            'amount.min'        => 'الحد الأدنى للشحن هو 1 د.ل.',
            'proof_image.image' => 'يجب أن يكون الملف المرفق صورة للإيصال.',
            'proof_image.max'   => 'الحد الأقصى لحجم صورة الإيصال هو 5 ميجابايت.',
        ]);

        $user = auth()->user();
        $driver = $user->driver;

        if (!$driver) {
            return response()->json([
                'status'  => false,
                'message' => 'بيانات السائق غير متوفرة لهذا الحساب.',
            ], 403);
        }

        $recharge = $this->service->submitRechargeRequest(
            $driver->id,
            (float) $validated['amount'],
            $validated['payment_method_id'] ?? null,
            $validated['reference_number'] ?? null,
            $request->file('proof_image'),
            $validated['notes'] ?? null
        );

        return response()->json([
            'status'  => true,
            'message' => 'تم رفع طلب الشحن بنجاح وهو قيد مراجعة الإدارة.',
            'data'    => $recharge,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        $driver = $user->driver;

        if (!$driver) {
            return response()->json([
                'status'  => false,
                'message' => 'بيانات السائق غير متوفرة.',
            ], 403);
        }

        $history = $this->service->getDriverRechargeHistory($driver->id, $request->all());

        return response()->json([
            'status'     => true,
            'data'       => $history->items(),
            'pagination' => [
                'current_page' => $history->currentPage(),
                'last_page'    => $history->lastPage(),
                'total'        => $history->total(),
                'per_page'     => $history->perPage(),
            ],
        ]);
    }
}
