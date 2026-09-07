<?php

namespace App\Services\Shared;

use App\Models\Driver\Driver;
use App\Models\Parent\ParentModel;
use App\Models\Shared\ActiveSubscription;
use App\Models\Shared\Invoice;
use App\Models\Shared\SupportTicket;
use App\Models\Shared\SupportTicketMessage;
use App\Models\Shared\Trip;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Bavix\Wallet\Models\Transaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * طبقة مشتركة لإنشاء تذاكر الدعم الفني وإدارتها من جهة صاحب التذكرة
 * (ولي الأمر أو السائق) — القوائم المساعدة (الفواتير/المعاملات/الرحلات)
 * تُبنى على العلاقات الجاهزة في المشروع دون إعادة تنفيذ أي استعلام موجود.
 */
class SupportTicketService
{
    protected NotificationService $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    // ============================================================
    // إنشاء التذكرة
    // ============================================================

    /**
     * @param UploadedFile[] $files
     */
    public function createTicket(User $user, string $role, array $data, array $files = []): SupportTicket
    {
        $referenceableType = null;
        $referenceableId = null;
        $targetRole = null;
        $targetUserId = null;

        if ($data['category'] === SupportTicket::CATEGORY_FINANCIAL && !empty($data['financial_reference_type']) && !empty($data['financial_reference_id'])) {
            [$referenceableType, $referenceableId] = $this->resolveFinancialReference(
                $user,
                $role,
                $data['financial_reference_type'],
                (int) $data['financial_reference_id']
            );
        }

        if (!empty($data['target_user_id'])) {
            $targetUserId = (int) $data['target_user_id'];
            if ($targetUserId === $user->id) {
                throw ValidationException::withMessages([
                    'target_user_id' => ['لا يمكن تقديم تذكرة ضد نفسك.'],
                ]);
            }
            $targetRole = $role === 'parent' ? 'driver' : 'parent';
        }

        if (!empty($data['trip_id'])) {
            $referenceableType = Trip::class;
            $referenceableId = (int) $data['trip_id'];
        }

        $ticket = DB::transaction(function () use ($user, $role, $data, $files, $referenceableType, $referenceableId, $targetRole, $targetUserId) {
            $ticket = SupportTicket::create([
                'user_id'             => $user->id,
                'creator_role'        => $role,
                'category'            => $data['category'],
                'referenceable_type'  => $referenceableType,
                'referenceable_id'    => $referenceableId,
                'target_role'         => $targetRole,
                'target_user_id'      => $targetUserId,
                'description'         => $data['description'],
                'attachments'         => $this->storeAttachments($files),
                'status'              => SupportTicket::STATUS_OPEN,
                'scope'               => $data['category'] === SupportTicket::CATEGORY_FINANCIAL
                    ? SupportTicket::SCOPE_FINANCIAL
                    : SupportTicket::SCOPE_OPERATIONS,
            ]);

            return $ticket;
        });

        $this->notifyAdminsOfNewTicket($ticket);

        if ($targetUserId) {
            $this->notifyTargetUserOfTicket($ticket);
        }

        return $ticket->fresh(['user', 'targetUser', 'referenceable']);
    }

