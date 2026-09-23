<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\ExecuteTicketSettlementRequest;
use App\Http\Resources\Api\Shared\SupportTicketResource;
use App\Models\Admin\Admin;
use App\Models\Shared\SupportTicket;
use App\Services\Admin\SupportTicketAdminService;
use App\Services\Admin\SupportTicketSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    protected SupportTicketAdminService $adminService;
    protected SupportTicketSettlementService $settlementService;

    public function __construct(SupportTicketAdminService $adminService, SupportTicketSettlementService $settlementService)
    {
        $this->adminService = $adminService;
        $this->settlementService = $settlementService;
    }

    protected function currentAdmin(): Admin
    {
        // Admin موديل proxy فوق جدول users (id = id)، لا عمود user_id
        return Admin::find(auth()->id()) ?? Admin::firstOrFail();
    }

    protected function paginatedResponse($tickets): JsonResponse
    {
        return response()->json([
            'success'    => true,
            'data'       => SupportTicketResource::collection($tickets),
            'pagination' => [
                'current_page' => $tickets->currentPage(),
                'last_page'    => $tickets->lastPage(),
                'total'        => $tickets->total(),
                'per_page'     => $tickets->perPage(),
            ],
        ]);
    }

    /** قائمة شاملة بكافة تذاكر الدعم الفني للأدمن مع الفلترة */
    public function index(Request $request): JsonResponse
    {
        return $this->paginatedResponse(
            $this->adminService->getAllTickets($request->only(['status', 'category', 'scope']))
        );
    }

    /** قائمة تذاكر مشرف التشغيل والطوارئ (عامة، تقنية، سائق/رحلات، ولي أمر/طفل) */
    public function operationsIndex(Request $request): JsonResponse
    {
        return $this->paginatedResponse(
            $this->adminService->getQueue(SupportTicket::SCOPE_OPERATIONS, $request->only(['status', 'category']))
        );
    }

    /** قائمة تذاكر المشرف المالي (مالية مباشرة + محوَّلة من التشغيل) */
    public function financialIndex(Request $request): JsonResponse
    {
        return $this->paginatedResponse(
            $this->adminService->getQueue(SupportTicket::SCOPE_FINANCIAL, $request->only(['status', 'category']))
        );
    }

    /** حل التذكرة أو تحديث حالتها مع كتابة توضيح الحل (شن حل المشرف) */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status'          => 'required|string|in:open,in_progress,resolved,closed,rejected',
            'resolution_note' => 'nullable|string|max:2000',
        ]);

        $ticket = $this->adminService->updateStatus(
            $id,
            $this->currentAdmin(),
            $request->input('status'),
            $request->input('resolution_note')
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تحديث حالة التذكرة وتوثيق الملاحظات بنجاح.',
            'data'    => new SupportTicketResource($ticket),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $ticket = $this->adminService->getDetail($id);

        return response()->json([
            'success' => true,
            'data'    => new SupportTicketResource($ticket),
        ]);
    }

    public function reply(Request $request, int $id): JsonResponse
    {
        $request->validate(['message' => 'required|string|min:1|max:5000']);

        $message = $this->adminService->reply($id, $this->currentAdmin(), $request->input('message'));

        return response()->json([
            'success' => true,
            'data'    => [
                'id'         => $message->id,
                'message'    => $message->message,
                'created_at' => $message->created_at?->format('Y-m-d H:i:s'),
            ],
        ], 201);
    }

    /** تطبيق عقوبة تشغيلية: إخفاء سائق من البحث / منع ولي أمر من حجز رحلات جديدة */
    public function applyPenalty(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'penalty_action' => 'required|string|in:hide_driver_from_search,block_parent_booking',
        ]);

        $ticket = $this->adminService->applyPenalty($id, $this->currentAdmin(), $request->input('penalty_action'));

        return response()->json([
            'success' => true,
            'message' => 'تم تطبيق العقوبة التشغيلية بنجاح.',
            'data'    => new SupportTicketResource($ticket),
        ]);
    }

    /** نقل التذكرة إلى القسم المالي مع ملاحظة توضح السبب */
    public function transferToFinancial(Request $request, int $id): JsonResponse
    {
        $request->validate(['note' => 'required|string|min:5|max:2000']);

        $ticket = $this->adminService->transferToFinancial($id, $this->currentAdmin(), $request->input('note'));

        return response()->json([
            'success' => true,
            'message' => 'تم نقل التذكرة إلى القسم المالي.',
            'data'    => new SupportTicketResource($ticket),
        ]);
    }

    /** تنفيذ التسوية المالية (تحويل إلى / خصم من) */
    public function executeSettlement(ExecuteTicketSettlementRequest $request, int $id): JsonResponse
    {
        $ticket = SupportTicket::findOrFail($id);

        $updated = $this->settlementService->executeSettlement(
            $ticket,
            $this->currentAdmin(),
            $request->validated('direction'),
            $request->validated('party_role'),
            (int) $request->validated('party_user_id'),
            (float) $request->validated('amount'),
            $request->validated('note')
        );

        return response()->json([
            'success' => true,
            'message' => 'تم تنفيذ التسوية المالية بنجاح.',
            'data'    => new SupportTicketResource($updated),
        ]);
    }

    /** إغلاق التذكرة وتوثيقها بالرد النهائي */
    public function close(Request $request, int $id): JsonResponse
    {
        $request->validate(['resolution_note' => 'nullable|string|max:2000']);

        $ticket = $this->adminService->close($id, $this->currentAdmin(), $request->input('resolution_note'));

        return response()->json([
            'success' => true,
            'message' => 'تم إغلاق وتوثيق التذكرة بنجاح.',
            'data'    => new SupportTicketResource($ticket),
        ]);
    }
}
