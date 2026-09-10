<?php

namespace App\Services\Parent;

use App\Models\Shared\RechargeRequest;
use App\Models\Shared\PaymentMethod;
use App\Models\Parent\ParentModel;
use App\Models\Shared\Invoice;
use App\Models\Shared\MasterEscrowVault;
use App\Models\User;
use App\Services\Notification\NotificationService;
use App\Services\Shared\FinancialLedgerService;
use Illuminate\Support\Facades\DB;
// ⚠️ كان هذا الاستيراد ناقصاً بينما تستخدم completeRecharge() و failRecharge()
// الاسم المجرّد Log داخل كتل catch، فيُحلّ إلى App\Services\Parent\Log ويُسقط
// الطلب بخطأ قاتل في اللحظة التي يُفترض أن يلتقط فيها الخطأ الأصلي.
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletRechargeService
{
    protected NotificationService $notificationService;
    protected FinancialLedgerService $ledgerService;

    public function __construct(NotificationService $notificationService, FinancialLedgerService $ledgerService)
    {
        $this->notificationService = $notificationService;
        $this->ledgerService       = $ledgerService;
    }

    /**
     * جلب طرق الدفع المفعلة المتاحة لأولياء الأمور
     */
    public function getPaymentMethods()
    {
        $methods = PaymentMethod::active()
            ->forParents()
            ->orderBy('sort_order')
            ->get();

        if ($methods->isEmpty()) {
            return $this->getFallbackPaymentMethods();
        }

        return $methods;
    }

    /**
     * بدء عملية شحن محفظة ولي الأمر وإنشاء جلسة الدفع الوهمية
     */
    public function initiateRecharge(int $userId, float $amount, $paymentMethodIdentifier, ?string $referenceNumber = null): array
    {
        $method = null;
        if (is_numeric($paymentMethodIdentifier)) {
            $method = PaymentMethod::find($paymentMethodIdentifier);
        } else {
            $method = PaymentMethod::where('code', $paymentMethodIdentifier)->first();
        }

        // ⚠️ لا يكفي إخفاء الوسيلة المعطّلة من getPaymentMethods(): كان أي عميل يعرف
        // id/code وسيلة عطّلها الأدمن (توكن قديم مخزّن بالتطبيق، أو تجربة يدوية)
        // يقدر يبدأ بها جلسة شحن وينفّذها كأن شيئاً لم يتغيّر. تعطيل الأدمن يجب أن
        // يمنع الاستخدام فعلياً لا أن يخفي الخيار من القائمة فقط.
        if ($method && !$method->is_active) {
            throw ValidationException::withMessages([
                'payment_method' => ['طريقة الدفع المحددة غير مفعلة حالياً.'],
            ]);
        }

        $minAmount = $method ? (float) $method->min_amount : 1.0;
        $maxAmount = $method ? (float) $method->max_amount : 50000.0;

        if ($amount < $minAmount) {
            throw ValidationException::withMessages([
                'amount' => ["الحد الأدنى للشحن هو {$minAmount} د.ل."],
            ]);
        }

        if ($amount > $maxAmount) {
            throw ValidationException::withMessages([
                'amount' => ["الحد الأقصى للشحن هو {$maxAmount} د.ل."],
            ]);
        }

        $methodCode = $method ? $method->code : (string) $paymentMethodIdentifier;
        $methodName = $method ? $method->name_ar : $methodCode;

        $transactionRef = 'TXN-' . strtoupper(Str::random(6)) . '-' . time();
        $sessionToken   = 'MOCK_SESS_' . Str::random(32);

        $recharge = RechargeRequest::create([
            'parent_id'         => $userId,
            'payment_method_id' => $method?->id,
            'payment_method'    => $methodCode,
            'amount'            => $amount,
            'reference_number'  => $referenceNumber,
            'transaction_ref'   => $transactionRef,
            'session_token'     => $sessionToken,
            'status'            => 'pending',
            'notes'             => 'طلب شحن عبر ' . $methodName,
        ]);

        return [
            'recharge_id'       => $recharge->id,
            'transaction_ref'   => $transactionRef,
            'session_token'     => $sessionToken,
            'amount'            => (float) $amount,
            'currency'          => 'د.ل',
            'payment_method'    => [
                'id'              => $method?->id,
                'code'            => $methodCode,
                'name'            => $methodName,
                'processing_type' => $method?->processing_type ?? 'instant_simulation',
            ],
            'mock_gateway_url'  => url("/api/parent/wallet/recharge/mock-pay?token={$sessionToken}"),
            'expires_in_minutes'=> 30,
        ];
    }

    /**
     * تنفيذ محاكاة بوابة الدفع وتحديث رصيد ولي الأمر فوراً
     */
    public function processMockPayment(string $sessionToken, array $mockCardData = []): array
    {
        return DB::transaction(function () use ($sessionToken, $mockCardData) {
            $recharge = RechargeRequest::where('session_token', $sessionToken)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (!$recharge) {
                throw ValidationException::withMessages([
                    'session_token' => ['جلسة الدفع غير صالحة أو منتهية الصلاحية أو تم سدادها مسبقاً.'],
                ]);
            }

            // جلب حساب ولي الأمر مباشرة من جدول المستخدمين
            $parent = User::find($recharge->parent_id);
            if (!$parent) {
                throw new \Exception("لم يتم العثور على حساب ولي الأمر برقم: {$recharge->parent_id}");
            }

            // تحويل المبلغ إلى قروش وإيداعه في المحفظة
            $amountCents = (int) round($recharge->amount * 100);
            $balanceBefore = (int) ($parent->balance ?? 0);
            $parent->deposit($amountCents);

            // ⚠️ لا يُزاد حوض الأمانات هنا. المال المشحون يجلس في محفظة ولي الأمر
            // ولم يُحجز مقابل أي خدمة بعد؛ حوض الأمانات يرتفع فقط لحظة قبول السائق
            // للطلب. زيادته هنا كانت تعني احتساب نفس المبلغ مرتين — مرة عند الشحن
            // ومرة عند الحجز — فيستحيل على فحص السلامة المالية أن يتوازن أبداً.

            // تسجيل القيد في السجل المالي المزدوج — جزء من نفس المعاملة: شحن بلا
            // قيد يعني مالاً دخل النظام دون أثر محاسبي يمكن تدقيقه.
            $this->ledgerService->recordLedgerEntry(
                'payment_gateway_clearing',
                FinancialLedgerService::parentAccount($parent),
                $amountCents,
                'parent_deposit',
                $balanceBefore,
                (int) ($parent->balance ?? 0),
                "RECHARGE-PRNT-{$recharge->id}",
                ['recharge_id' => $recharge->id, 'transaction_ref' => $recharge->transaction_ref]
            );

            // إنشاء فاتورة/إيصال مالي تلقائي
            $invoiceNumber = 'INV-' . date('Y') . '-' . str_pad((string) $recharge->id, 6, '0', STR_PAD_LEFT);
            $invoice = Invoice::create([
                'invoice_number' => $invoiceNumber,
                'parent_id'      => $recharge->parent_id,
                'amount'         => $recharge->amount,
                'type'           => 'receipt',
                'status'         => 'paid',
                'due_date'       => now()->toDateString(),
                'payment_method' => $recharge->payment_method,
                'paid_at'        => now(),
                'details'        => [
                    'type'            => 'wallet_topup',
                    'transaction_ref' => $recharge->transaction_ref,
                    'card_last4'      => substr($mockCardData['card_number'] ?? '4242', -4),
                    'gateway'         => 'Mock Gateway Simulator',
                ],
            ]);

            // تحديث سجل الشحن
            $recharge->update([
                'status'          => 'completed',
                'completed_at'    => now(),
                'gateway_payload' => array_merge($mockCardData, [
                    'gateway_status' => 'APPROVED',
                    'simulated_at'   => now()->toIso8601String(),
                    'invoice_id'     => $invoice->id,
                ]),
            ]);

            // إرسال إشعار لحظي لولي الأمر
            $user = User::find($recharge->parent_id);
            if ($user) {
                $formattedAmount = number_format($recharge->amount, 2);
                $newBalance = number_format(($parent->balance ?? 0) / 100, 2);
                $this->notificationService->sendToUser($user, 'recharge_completed', [
                    'title'   => '💳 تم شحن المحفظة بنجاح',
                    'message' => "تم شحن محفظتك بمبلغ {$formattedAmount} د.ل بنجاح. رصيدك الحالي: {$newBalance} د.ل",
                    'entity_id' => (string) $recharge->id,
                ]);
            }

            return [
                'status'           => 'success',
                'message'          => 'تم الدفع بنجاح وإيداع المبلغ في المحفظة فوراً.',
                'transaction_ref'  => $recharge->transaction_ref,
                'amount'           => (float) $recharge->amount,
                'currency'         => 'د.ل',
                'current_balance'  => (float) round($parent->balance / 100, 2),
                'invoice'          => [
                    'id'             => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'paid_at'        => $invoice->paid_at?->format('Y-m-d H:i:s'),
                ],
            ];
        });
    }

    /**
     * إكمال الشحن يدوياً من الأدمن (Legacy support)
     */
    public function completeRecharge(int $rechargeId, int $adminId): RechargeRequest
    {
        return DB::transaction(function () use ($rechargeId, $adminId) {
            $request = RechargeRequest::where('id', $rechargeId)
                ->where('status', 'pending')
                ->firstOrFail();

            $parent = User::find($request->parent_id);
            if (!$parent) {
                throw new \Exception("لم يتم العثور على حساب ولي الأمر برقم: {$request->parent_id}");
            }

            $amountCents = (int) round($request->amount * 100);
            $balanceBefore = (int) ($parent->balance ?? 0);
            $parent->deposit($amountCents);

            // هذا المسار كان يودع المال بلا أي قيد في دفتر الأستاذ، فلا يظهر
            // الشحن اليدوي في أي تقرير ولا يمكن تدقيقه لاحقاً.
            $this->ledgerService->recordLedgerEntry(
                'manual_admin_clearing',
                FinancialLedgerService::parentAccount($parent),
                $amountCents,
                'parent_deposit',
                $balanceBefore,
                (int) ($parent->balance ?? 0),
                "RECHARGE-MANUAL-{$request->id}",
                ['recharge_id' => $request->id, 'admin_id' => $adminId]
            );

            $request->update([
                'status'       => 'completed',
                'admin_id'     => $adminId,
                'completed_at' => now(),
            ]);

            // إرسال إشعار فوري لولي الأمر
            try {
                $user = User::find($request->parent_id);
                if ($user) {
                    $formattedAmount = number_format($request->amount, 2);
                    $newBalance = number_format(($parent->balance ?? 0) / 100, 2);
                    $this->notificationService->sendToUser($user, 'recharge_completed', [
                        'title'       => '💳 تم تأكيد شحن المحفظة',
                        'message'     => "تمت مراجعة وتأكيد طلب الشحن بمبلغ ({$formattedAmount} د.ل) بنجاح. رصيدك الحالي: {$newBalance} د.ل",
                        'amount'      => $request->amount,
                        'entity_type' => 'wallet',
                        'entity_id'   => (string) $request->id,
                        'screen'      => 'WALLET',
                    ]);
                }
            } catch (\Throwable $e) {
                Log::warning("فشل إرسال إشعار تأكيد الشحن لولي الأمر: " . $e->getMessage());
            }

            return $request->fresh();
        });
    }

    public function failRecharge(int $rechargeId, int $adminId, string $reason): RechargeRequest
    {
        $request = RechargeRequest::where('id', $rechargeId)
            ->where('status', 'pending')
            ->firstOrFail();

        $request->update([
            'status'   => 'failed',
            'admin_id' => $adminId,
            'notes'    => $reason,
        ]);

        // إرسال إشعار رفض الشحن لولي الأمر
        try {
            $user = User::find($request->parent_id);
            if ($user) {
                $formattedAmount = number_format($request->amount, 2);
                $this->notificationService->sendToUser($user, 'recharge_rejected', [
                    'title'       => '⚠️ رفض طلب شحن المحفظة',
                    'message'     => "تم رفض طلب شحن المحفظة بمبلغ ({$formattedAmount} د.ل). السبب: {$reason}",
                    'amount'      => $request->amount,
                    'entity_type' => 'wallet',
                    'entity_id'   => (string) $request->id,
                    'screen'      => 'WALLET',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("فشل إرسال إشعار رفض الشحن لولي الأمر: " . $e->getMessage());
        }

        return $request->fresh();
    }

    private function getFallbackPaymentMethods(): array
    {
        // ملاحظة: أُزيلت بيانات الحسابات البنكية الوهمية (رقم حساب/IBAN تجريبي)
        // التي كانت مضمّنة هنا لأنها كانت ستُعرض لأولياء الأمور كوسائل دفع حقيقية.
        // مصدر الحقيقة الوحيد لوسائل الدفع هو جدول payment_methods في قاعدة البيانات؛
        // وفي حال عدم وجود أي وسيلة مفعّلة نُعيد قائمة فارغة بدلاً من بيانات مزيّفة.
        return [];
    }
}
