<?php

namespace App\Http\Controllers\Api\Parent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Parent\RechargeWalletRequest;
use App\Services\Parent\WalletRechargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    protected WalletRechargeService $rechargeService;

    public function __construct(WalletRechargeService $rechargeService)
    {
        $this->rechargeService = $rechargeService;
    }

    /**
     * 1️⃣ جلب طرق الدفع المفعلة المتاحة لولي الأمر
     */
    public function paymentMethods(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $this->rechargeService->getPaymentMethods(),
        ]);
    }

    /**
     * 2️⃣ بدء عملية الشحن لولي الأمر والحصول على توكن جلسة الدفع ورابط المحاكاة
     */
    public function initiateRecharge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount'            => 'required|numeric|min:0.5',
            'payment_method'    => 'nullable|string|max:50',
            'payment_method_id' => 'nullable|integer|exists:payment_methods,id',
            'reference_number'  => 'nullable|string|max:100',
        ], [
            'amount.required' => 'يرجى إدخال مبلغ الشحن.',
            'amount.min'      => 'الحد الأدنى للشحن هو نصف دينار ليبي.',
        ]);

        $userId = auth()->id();
        $methodIdentifier = $validated['payment_method_id'] ?? $validated['payment_method'] ?? 'sadad';

        $sessionData = $this->rechargeService->initiateRecharge(
            $userId,
            (float) $validated['amount'],
            $methodIdentifier,
            $validated['reference_number'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'تم إنشاء جلسة الشحن وجاهزة لتنفيذ عملية الدفع.',
            'data'    => $sessionData,
        ], 201);
    }

    /**
     * 3️⃣ تنفيذ محاكاة بوابة الدفع وتأكيد السداد وتحديث رصيد ولي الأمر فوراً
     */
    public function mockPay(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_token' => 'required|string',
            'card_number'   => 'nullable|string|max:30',
            'card_holder'   => 'nullable|string|max:100',
            'expiry_date'   => 'nullable|string|max:10',
            'cvv'           => 'nullable|string|max:5',
            'otp'           => 'nullable|string|max:10',
        ], [
            'session_token.required' => 'توكن جلسة الدفع مطلوب.',
        ]);

        // 🔒 بوابة الدفع الوهمية (mock) تُقيّد على بيئات التطوير/الاختبار فقط.
        // بدون هذا القيد كان بإمكان أي مستخدم شحن محفظته بأموال حقيقية مجاناً
        // عبر إرسال رقم بطاقة صوري، وهو خطر مالي جسيم في الإنتاج.
        if (! app()->environment(['local', 'development', 'testing', 'staging'])) {
            return response()->json([
                'success'    => false,
                'error_code' => 'MOCK_GATEWAY_DISABLED',
                'message'    => 'بوابة الدفع التجريبية غير متاحة. يرجى استخدام وسيلة دفع حقيقية.',
            ], 403);
        }

        $result = $this->rechargeService->processMockPayment(
            $validated['session_token'],
            $request->only(['card_number', 'card_holder', 'expiry_date', 'cvv', 'otp'])
        );

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'data'    => $result,
        ]);
    }

    /**
     * الشحن المباشر (Legacy)
     */
    public function recharge(RechargeWalletRequest $request): JsonResponse
    {
        $userId = auth()->id();

        $sessionData = $this->rechargeService->initiateRecharge(
            $userId,
            $request->validated('amount'),
            $request->validated('payment_method'),
            $request->validated('reference_number')
        );

        return response()->json([
            'success' => true,
            'message' => 'تم بدء عملية الشحن بنجاح.',
            'data'    => $sessionData,
        ], 201);
    }

    /**
     * 4️⃣ عرض رصيد محفظة ولي الأمر الحالي
     */
    public function balance(): JsonResponse
    {
        $user = auth()->user();
        $balance = (float) (($user->balance ?? 0) / 100);

        return response()->json([
            'success' => true,
            'data'    => [
                'balance'  => round($balance, 2),
                'currency' => 'د.ل',
            ],
        ]);
    }

    /**
     * 5️⃣ حجز مبلغ الرحلة اليومية في أمانات المحفظة
     */
    public function holdTripAmount(\Illuminate\Http\Request $request): JsonResponse
    {
        // ⚠️ لا `amount` من العميل. كان هذا المنفذ يقبل المبلغ الذي يرسله التطبيق
        // ويخصمه كما هو، فيقرّر ولي الأمر بنفسه كم يدفع مقابل الرحلة. السعر يُحسب
        // على الخادم من اشتراك الطفل، ولا يُقبل من الطلب إطلاقاً.
        $request->validate([
            'trip_id' => 'required|integer|exists:trips,id',
        ]);

        $trip = \App\Models\Shared\Trip::findOrFail($request->trip_id);

        // ⚠️ ولا حجز على رحلة لا تخص أطفال هذا المستخدم: كان أي ولي أمر مسجّل
        // يستطيع الحجز على رحلة أي عائلة أخرى.
        if (!$this->tripBelongsToParent($trip, (int) auth()->id())) {
            return response()->json([
                'success' => false,
                'message' => 'هذه الرحلة لا تخص أياً من أطفالك.',
            ], 403);
        }

        $ledgerService = app(\App\Services\Shared\FinancialLedgerService::class);
        $amount = $this->resolveTripPriceForParent($trip, (int) auth()->id());

        if ($amount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'تعذّر تحديد قيمة هذه الرحلة من بيانات الاشتراك.',
            ], 422);
        }

        $hold = $ledgerService->holdTripAmount($trip, auth()->id(), $amount);

        return response()->json([
            'success' => true,
            'message' => 'تم حجز مبلغ الرحلة بنجاح في أمانات المحفظة.',
            'data'    => [
                'id'           => $hold->id,
                'trip_id'      => $hold->trip_id,
                'driver_id'    => $hold->driver_id,
                'amount'       => round($hold->amount / 100, 2),
                'hold_status'  => $hold->hold_status,
                'held_at'      => $hold->held_at,
                'captured_at'  => $hold->captured_at,
                'available_at' => $hold->available_at,
                'disputed_at'  => $hold->disputed_at,
            ],
        ], 201);
    }

    /**
     * هل تخدم هذه الرحلة طفلاً من أطفال هذا المستخدم؟
     *
     * الربط: الرحلة → مسارها → الاشتراكات النشطة على ذلك المسار → ولي الأمر.
     * (`active_subscriptions.parent_id` يخزّن User::id.)
     */
    private function tripBelongsToParent(\App\Models\Shared\Trip $trip, int $parentUserId): bool
    {
        if (!$trip->route_id) {
            return false;
        }

        return \App\Models\Shared\ActiveSubscription::where('route_id', $trip->route_id)
            ->forParent($parentUserId)
            ->exists();
    }

    /**
     * حصة الرحلة الواحدة من قيمة اشتراك هذا الطفل — تُحسب على الخادم بنفس
     * المعادلة التي يوزّع بها نظام التسوية المال عند اكتمال الرحلة، فلا يختلف
     * ما يُحجز عمّا يُصرف.
     */
    private function resolveTripPriceForParent(\App\Models\Shared\Trip $trip, int $parentUserId): float
    {
        $subscriptionIds = \App\Models\Shared\ActiveSubscription::where('route_id', $trip->route_id)
            ->forParent($parentUserId)
            ->pluck('subscription_request_id')
            ->filter()
            ->unique();

        $total = 0.0;

        foreach ($subscriptionIds as $requestId) {
            $finance = \App\Models\Shared\PlatformFinance::where('subscription_request_id', $requestId)
                ->latest('id')
                ->first();

            if ($finance) {
                $expected = max(1, (int) ($finance->expected_trips_count ?? 1));
                $total += round(((float) $finance->total_amount) / $expected, 2);
                continue;
            }

            $request = \App\Models\Shared\SubscriptionRequest::find($requestId);
            if (!$request) {
                continue;
            }

            $expected = max(1, (int) ($request->days_count ?? 1));
            $total += round(((float) ($request->total_amount_after_discount ?? $request->total_price ?? 0)) / $expected, 2);
        }

        return round($total, 2);
    }

    /**
     * 6️⃣ تقديم اعتراض مالي على رحلة
     */
    public function openDispute(\Illuminate\Http\Request $request, int $tripId): JsonResponse
    {
        $request->validate([
            'reason' => 'required|string|min:5|max:1000',
        ]);

        // ⚠️ فحص الملكية إلزامي: بدونه كان أي ولي أمر مسجّل يفتح نزاعاً على رحلة
        // أي عائلة أخرى، فيتحوّل الحجز إلى `disputed` ويُجمَّد مال السائق حتى
        // تدخّل الإدارة — تعطيل مستحقات من طرف ثالث لا علاقة له بالرحلة.
        $trip = \App\Models\Shared\Trip::findOrFail($tripId);

        if (!$this->tripBelongsToParent($trip, (int) auth()->id())) {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكنك الاعتراض على رحلة لا تخص أياً من أطفالك.',
            ], 403);
        }

        $ledgerService = app(\App\Services\Shared\FinancialLedgerService::class);
        $dispute = $ledgerService->openDispute($tripId, auth()->id(), $request->reason);

        return response()->json([
            'success' => true,
            'message' => 'تم تقديم الاعتراض وتجميد مبلغ الرحلة لحين مراجعة الإدارة.',
            'data'    => $dispute,
        ], 201);
    }
}
