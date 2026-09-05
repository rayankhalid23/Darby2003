<?php

namespace App\Http\Resources\Api\Parent;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ParentResource extends JsonResource
{
    /**
     * تحويل كائن ولي الأمر إلى مصفوفة JSON متوافقة مع التعديل الجزئي والعرض الاحترافي
     */
    public function toArray(Request $request): array
    {
        // 1. حماية قصوى: إذا كان الكائن الممرر فارغاً تماماً، نرجع مصفوفة فارغة فوراً لتجنب انهيار السيرفر
        if (!$this->resource) {
            return [];
        }

        // 2. التحقق الذكي والدقيق لتحديد كائن المستخدم (User) وكائن الملف الشخصي (Parent Profile)
        $user = null;
        $parentProfile = null;

        if ($this->resource instanceof \App\Models\User) {
            // إذا كان الكائن الأساسي هو المستخدم (User)
            $user = $this->resource;
            
            // نجلب الملف الشخصي بدعم ديناميكي لكل مسميات العلاقات المتوقعة في Laravel
            $parentProfile = $this->parentProfile ?? $this->parent ?? $this->profile ?? null;
        } else {
            // إذا كان الكائن الأساسي هو موديل الملف الشخصي لولي الأمر نفسه
            $parentProfile = $this->resource;
            $user = $this->user ?? null;
        }

        // --- استخلاص رابط الصورة وحل مشكلة النصوص الفارغة بشكل هندسي ---
        $rawAvatar = null;
        if ($user && !empty(trim($user->avatar_url))) {
            $rawAvatar = $user->avatar_url;
        } elseif ($parentProfile && !empty(trim($parentProfile->avatar_url))) {
            $rawAvatar = $parentProfile->avatar_url;
        }

        // 3. بناء المصفوفة مع وضع قيم بديلة (Fallbacks) ذكية لكل حقل لتفادي أي خطأ
        return [
            'id_user'              => (int) ($user?->id ?? $parentProfile?->user_id ?? 0),
            'full_name'            => $user?->full_name ?? $parentProfile?->full_name ?? $this->full_name ?? '',
            'email'                => $user?->email ?? $parentProfile?->email ?? $this->email ?? '',
            'phone_number'         => $user?->phone_number ?? $parentProfile?->phone_number ?? $this->phone_number ?? '',
            'alternative_phone'    => $user?->alternative_phone ?? $parentProfile?->alternative_phone ?? $this->alternative_phone ?? null, 
            'role'                 => 'parent',
            'is_active'            => (bool) ($user?->is_active ?? $parentProfile?->is_active ?? $this->is_active ?? false),
            
            // جلب حالة الحساب الموثوق من جدول users المطبع
            'is_trusted'           => (bool) ($user?->is_trusted ?? $parentProfile?->is_trusted ?? true),
            
            // الحل الجذري لعرض رابط الصورة الصحيح أو null للفرونت إند لمنع التشوه
            'avatar_url'           => $rawAvatar ? asset($rawAvatar) : null,

            // التنبيه الخاص بتعديل البريد الإلكتروني المعلق
            'email_change_pending' => (bool) ($user?->email_change_pending ?? $parentProfile?->email_change_pending ?? $this->email_change_pending ?? false),
            
            // عرض الـ Access Token بشكل آمن ومحمي عند توفره فقط
            'access_token'         => $this->when(
                (isset($this->access_token) || ($user && isset($user->access_token))), 
                $this->access_token ?? $user?->access_token
            ),
        ];
    }
}