<?php

namespace App\Http\Controllers\Api\Shared;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\Shared\InvoiceResource;
use App\Services\Shared\FinancialService;
use Illuminate\Http\JsonResponse;

class InvoiceController extends Controller
{
    protected FinancialService $financialService;

    public function __construct(FinancialService $financialService)
    {
        $this->financialService = $financialService;
    }

    public function index(): JsonResponse
    {
        $user = auth()->user();

        if ($user->admin) {
            $invoices = $this->financialService->getAllInvoices(
                request()->only(['status', 'type', 'driver_id'])
            );
        } elseif ($user->driver) {
            $invoices = $this->financialService->getDriverInvoices(
                $user->driver->id,
                request()->only(['status'])
            );
        } else {
            $parent = $user->parentProfile ?? $user->parent;
            $parentUserId = $parent?->user_id ?? $user->id;

            $invoices = $this->financialService->getParentInvoices(
                $parentUserId,
                request()->only(['status'])
            );
        }

        return response()->json([
            'success'    => true,
            'data'       => InvoiceResource::collection($invoices),
            'pagination' => [
                'current_page' => $invoices->currentPage(),
                'last_page'    => $invoices->lastPage(),
                'total'        => $invoices->total(),
                'per_page'     => $invoices->perPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $invoice = $this->financialService->getInvoiceById($id);

        $user = auth()->user();
        $hasAccess = false;

        if ($user->driver && $invoice->driver_id === $user->driver->id) {
            $hasAccess = true;
        }

        $parentProfile = $user->parentProfile ?? $user->parent;
        if ($parentProfile) {
            $parentUserId = $parentProfile->user_id ?? $user->id;
            if ($invoice->parent_id === $parentUserId || $invoice->parent_id === $parentProfile->id) {
                $hasAccess = true;
            }
        }

        if ($user->admin) {
            $hasAccess = true;
        }

        if (!$hasAccess) {
            return response()->json([
                'success' => false,
                'message' => 'لا تملك صلاحية الوصول إلى هذه الفاتورة.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data'    => new InvoiceResource($invoice),
        ]);
    }
}
