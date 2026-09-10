<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\ProcessWithdrawalRequest;
use App\Http\Resources\Api\Shared\InvoiceResource;
use App\Services\Admin\ComplaintService;
use App\Services\Driver\WithdrawalService;
use App\Services\Parent\WalletRechargeService;
use App\Services\Shared\FinancialService;
use App\Models\Shared\RechargeRequest;
use Illuminate\Http\JsonResponse;

class FinancialController extends Controller
{
    protected FinancialService $financialService;
    protected WithdrawalService $withdrawalService;
    protected WalletRechargeService $rechargeService;

    public function __construct(
        FinancialService $financialService,
        WithdrawalService $withdrawalService,
        WalletRechargeService $rechargeService
    ) {
        $this->financialService = $financialService;
        $this->withdrawalService = $withdrawalService;
        $this->rechargeService = $rechargeService;
    }

    public function invoices(): JsonResponse
    {
        $perPage = (int) request('per_page', 15);
        $query = \App\Models\Shared\Invoice::with(['subscriptionRequest', 'parent', 'driver.user'])
            ->when(request('status'), fn($q, $v) => $q->where('status', $v))
            ->when(request('type'), fn($q, $v) => $q->where('type', $v))
            ->when(request('search'), function ($q, $v) {
                $q->where('invoice_number', 'like', "%{$v}%");
            })
            ->when(request('date_from'), fn($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when(request('date_to'), fn($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest();

        $invoices = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => InvoiceResource::collection($invoices),
            'meta'    => [
                'current_page' => $invoices->currentPage(),
                'last_page'    => $invoices->lastPage(),
                'per_page'     => $invoices->perPage(),
                'total'        => $invoices->total(),
            ],
        ]);
    }

    public function invoiceDetail(int $id): JsonResponse
    {
        $invoice = $this->financialService->getInvoiceById($id);

        return response()->json([
            'success' => true,
            'data'    => new InvoiceResource($invoice),
        ]);
    }

    public function withdrawals(): JsonResponse
    {
        $perPage = (int) request('per_page', 15);
        $query = \App\Models\Shared\WithdrawalRequest::with(['driver.user'])
            ->when(request('status'), fn($q, $v) => $q->where('status', $v))
            ->when(request('search'), function ($q, $v) {
                $q->whereHas('driver.user', fn($sq) => $sq->where('full_name', 'like', "%{$v}%"));
            })
            ->when(request('date_from'), fn($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when(request('date_to'), fn($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest();

        $requests = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $requests->items(),
            'meta'    => [
                'current_page' => $requests->currentPage(),
                'last_page'    => $requests->lastPage(),
                'per_page'     => $requests->perPage(),
                'total'        => $requests->total(),
            ],
        ]);
    }

    public function withdrawalDetail(int $id): JsonResponse
    {
        $withdrawal = \App\Models\Shared\WithdrawalRequest::with(['driver.user', 'admin.user'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'                     => $withdrawal->id,
                'driver_id'              => $withdrawal->driver_id,
                'driver_name'            => $withdrawal->driver?->user?->full_name,
                'driver_phone'           => $withdrawal->driver?->user?->phone_number,
                'amount'                 => (float) $withdrawal->amount,
                'wallet_balance_at_req'  => (float) $withdrawal->wallet_balance_at_request,
                'status'                 => $withdrawal->status,
                'payment_method_details' => $withdrawal->payment_method_details,
                'rejection_reason'       => $withdrawal->rejection_reason,
                'created_at'             => $withdrawal->created_at,
                'processed_at'           => $withdrawal->processed_at,
                'admin_name'             => $withdrawal->admin?->user?->full_name,
            ],
        ]);
    }

    public function processWithdrawal(int $id, ProcessWithdrawalRequest $request): JsonResponse
    {
        // ⚠️ كان `?? Admin::first()` يُسجّل الإجراء باسم "أول أدمن بالجدول" حرفياً
        // كلما رجعت `auth()->user()->admin` قيمة فارغة (بدل رفض الطلب أو تسجيله
        // باسم صاحبه الحقيقي) — فيُنسَب اعتماد/رفض طلب سحب لموظف لم يفعله إطلاقاً.
        // هذا المسار محمي أصلاً بصلاحية `financial.manage_withdrawals` بالراوتر،
        // فمعرّف المستخدم الحالي هو المصدر الصحيح الوحيد لهوية من نفّذ الإجراء.
        $adminId = auth()->id();

        $withdrawalReq = \App\Models\Shared\WithdrawalRequest::findOrFail($id);

        // 🔴 Idempotency Guard
        if ($withdrawalReq->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تنفيذ العملية.',
                'errors'  => ['action' => ['طلب السحب تم معالجته مسبقاً وغير معلق حالياً.']]
            ], 422);
        }

        $action = $request->validated('action');

        if ($action === 'approve') {
            $withdrawal = $this->withdrawalService->approveWithdrawal($id, $adminId);
            $message = 'تمت الموافقة على طلب السحب بنجاح.';
        } else {
            $withdrawal = $this->withdrawalService->rejectWithdrawal(
                $id,
                $adminId,
                $request->validated('rejection_reason')
            );
            $message = 'تم رفض طلب السحب.';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $withdrawal->fresh(['driver.user']),
        ]);
    }

