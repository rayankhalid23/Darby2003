<?php

namespace App\Http\Resources\Api\Admin;

use App\Http\Controllers\Api\Admin\AdminAvatarController;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Cache;

class AdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = ($this->resource instanceof \App\Models\User) ? $this->resource : ($this->user ?? $this->resource);
        $userId = (int) ($user->id ?? $this->id ?? 0);

        // طلب تغيير بريد معلّق (إن وُجد) حتى تعرض الواجهة شارة "بانتظار التأكيد"
        $pendingEmail = $user
            ? (Cache::get("admin_email_change_{$userId}")['new_email'] ?? null)
            : null;

        return [
            'id'           => $this->id,
            'user_id'      => $userId,
            'full_name'    => $user->full_name ?? null,
            'email'        => $user->email ?? null,
            'phone_number' => $user->phone_number ?? null,
            // يمر عبر مسار لارافيل ليحصل على ترويسات CORS التي يحتاجها Flutter Web
            'avatar_url'   => AdminAvatarController::urlFor($user->avatar_url ?? null),
            'is_active'    => (bool) ($user->is_active ?? false),
            'role_id'            => $user->role_id ?? null,
            'role_key'           => $user?->role?->name ?? 'supervisor',
            'role_name'          => $user?->role?->display_name ?? (((int) ($user->role_id ?? 0) === 1) ? 'مدير النظام العام' : 'مشرف'),
            'permissions'        => method_exists($user, 'getAllPermissions') ? $user->getAllPermissions() : [],
            'custom_permissions' => $user?->custom_permissions ?? [],
            'created_by'         => $user->created_by ?? null,
            'creator_name'       => $user->creator->full_name ?? null,
            'created_at'         => optional($user)->created_at?->toDateTimeString(),
            'last_login_at'      => optional($user)->last_login_at?->toDateTimeString(),

            // حالة تغيير البريد الإلكتروني المعلّق
            'email_change_pending' => $pendingEmail !== null,
            'pending_new_email'    => $pendingEmail,
        ];
    }
}
