<?php

namespace App\Http\Controllers\Api\Driver;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Driver\WithdrawalRequest as WithdrawalFormRequest;
use App\Models\Shared\WithdrawalRequest;
use App\Services\Driver\WithdrawalService;
use Illuminate\Http\JsonResponse;

class WithdrawalController extends Controller
{
    protected WithdrawalService $withdrawalService;

    public function __construct(WithdrawalService $withdrawalService)
    {
        $this->withdrawalService = $withdrawalService;
    }

    public function index(): JsonResponse
    {
        $driver = auth()->user()->driver;

        if (!$driver) {
            return response()->json(['success' => false, 'message' => 'ليس لديك صلاحية.'], 403);
        }

        $requests = $this->withdrawalService->getDriverWithdrawals(
            $driver->id,
            request()->only(['status'])
        );

        return response()->json([
            'success'    => true,
            'data'       => $requests,
            'pagination' => [
                'current_page' => $requests->currentPage(),
                'last_page'    => $requests->lastPage(),
                'total'        => $requests->total(),
            ],
        ]);
    }

    public function store(WithdrawalFormRequest $request): JsonResponse
    {
        $driver = auth()->user()->driver;

        if (!$driver) {
            return response()->json(['success' => false, 'message' => 'ليس لديك صلاحية.'], 403);
        }

        $withdrawal = $this->withdrawalService->requestWithdrawal(
            $driver->id,
            $request->validated('amount')
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تقديم طلب السحب بنجاح. بانتظار مراجعة الإدارة.',
            'data'    => $withdrawal,
        ], 201);
    }

    public function balance(): JsonResponse
    {
        $driver = auth()->user()->driver;

        if (!$driver) {
            return response()->json(['success' => false, 'message' => 'ليس لديك صلاحية.'], 403);
        }

        $balance = $driver->balance / 100;

        return response()->json([
            'success' => true,
            'data'    => [
                'balance'  => $balance,
                'currency' => 'د.ل',
            ],
        ]);
    }

    /**
     * ملخّص مالي للسائق: الرصيد المتاح فعلياً للسحب، والمبلغ العالق حالياً في
     * طلب سحب بانتظار قرار الأدمن، وإجمالي ما يملكه في المنظومة (الاثنان معاً).
     */
    public function summary(): JsonResponse
    {
        $driver = auth()->user()->driver;

        if (!$driver) {
            return response()->json(['success' => false, 'message' => 'ليس لديك صلاحية.'], 403);
        }

        $availableDinar = $driver->balance / 100;

        $pendingRequest = WithdrawalRequest::where('driver_id', $driver->id)
            ->where('status', 'pending')
            ->latest()
            ->first();

        $pendingAmount = $pendingRequest ? (float) $pendingRequest->amount : 0.0;

        return response()->json([
            'success' => true,
            'data'    => [
                'available_balance' => round($availableDinar, 2),
                'pending_withdrawal' => round($pendingAmount, 2),
                'total_owned'       => round($availableDinar + $pendingAmount, 2),
                'has_pending_request' => (bool) $pendingRequest,
                'pending_request_id'  => $pendingRequest?->id,
                'currency' => 'د.ل',
            ],
        ]);
    }
}