    protected function notifyTargetUserOfTicket(SupportTicket $ticket): void
    {
        try {
            $target = $ticket->targetUser ?? User::find($ticket->target_user_id);
            if ($target) {
                $this->notificationService->sendToUser($target, 'support_ticket_created', [
                    'title'       => 'تنبيه: تذكرة دعم فني جديدة 🎫',
                    'message'     => "وردت تذكرة دعم فني برقم #{$ticket->id} تخصك، يرجى مراجعتها وتوضيح الأمر.",
                    'entity_id'   => (string) $ticket->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning("فشل إرسال إشعار الطرف المعني عن التذكرة #{$ticket->id}: " . $e->getMessage());
        }
    }

    protected function resolveFinancialReference(User $user, string $role, string $type, int $id): array
    {
        if ($type === 'invoice') {
            $invoice = Invoice::find($id);
            $belongsToUser = $invoice && (
                (int) $invoice->parent_id === $user->id
                || ($role === 'driver' && $user->driver && (int) $invoice->driver_id === $user->driver->id)
            );

            if (!$belongsToUser) {
                throw ValidationException::withMessages([
                    'financial_reference_id' => ['الفاتورة المحددة غير موجودة أو لا تخصك.'],
                ]);
            }

            return [Invoice::class, $invoice->id];
        }

        // type === 'transaction'
        $holder = $role === 'parent' ? $user->parentProfile : $user->driver;
        if (!$holder) {
            throw ValidationException::withMessages([
                'financial_reference_id' => ['تعذر تحديد محفظتك المالية.'],
            ]);
        }

        $transaction = Transaction::where('id', $id)
            ->where('payable_type', $holder::class)
            ->where('payable_id', $holder->id)
            ->first();

        if (!$transaction) {
            throw ValidationException::withMessages([
                'financial_reference_id' => ['المعاملة المالية المحددة غير موجودة أو لا تخصك.'],
            ]);
        }

        return [Transaction::class, $transaction->id];
    }

    /**
     * @param UploadedFile[] $files
     */
    protected function storeAttachments(array $files): ?array
    {
        if (empty($files)) {
            return null;
        }

        $paths = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $paths[] = $file->store('support_tickets/attachments', 'public');
            }
        }

        return $paths ?: null;
    }

    protected function notifyAdminsOfNewTicket(SupportTicket $ticket): void
    {
        try {
            $admins = User::whereIn('role_id', [1, 2])->get();
            $this->notificationService->sendToUsers($admins, 'support_ticket_created', [
                'title'       => 'تذكرة دعم فني جديدة 🎫',
                'message'     => 'تم فتح تذكرة دعم فني جديدة وتحتاج إلى مراجعة.',
                'entity_id'   => (string) $ticket->id,
            ], withPush: false);
        } catch (\Throwable $e) {
            Log::warning("فشل إرسال إشعار الأدمن عن التذكرة الجديدة #{$ticket->id}: " . $e->getMessage());
        }
    }

    // ============================================================
    // القوائم والتفاصيل (من جهة صاحب التذكرة)
    // ============================================================

    public function getUserTickets(User $user, string $role, array $filters = []): LengthAwarePaginator
    {
        $query = SupportTicket::with(['user', 'targetUser', 'referenceable']);

        if (!empty($filters['incoming']) || (!empty($filters['type']) && $filters['type'] === 'incoming')) {
            $query->where('target_user_id', $user->id);
        } else {
            $query->where('user_id', $user->id)
                  ->where('creator_role', $role);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        return $query->latest()->paginate(15);
    }

    public function getUserTicketDetail(User $user, string $role, int $ticketId): SupportTicket
    {
        return SupportTicket::with(['user', 'targetUser', 'referenceable', 'messages.admin.user'])
            ->where(function ($q) use ($user, $role) {
                $q->where(function ($sub) use ($user, $role) {
                    $sub->where('user_id', $user->id)
                        ->where('creator_role', $role);
                })->orWhere('target_user_id', $user->id);
            })
            ->findOrFail($ticketId);
    }

    public function addUserMessage(User $user, string $role, int $ticketId, string $message): SupportTicketMessage
    {
        $ticket = SupportTicket::where(function ($q) use ($user, $role) {
            $q->where(function ($sub) use ($user, $role) {
                $sub->where('user_id', $user->id)
                    ->where('creator_role', $role);
            })->orWhere('target_user_id', $user->id);
        })->findOrFail($ticketId);

        if ($ticket->status === SupportTicket::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'ticket' => ['هذه التذكرة مغلقة ولا يمكن إضافة ردود جديدة عليها.'],
            ]);
        }

        $ticketMessage = SupportTicketMessage::create([
            'ticket_id' => $ticket->id,
            'admin_id'  => null,
            'is_admin'  => false,
            'message'   => $message,
        ]);

        $this->notifyAdminsOfNewReply($ticket, $user);

        return $ticketMessage;
    }

    public function closeTicketByUser(User $user, string $role, int $ticketId): SupportTicket
    {
        $ticket = SupportTicket::where('user_id', $user->id)
            ->where('creator_role', $role)
            ->findOrFail($ticketId);

        if ($ticket->status === SupportTicket::STATUS_CLOSED) {
            throw ValidationException::withMessages([
                'ticket' => ['هذه التذكرة مغلقة بالفعل.'],
            ]);
        }

        $ticket->update([
            'status'    => SupportTicket::STATUS_CLOSED,
            'closed_at' => now(),
        ]);

        return $ticket->fresh();
    }

    protected function notifyAdminsOfNewReply(SupportTicket $ticket, User $sender): void
    {
        try {
            $admins = User::whereIn('role_id', [1, 2])->get();
            $this->notificationService->sendToUsers($admins, 'support_ticket_reply', [
                'title'       => 'رد جديد على تذكرة دعم فني 💬',
                'message'     => "أضاف {$sender->full_name} رداً على التذكرة #{$ticket->id}.",
                'entity_id'   => (string) $ticket->id,
            ], withPush: false);
        } catch (\Throwable $e) {
            Log::warning("فشل إرسال إشعار الرد الجديد للتذكرة #{$ticket->id}: " . $e->getMessage());
        }
    }

    // ============================================================
    // القوائم المساعدة: الفواتير/المعاملات/الرحلات (لبناء واجهة إنشاء التذكرة)
    // ============================================================

    public function financialHistoryForParent(User $user): array
    {
        $invoices = Invoice::where('parent_id', $user->id)->latest()->limit(50)->get();

        $transactions = collect();
        if ($user->parentProfile) {
            $transactions = $user->parentProfile->transactions()->latest()->limit(50)->get();
        }

        return ['invoices' => $invoices, 'transactions' => $transactions];
    }

    public function financialHistoryForDriver(User $user): array
    {
        $transactions = collect();
        if ($user->driver) {
            $transactions = $user->driver->transactions()->latest()->limit(50)->get();
        }

        return ['invoices' => collect(), 'transactions' => $transactions];
    }

    /**
     * رحلات ولي الأمر مع السائقين (لاختيار رحلة عند تذكرة "سائق/رحلات")
     */
    public function tripsForParent(User $user): \Illuminate\Support\Collection
    {
        $parent = ParentModel::where('user_id', $user->id)->first();
        if (!$parent) {
            return collect();
        }

        $routeIds = ActiveSubscription::forParent($user->id)->pluck('route_id')->unique();

        return Trip::with('driver.user')
            ->whereIn('route_id', $routeIds)
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    /**
     * رحلات السائق مع أولياء الأمور/الأطفال (لاختيار رحلة عند تذكرة "ولي أمر/طفل")
     */
    public function tripsForDriver(User $user): \Illuminate\Support\Collection
    {
        $driver = Driver::where('user_id', $user->id)->first();
        if (!$driver) {
            return collect();
        }

        return Trip::with('activeSubscriptions.parent', 'activeSubscriptions.child')
            ->where('driver_id', $driver->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }
}
