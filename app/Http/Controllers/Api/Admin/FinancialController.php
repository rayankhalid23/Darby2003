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
        $admin = auth()->user()->admin ?? \App\Models\Admin\Admin::first();

        if (!$admin) {
            return response()->json([
                'success' => false,
                'message' => 'ليس لديك صلاحية لإجراء هذا العمل.',
                'errors'  => ['admin' => ['غير مصرح بالحساب الإداري.']]
            ], 403);
        }

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
            $withdrawal = $this->withdrawalService->approveWithdrawal($id, $admin->id);
            $message = 'تمت الموافقة على طلب السحب بنجاح.';
        } else {
            $withdrawal = $this->withdrawalService->rejectWithdrawal(
                $id,
                $admin->id,
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
                'failure_reason'   => $recharge->failure_reason,
                'created_at'       => $recharge->created_at,
                'processed_at'     => $recharge->processed_at,
                'admin_name'       => $recharge->admin?->user?->full_name,
            ],
        ]);
    }

    public function processRecharge(int $id): JsonResponse
    {
        $admin = auth()->user()->admin ?? \App\Models\Admin\Admin::first();

        if (!$admin) {
            return response()->json([
                'success' => false,
                'message' => 'ليس لديك صلاحية.',
                'errors'  => ['admin' => ['غير مصرح بالحساب الإداري.']]
            ], 403);
        }

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
            $recharge = $this->rechargeService->completeRecharge($id, $admin->id);
            $message = 'تم تأكيد عملية الشحن وإضافة الرصيد للمحفظة.';
        } else {
            $recharge = $this->rechargeService->failRecharge(
                $id,
                $admin->id,
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
