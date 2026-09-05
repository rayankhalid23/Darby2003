<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\LoginRequest;
use App\Http\Resources\Api\UserResource;
use App\Http\Resources\Api\Parent\ParentResource; 
use App\Http\Resources\Api\Driver\DriverResource;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use App\Traits\AuthenticatableTrait;
use Throwable;

class LoginController extends Controller
{
    use AuthenticatableTrait;

    public function login(LoginRequest $request)
    {
        try {
            // 1. التحقق من المستخدم
            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json(['status' => false, 'message' => 'البريد الإلكتروني غير مسجل في النظام.'], 404);
            }

            // استخدام password_hash بناءً على قاعدة بياناتك
            if (!Hash::check($request->password, $user->password_hash)) {
                return response()->json(['status' => false, 'message' => 'كلمة المرور غير صحيحة.'], 401);
            }

            // التحقق من التفعيل
            if (!$user->is_active) {
                return response()->json(['status' => false, 'message' => 'حسابك غير مفعل.'], 403);
            }

            // 2. تحديث بيانات الدخول
            $user->update([
                'last_login_at' => Carbon::now(),
                'is_active' => 1
            ]);

            // 3. إدارة التوكن
            if (in_array((int) $user->role_id, [1, 2])) {
                $user->tokens()->delete(); 
            }

            // 4. تحديد مدة الصلاحية الذكية
            $expiresAt = match ((int) $user->role_id) {
                1, 2 => now()->addWeek(),   // 1 و 2 لمدة أسبوع
                3, 4 => now()->addYear(),   // 3 و 4 لمدة سنة
                default => now()->addDay(), // الحالة الافتراضية
            };

            // 5. إنشاء التوكن
            $deviceName = $request->device_name ?? 'mobile_device';
            $token = $user->createToken($deviceName, ['*'], $expiresAt)->plainTextToken;

            // 6. تسجيل الجهاز فقط إذا تم إرسال fcm_token حقيقي (فريد عالمياً في الجدول)؛
            // التسجيل الرسمي يتم عبر POST /api/user/device-token
            if ($request->filled('fcm_token')) {
                DB::table('user_devices')->updateOrInsert(
                    ['fcm_token' => $request->fcm_token],
                    [
                        'user_id'        => $user->id,
                        'device_id'      => $request->device_id ?? null,
                        'device_name'    => $deviceName,
                        'platform'       => $request->platform ?? 'unknown',
                        'is_active'      => true,
                        'last_active_at' => Carbon::now(),
                        'created_at'     => Carbon::now(),
                        'updated_at'     => Carbon::now(),
                    ]
                );
            }

            // تحديد مسمى الدور (Role Name) بناءً على المعرف في قاعدة البيانات
            $roleName = match ((int) $user->role_id) {
                1 => 'مدير النظام',
                2 => 'مشرف',
                3 => 'ولي أمر',
                4 => 'سائق',
                default => 'مستخدم',
            };

            // 7. تحويل كائن المستخدم إلى الـ Resource المناسب لدوره
            $userResourceData = match ((int) $user->role_id) {
                3 => new ParentResource($user), 
                4 => new DriverResource($user),
                1, 2, 5, 6, 7, 8 => new \App\Http\Resources\Api\Admin\AdminResource($user),
                default => new UserResource($user), 
            };

            return response()->json([
                'status' => true, 
                'message' => "مرحباً {$user->full_name}، تم تسجيل الدخول بنجاح!", 
                'access_token' => $token,
                'token_type' => 'Bearer',
                'role_name' => $roleName, 
                'user' => $userResourceData 
            ], 200);

        } catch (Throwable $e) {
            // تسجيل الخطأ بالتفصيل المُمِل في ملف storage/logs/laravel.log
            Log::error('Login Controller Exception: ' . $e->getMessage(), [
                'exception_class' => get_class($e),
                'file'            => $e->getFile(),
                'line'            => $e->getLine(),
                'request_inputs'  => $request->only(['email', 'device_name', 'platform']),
                'ip_address'      => $request->ip(),
                'user_agent'      => $request->userAgent(),
                'trace'           => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status'  => false, 
                'message' => 'حدث عطل تقني أثناء تسجيل الدخول.',
                'debug'   => config('app.debug') ? [
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                    'trace'   => explode("\n", $e->getTraceAsString())
                ] : null
            ], 500);
        }
    }
}