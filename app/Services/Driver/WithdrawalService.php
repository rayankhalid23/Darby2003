<?php

namespace App\Services\Driver;

use App\Models\Shared\MasterEscrowVault;
use App\Models\Shared\WithdrawalRequest;
use App\Models\Driver\Driver;
use App\Services\Notification\NotificationService;
use App\Services\Shared\FinancialLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class WithdrawalService
{
    protected ?NotificationService $notificationService;
    protected FinancialLedgerService $ledgerService;

    public function __construct(?NotificationService $notificationService = null, ?FinancialLedgerService $ledgerService = null)
    {
        $this->notificationService = $notificationService ?? app(NotificationService::class);
        $this->ledgerService       = $ledgerService ?? app(FinancialLedgerService::class);
    }

    public function requestWithdrawal(int $driverId, float $amount): WithdrawalRequest
    {
        $driver = Driver::findOrFail($driverId);

        $balance = $driver->balance / 100;

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['المبلغ يجب أن يكون أكبر من صفر.'],
            ]);
        }

        if ($amount > $balance) {
            throw ValidationException::withMessages([
                'amount' => ["رصيد محفظتك غير كافٍ. الرصيد المتاح: {$balance} د.ل"],
            ]);
        }

        // الحد الأدنى يُقرأ من ثابت النظام المالي بدل رقم مكتوب هنا: كان الثابت
        // يقول 50 د.ل والرقم الصريح هنا يقول 5، فيختلف ما يطبّقه النظام عمّا يوثّقه.
        $minWithdrawalDinar = FinancialLedgerService::MIN_WITHDRAWAL_AMOUNT / 100;
        if ($amount < $minWithdrawalDinar) {
            throw ValidationException::withMessages([
                'amount' => ["الحد الأدنى للسحب هو {$minWithdrawalDinar} د.ل."],
            ]);
        }

        $pendingExists = WithdrawalRequest::where('driver_id', $driverId)
            ->where('status', 'pending')
            ->exists();

        if ($pendingExists) {
            throw ValidationException::withMessages([
                'amount' => ['لديك طلب سحب قيد المراجعة بالفعل. انتظر حتى تتم معالجته.'],
            ]);
        }

        // (int) round(...) إلزامي: المحفظة تتعامل بالقروش كأعداد صحيحة، و 91.55 * 100
        // تساوي 9154.999... في الفاصلة العائمة فتُقتطع لقرش ناقص أو تُرفض من المحفظة.
        $amountCents = (int) round($amount * 100);

        // السحب وإنشاء الطلب في معاملة واحدة: بدونها قد تُخصم المحفظة
        // ثم يفشل إنشاء سجل الطلب فيضيع المبلغ بلا أثر يمكن تتبّعه أو استرجاعه.
        return DB::transaction(function () use ($driver, $driverId, $amount, $amountCents, $balance) {
            $balanceBefore = (int) $driver->balance;
            $driver->wallet->withdraw($amountCents);

            // ⚠️ كان المبلغ يخرج من المحفظة دون أي تعديل على الخزينة ودون قيد في
            // دفتر الأستاذ، فينحرف حوض أرصدة السائقين عن المحافظ ويختفي المال من
            // المحاسبة تماماً بين لحظة الطلب ولحظة قرار الأدمن. الآن ينتقل إلى
            // حوض السحوبات المعلّقة الذي يحمله في تلك الفترة.
            $vault = MasterEscrowVault::getVault();
            $vault->decrement('driver_available_pool', $amountCents);
            $vault->increment('pending_withdrawal_pool', $amountCents);

            $this->ledgerService->recordLedgerEntry(
                FinancialLedgerService::driverAccount($driver),
                'pending_withdrawal_pool',
                $amountCents,
                'withdrawal_requested',
                $balanceBefore,
                (int) $driver->fresh()->balance,
                "WITHDRAW-REQ-{$driverId}-" . now()->timestamp,
                ['driver_id' => $driverId]
            );

            return WithdrawalRequest::create([
                'driver_id'                 => $driverId,
                'amount'                    => $amount,
                'wallet_balance_at_request' => $balance,
                'status'                    => 'pending',
            ]);
        });
    }

    public function approveWithdrawal(int $withdrawalId, int $adminId): WithdrawalRequest
    {
        $request = DB::transaction(function () use ($withdrawalId, $adminId) {
            $request = WithdrawalRequest::where('id', $withdrawalId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->firstOrFail();

            $amountCents = (int) round((float) $request->amount * 100);

            // الموافقة تعني خروج المال من المنظومة فعلياً إلى حساب السائق البنكي:
            // يُفرَّغ حوض السحوبات المعلّقة ويُسجَّل القيد الختامي للحركة.
            MasterEscrowVault::getVault()->decrement('pending_withdrawal_pool', $amountCents);

            $this->ledgerService->recordLedgerEntry(
                'pending_withdrawal_pool',
                'external_bank_payout',
                $amountCents,
                'withdrawal_paid',
                $amountCents,
                0,
                "WITHDRAW-PAID-{$request->id}",
                ['withdrawal_id' => $request->id, 'admin_id' => $adminId]
            );

            $request->update([
                'status'       => 'approved',
                'admin_id'     => $adminId,
                'processed_at' => now(),
            ]);

            return $request;
        });

        $fresh = $request->fresh(['driver.user']);

        try {
            $driverUser = $fresh->driver?->user;
            if ($driverUser && $this->notificationService) {
                $formattedAmount = number_format($fresh->amount, 2);
                $this->notificationService->sendToUser($driverUser, 'withdrawal_approved', [
                    'title'       => '💵 تمت الموافقة على طلب السحب',
                    'message'     => "تمت معالجة طلب سحب مبلغ ({$formattedAmount} د.ل) بنجاح وتحويله إلى حسابك.",
                    'amount'      => $fresh->amount,
                    'entity_type' => 'withdrawal',
                    'entity_id'   => (string) $fresh->id,
                    'screen'      => 'WALLET',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("فشل إرسال إشعار الموافقة على السحب للسائق: " . $e->getMessage());
        }

        return $fresh;
    }

    public function rejectWithdrawal(int $withdrawalId, int $adminId, string $reason): WithdrawalRequest
    {
        $request = DB::transaction(function () use ($withdrawalId, $adminId, $reason) {
            $request = WithdrawalRequest::where('id', $withdrawalId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->firstOrFail();

            $driver = Driver::findOrFail($request->driver_id);
            // amount مُعرَّف كـ decimal:2 فيصل كنص؛ التحويل الصريح يمنع أي انحراف بالقروش عند الإرجاع.
            $amountCents   = (int) round((float) $request->amount * 100);
            $balanceBefore = (int) $driver->balance;
            $driver->deposit($amountCents);

            // المبلغ يعود من حوض السحوبات المعلّقة إلى حوض أرصدة السائقين المتاحة.
            $vault = MasterEscrowVault::getVault();
            $vault->decrement('pending_withdrawal_pool', $amountCents);
            $vault->increment('driver_available_pool', $amountCents);

            $this->ledgerService->recordLedgerEntry(
                'pending_withdrawal_pool',
                FinancialLedgerService::driverAccount($driver),
                $amountCents,
                'withdrawal_rejected',
                $balanceBefore,
                (int) $driver->fresh()->balance,
                "WITHDRAW-REJECT-{$request->id}",
                ['withdrawal_id' => $request->id, 'admin_id' => $adminId, 'reason' => $reason]
            );

            $request->update([
                'status'           => 'rejected',
                'admin_id'         => $adminId,
                'rejection_reason' => $reason,
                'processed_at'     => now(),
            ]);

            return $request;
        });

        $fresh = $request->fresh(['driver.user']);

        try {
            $driverUser = $fresh->driver?->user;
            if ($driverUser && $this->notificationService) {
                $formattedAmount = number_format($fresh->amount, 2);
                $this->notificationService->sendToUser($driverUser, 'withdrawal_rejected', [
                    'title'       => '⚠️ رفض طلب سحب الرصيد',
                    'message'     => "تم رفض طلب سحب مبلغ ({$formattedAmount} د.ل) وإعادة المبلغ إلى محفظتك. السبب: {$reason}",
                    'amount'      => $fresh->amount,
                    'entity_type' => 'withdrawal',
                    'entity_id'   => (string) $fresh->id,
                    'screen'      => 'WALLET',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("فشل إرسال إشعار رفض السحب للسائق: " . $e->getMessage());
        }

        return $fresh;
    }

    public function getDriverWithdrawals(int $driverId, array $filters = [])
    {
        $query = WithdrawalRequest::where('driver_id', $driverId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->latest()->paginate(15);
    }

    public function getAllWithdrawals(array $filters = [])
    {
        $query = WithdrawalRequest::with(['driver.user']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->latest()->paginate(15);
    }
}
