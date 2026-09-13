<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\ReviewComplaintRequest;
use App\Http\Resources\Api\Parent\ComplaintResource;
use App\Services\Admin\ComplaintService;
use Illuminate\Http\JsonResponse;

class ComplaintController extends Controller
{
    protected ComplaintService $complaintService;

    public function __construct(ComplaintService $complaintService)
    {
        $this->complaintService = $complaintService;
    }

    public function index(): JsonResponse
    {
        $complaints = $this->complaintService->getAllComplaints(
            request()->only(['status', 'type', 'filter', 'driver_id'])
        );

        return response()->json([
            'status'     => true,
            'data'       => ComplaintResource::collection($complaints),
            'pagination' => [
                'current_page' => $complaints->currentPage(),
                'last_page'    => $complaints->lastPage(),
                'total'        => $complaints->total(),
                'per_page'     => $complaints->perPage(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $complaint = $this->complaintService->getComplaintDetail($id);

        return response()->json([
            'status' => true,
            'data'   => new ComplaintResource($complaint),
        ]);
    }

    public function driverComplaints(int $driverId): JsonResponse
    {
        $complaints = $this->complaintService->getDriverComplaints(
            $driverId,
            request()->only(['status'])
        );

        return response()->json([
            'status'     => true,
            'data'       => ComplaintResource::collection($complaints),
            'pagination' => [
                'current_page' => $complaints->currentPage(),
                'last_page'    => $complaints->lastPage(),
                'total'        => $complaints->total(),
                'per_page'     => $complaints->perPage(),
            ],
        ]);
    }

    public function review(ReviewComplaintRequest $request, int $id): JsonResponse
    {
        $adminId = auth()->id();
        $admin = \App\Models\Admin\Admin::find($adminId) ?? \App\Models\Admin\Admin::first();

        $action = $request->input('action') ?? (method_exists($request, 'validated') ? $request->validated('action') : null);
        $actionDetails = $request->input('action_details') ?? (method_exists($request, 'validated') ? $request->validated('action_details') : null);

        $complaint = $this->complaintService->reviewComplaint(
            $id,
            $action,
            $actionDetails,
            $admin?->id ?? 1
        );

        $actionMessages = [
            'warning'    => 'تم إرسال إنذار للسائق وحفظ القرار.',
            'suspension' => 'تم إيقاف السائق وحفظ القرار.',
            'dismiss'    => 'تم تجاهل الشكوى وحفظ القرار.',
        ];

        return response()->json([
            'status'  => true,
            'message' => $actionMessages[$action] ?? 'تمت المعالجة بنجاح.',
            'data'    => new ComplaintResource($complaint),
        ]);
    }
}
