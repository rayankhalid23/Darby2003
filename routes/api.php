<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\PasswordController;
use App\Http\Controllers\Api\Shared\NotificationController as SharedNotificationController;
use App\Http\Controllers\Api\Shared\MediaController;
use App\Http\Controllers\Api\Shared\GeographySearchController;
use App\Http\Controllers\Api\Shared\TermsController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// 🗺️ البحث في التقسيمات الجغرافية (بلدية، بلدية فرعية، منطقة)
Route::match(['get', 'post'], '/geography/search', [GeographySearchController::class, 'search'])
    ->name('api.geography.search');

// 🖼️ تقديم ملفات وثائق/مركبات السائقين عبر لارافيل لضمان ترويسات CORS (عام، بلا توكن —
// وسوم <img> والمكتبات المستخدمة في تحميل الصور لا ترسل Authorization)
Route::get('/media/{path}', [MediaController::class, 'show'])->where('path', '.*');

// 📜 الشروط والأحكام — عرض عام بلا توكن (تُستدعى من شاشة التسجيل قبل أي مصادقة)
Route::prefix('terms')->group(function () {
    Route::get('/current', [TermsController::class, 'current'])->name('api.terms.current');
    Route::get('/{id}', [TermsController::class, 'show'])->whereNumber('id')->name('api.terms.show');
});

// مسار تسجيل الدخول
Route::post('/auth/login', [LoginController::class, 'login']);

// مسارات استعادة كلمة المرور (عامة - خارج الميدلوير)
// مسارات استعادة كلمة المرور (عامة)
Route::prefix('auth/password')->group(function () {
    Route::post('/send-otp', [PasswordController::class, 'sendResetOtp']); // 1. إرسال الكود
    Route::post('/verify-otp', [PasswordController::class, 'verifyOtp']);  // 2. التحقق من الكود (الجديدة)
    Route::post('/reset', [PasswordController::class, 'resetPassword']);   // 3. تغيير كلمة المرور
});

// المسارات المحمية بالتوكن
Route::middleware('auth:sanctum')->group(function () {
    
    Route::post('/auth/logout', [LoginController::class, 'logout']);

    // 📜 حالة موافقة المستخدم الحالي + تسجيل الموافقة — مستقلتان تماماً عن التسجيل،
    // يستدعيهما الفرونت في اللحظة التي يقرر فيها عرض شاشة الشروط (بعد إنشاء حساب
    // ولي الأمر، أو بعد تفعيل الأدمن لحساب السائق)
    Route::get('/terms/status', [TermsController::class, 'status'])->name('api.terms.status');
    Route::post('/terms/accept', [TermsController::class, 'accept'])->name('api.terms.accept');

    Route::get('/user/profile', function (Request $request) {
        return response()->json([
            'status' => true,
            'user' => $request->user()
        ]);
    });

    // مسارات الإشعارات الفورية وإدارة التوكنات (Notifications & Device Tokens)
    Route::prefix('notifications')->group(function () {
        Route::get('/', [SharedNotificationController::class, 'index']);
        Route::get('/unread-count', [SharedNotificationController::class, 'unreadCount']);
        Route::post('/{id}/read', [SharedNotificationController::class, 'markAsRead']);
        Route::patch('/{id}/read', [SharedNotificationController::class, 'markAsRead']);
        Route::post('/read-all', [SharedNotificationController::class, 'markAllAsRead']);
        Route::delete('/{id}', [SharedNotificationController::class, 'destroy']);
    });

    Route::post('/user/device-token', [SharedNotificationController::class, 'storeDeviceToken']);
    Route::delete('/user/device-token', [SharedNotificationController::class, 'removeDeviceToken']);
    Route::post('/user/device-token/logout-all', [SharedNotificationController::class, 'logoutAllDevices']);
});