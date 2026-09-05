<?php

namespace App\Http\Resources\Api\Driver;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Log;
use Exception;

class DriverResource extends JsonResource
{
    /**
     * تحويل كائن السائق إلى مصفوفة JSON احترافية متوافقة مع التعديل والعرض
     */
    public function toArray(Request $request): array
    {
        try {
            if (!$this->resource) {
                return [];
            }

            // 🧠 هندسة ذكية: فصل الهويات وتحديد الموديل الممرر تلقائياً
            $user = null;
            $driver = null;

            if ($this->resource instanceof \App\Models\User) {
                // إذا تم تمرير موديل المستخدم (مثلما يحدث في الـ LoginController)
                $user = $this->resource;
                $driver = $user->driver ?? $user->driverProfile ?? null; // جلب علاقة السائق
            } else {
                // إذا تم تمرير موديل السائق مباشرة (مثل عمليات الـ CRUD العادية)
                $driver = $this->resource;
                $user = $driver->user ?? null;
            }

            return [
                // 1. البيانات الشخصية والحساب
                'driver_id'                   => $driver ? (int) $driver->id : null, // معرف السائق (Driver ID)
                'user_id'           => (int) ($user?->id ?? $driver?->user_id ?? 0), // معرف المستخدم (User ID)
                'role_id'           => $user->role_id ?? null,
                'full_name'            => $user?->full_name ?? '',
                'gender'               => $user?->gender ?? $driver?->gender ?? '',
                'phone_number'         => $user?->phone_number ?? '',
                'alternative_phone'    => $user?->alternative_phone ?? null,
                'email'                => $user?->email ?? '', 
                'new_email_temporary'  => $user?->new_email_temporary ?? null,
                'email_change_pending' => (bool) ($user?->email_change_pending ?? false),
                'avatar_url'           => $user?->avatar_url ? asset($user->avatar_url) : null,
                'is_active'            => (bool) ($user?->is_active ?? false),

                // التوكن يظهر ديناميكياً فقط عند تسجيل الدخول أو التسجيل
                'access_token'         => $this->when(
                    isset($this->access_token) || isset($user->access_token), 
                    $this->access_token ?? $user?->access_token
                ),

                // 2. البيانات المهنية (تُجلب بأمان من كائن السائق المحلول)
                'national_id'          => $driver?->national_id,
                'license_number'       => $driver?->license_number,
                'license_expiry'       => $driver?->license_expiry,
                'driver_status'        => $driver?->status ?? 'Pending',
                
            
            ];

        } catch (Exception $e) {
            Log::error("DriverResource Error: " . $e->getMessage());
            return ['error' => true, 'message' => 'تعذر تنسيق بيانات السائق الشخصية.'];
        }
    }
}