    public function rechargeRequests(): JsonResponse
    {
        $perPage = (int) request('per_page', 15);
        $query = RechargeRequest::with(['parent'])
            ->when(request('status'), fn($q, $v) => $q->where('status', $v))
            ->when(request('search'), function ($q, $v) {
                $q->where('reference_number', 'like', "%{$v}%")
                  ->orWhereHas('parent', fn($sq) => $sq->where('full_name', 'like', "%{$v}%"));
            })
            ->when(request('date_from'), fn($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when(request('date_to'), fn($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest();

        $requests = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $requests->items(),
            'meta'    => [
                'current_page' => $requests->currentPage(),
                'last_page'    => $requests->lastPage(),
                'per_page'     => $requests->perPage(),
                'total'        => $requests->total(),
            ],
        ]);
    }

    public function rechargeDetail(int $id): JsonResponse
    {
        $recharge = RechargeRequest::with(['parent', 'admin.user'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => [
                'id'               => $recharge->id,
                'parent_name'      => $recharge->parent?->full_name,
                'parent_phone'     => $recharge->parent?->phone_number,
                'amount'           => (float) $recharge->amount,
                'payment_method'   => $recharge->payment_method,
                'reference_number' => $recharge->reference_number,
                'status'           => $recharge->status,
                // ⚠️ 'failure_reason' و'processed_at' لم يكونا موجودين أصلاً بجدول
                // recharge_requests (تحقق فعلي عبر SHOW COLUMNS) فكانا يرجعان null
                // دائماً مهما كان القرار. سبب الرفض الحقيقي يُخزَّن بعمود notes عبر
                // WalletRechargeService::failRecharge()، ووقت المعالجة الفعلي هو
                // updated_at لحظة تغيّر status (completed_at لا يُملأ إلا للنجاح).
                'notes'            => $recharge->notes,
                'created_at'       => $recharge->created_at,
                'completed_at'     => $recharge->completed_at,
                'processed_at'     => $recharge->status === 'pending' ? null : $recharge->updated_at,
                'admin_name'       => $recharge->admin?->full_name,
            ],
        ]);
    }

    public function processRecharge(int $id): JsonResponse
    {
        // ⚠️ نفس خلل processWithdrawal: `?? Admin::first()` كان يُسنِد الإجراء
        // زوراً لأول أدمن بالجدول بدل منفّذه الحقيقي. الراوتر يحمي هذا المسار
        // بصلاحية `financial.manage_recharges` أصلاً.
        $adminId = auth()->id();

        $rechargeReq = RechargeRequest::findOrFail($id);

        // 🔴 Idempotency Guard
        if ($rechargeReq->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'لا يمكن تنفيذ العملية.',
                'errors'  => ['action' => ['طلب الشحن تم معالجته مسبقاً وليس معلقاً.']]
            ], 422);
        }

        $action = request('action', 'complete');

        if ($action === 'complete') {
            $recharge = $this->rechargeService->completeRecharge($id, $adminId);
            $message = 'تم تأكيد عملية الشحن وإضافة الرصيد للمحفظة.';
        } else {
            $recharge = $this->rechargeService->failRecharge(
                $id,
                $adminId,
                request('reason', 'تم رفض طلب الشحن.')
            );
            $message = 'تم رفض طلب الشحن.';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'data'    => $recharge->fresh(['parent']),
        ]);
    }
}
