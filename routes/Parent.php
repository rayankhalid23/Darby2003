<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Parent\ParentAuthController;
use App\Http\Controllers\Api\Parent\ChildrenController;
use App\Http\Controllers\Api\Parent\AddressController;
use App\Http\Controllers\Api\Parent\ParentSchoolController;
use App\Http\Controllers\Api\Admin\ZoneController;
use App\Http\Controllers\Api\Parent\DriverSearchController;
use App\Http\Controllers\Api\Admin\SchoolController;
use App\Http\Controllers\API\Trip\ParentChildController;
use App\Http\Controllers\Api\Parent\DriverReviewController;
use App\Http\Controllers\Api\Parent\ComplaintController;
use App\Http\Controllers\Api\Parent\WalletController;
use App\Http\Controllers\Api\Shared\InvoiceController;
use App\Http\Controllers\API\Parent\ParentSubscriptionController;

use App\Http\Controllers\Api\Trip\ParentTripController;


/*
|--------------------------------------------------------------------------
| Parent Module Routes
|--------------------------------------------------------------------------
| ملاحظة هندسية: هذا الملف ممرر عبر الـ Prefix التلقائي (api/parent) 
| من خلال ملف الإعدادات الأساسي bootstrap/app.php. لا تكرر الكلمة هنا.
*/

// 1. مسارات المصادقة العامة والتحقق (بدون توكن)
Route::post('/send-otp', [ParentAuthController::class, 'sendOtp']);
Route::post('/register', [ParentAuthController::class, 'register']);

/*
 * روابط موقّعة (Signed URLs) لتأكيد أو رفض تغيير البريد الإلكتروني لولي الأمر.
 * تفتح مباشرة من المتصفح محمية بالـ Middleware 'signed'
 */
Route::middleware('signed')->group(function () {
    Route::get('/profile/email/approve/{id}', [ParentAuthController::class, 'approveEmailChange'])
        ->name('parent.profile.email.approve');
        
    Route::get('/profile/email/reject/{id}', [ParentAuthController::class, 'rejectEmailChange'])
        ->name('parent.profile.email.reject');
});


// 2. مسارات ولي الأمر المحمية (تتطلب تسجيل دخول وتوكن Sanctum)
Route::middleware('auth:sanctum')->group(function () {
    
    // إدارة الحساب الشخصي لولي الأمر
    Route::get('/profile', [ParentAuthController::class, 'getProfile']);
    Route::post('/profile/update', [ParentAuthController::class, 'updateProfile']); 
    Route::put('/profile', [ParentAuthController::class, 'updateProfile']);
    Route::get('/profile/email-status', [ParentAuthController::class, 'checkEmailChangeStatus']);
    Route::get('/profile/email-change/status', [ParentAuthController::class, 'checkEmailChangeStatus']);
    Route::post('/profile/email-change/cancel', [ParentAuthController::class, 'cancelEmailChange']);
    Route::post('/profile/email-change/resend', [ParentAuthController::class, 'resendEmailChange']);
    
    // مسار جلب المدارس المعتمدة (الآن أصبح: api/parent/schools فوراً وبشكل صحيح)
    Route::get('schools', [SchoolController::class, 'index']);

    // --- 🔔 موديول إشعارات ولي الأمر (Notifications Module) ---
    Route::prefix('notifications')->group(function () {
        Route::get('/', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'index']);
        Route::get('/unread-count', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'unreadCount']);
        Route::post('/{id}/read', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'markAsRead']);
        Route::patch('/{id}/read', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'markAsRead']);
        Route::post('/read-all', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'markAllAsRead']);
        Route::delete('/{id}', [\App\Http\Controllers\Api\Shared\NotificationController::class, 'destroy']);
    });

    Route::prefix('children/{childId}')->group(function () {
        
        // تسجيل غياب طفل مع تحديد نوع الغياب (pickup/dropoff/both)
        Route::post('set-absence', [ParentChildController::class, 'setAbsence']);
        
        // إلغاء غياب مجدول مسبقاً للطفل (يمكن إلغاء نوع معين أو كل الغياب في الأيام المحددة)
        Route::post('cancel-absence', [ParentChildController::class, 'cancelAbsence']);
        
        // عرض قائمة الغيابات المسجلة للطفل (المستقبلية)
        Route::get('absences', [ParentChildController::class, 'getAbsences']);
        
        // جلب الأيام المتاحة لتسجيل الغياب بناءً على الاشتراك الفعال
        Route::get('available-absence-dates', [ParentChildController::class, 'getAvailableAbsenceDates']);
        
        // التأكيد اليدوي لصعود الطفل (بديل الـ QR في حال تعطل هاتف السائق أو كاميرته)
        Route::post('confirm-pickup/{tripId}', [ParentChildController::class, 'confirmManualPickup']);
        
    });
    
    // إدارة الأبناء والطلبة المضافين
    Route::prefix('children')->group(function () {
        Route::get('/has-children', [ChildrenController::class, 'checkHasChildren']);
        Route::get('/active-subscribed', [ChildrenController::class, 'getActiveSubscribedChildren']);
        Route::get('/', [ChildrenController::class, 'index']);
        Route::post('/', [ChildrenController::class, 'store']);
        Route::get('/{id}', [ChildrenController::class, 'show']);
        Route::match(['post', 'put', 'patch'], '/{id}', [ChildrenController::class, 'update']);
        Route::delete('/{id}', [ChildrenController::class, 'destroy']);
        Route::get('/{id}/subscription', [ChildrenController::class, 'getSubscription']);
    });

    // مسارات إدارة العناوين والمواقع الجغرافية لولي الأمر
    Route::prefix('addresses')->group(function () {
        Route::get('/', [AddressController::class, 'index']);
        Route::post('/', [AddressController::class, 'store']);
        // تعيين العنوان الرئيسي المفعّل (يُسجَّل قبل مسار {address} حتى لا يبتلعه)
        Route::match(['post', 'put', 'patch'], '/{address}/set-default', [AddressController::class, 'setDefault']);
        Route::match(['post', 'put', 'patch'], '/{address}', [AddressController::class, 'update'])->withTrashed();
        Route::delete('/{address}', [AddressController::class, 'destroy'])->withTrashed();
    });
    
    // اقتراح مدرسة جديدة من قبل ولي الأمر
    Route::post('/suggest-school', [ParentSchoolController::class, 'store']);
    
    // جلب المناطق المتاحة بالنظام لولي الأمر
    Route::get('zones', [ZoneController::class, 'index']);

    Route::match(['get', 'post'], '/drivers/search', [DriverSearchController::class, 'search']);
    // مسارات تقييم السائقين لولي الأمر
    Route::prefix('driver-reviews')->group(function () {
        Route::get('/driver/{driverId}', [DriverReviewController::class, 'index']);
        Route::post('/', [DriverReviewController::class, 'store']);
        Route::put('/{id}', [DriverReviewController::class, 'update']);
        Route::delete('/{id}', [DriverReviewController::class, 'destroy']);
    });

    // مسارات الشكاوى لولي الأمر
    Route::prefix('complaints')->group(function () {
        Route::get('/', [ComplaintController::class, 'index']);
        Route::get('/{id}', [ComplaintController::class, 'show']);
        Route::post('/', [ComplaintController::class, 'store']);
        Route::post('/{id}', [ComplaintController::class, 'update']);
        Route::delete('/{id}', [ComplaintController::class, 'destroy']);
        
    });
    Route::get('/driver/{driverId}/trips', [ComplaintController::class, 'driverTrips']);

    // مسارات المحفظة المالية والشحن
    Route::prefix('wallet')->group(function () {
        Route::get('/balance', [WalletController::class, 'balance']);
        Route::get('/payment-methods', [WalletController::class, 'paymentMethods']);
        Route::post('/recharge/initiate', [WalletController::class, 'initiateRecharge']);
        Route::post('/recharge/mock-pay', [WalletController::class, 'mockPay']);
        Route::post('/recharge', [WalletController::class, 'recharge']);
        Route::post('/hold-trip', [WalletController::class, 'holdTripAmount']);
    });

    // مسارات الفواتير
    Route::prefix('invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index']);
        Route::get('/{id}', [InvoiceController::class, 'show']);
    });

    // التحقق من وجود اشتراك لولي الأمر مع سائق معين ?driver_id=
    Route::get('/subscriptions/check', [ParentSubscriptionController::class, 'checkSubscription']);

    // شاشات العرض والتتبع للرحلات (الرحلة الكيان الأساسي)
    Route::get('/trips/active', [ParentTripController::class, 'getActiveTrips']);
    Route::get('/trips/active/tracking', [ParentTripController::class, 'getBulkActiveTracking']);
    Route::get('/trips/upcoming', [ParentTripController::class, 'getUpcomingTrips']);
    Route::get('/trips/history', [ParentTripController::class, 'getTripHistory']);
    
    Route::get('/trips/{tripId}', [ParentTripController::class, 'getTripDetails']);
    Route::get('/trips/{tripId}/track', [ParentTripController::class, 'getLiveTracking']);
    Route::get('/trips/{tripId}/timeline', [ParentTripController::class, 'getTripTimeline']);
    Route::post('/trips/{tripId}/dispute', [WalletController::class, 'openDispute']);
    Route::get('/trips/{tripId}/children/{childId}/status', [ParentTripController::class, 'getChildTripStatus']);
    Route::get('/trips/{tripId}/children/{childId}/progress', [ParentTripController::class, 'getChildTripProgress']);

    Route::get('/children/{childId}/trips', [ParentTripController::class, 'getChildTripsOverview']);

    // العمليات والإجراءات (الموجودة سابقاً)
    Route::post('/children/{childId}/absence', [ParentChildController::class, 'setAbsence']);
    Route::delete('/children/{childId}/absence', [ParentChildController::class, 'cancelAbsence']);
    Route::get('/children/{childId}/absences', [ParentChildController::class, 'getAbsences']);
    Route::get('/children/{childId}/available-absence-dates', [ParentChildController::class, 'getAvailableAbsenceDates']);
    Route::post('/trips/{tripId}/children/{childId}/manual-pickup', [ParentChildController::class, 'confirmManualPickup']);

    // -------------------------------------------------------------
    // 📍 طلب تغيير موقع الاستلام/التسليم لطفل ضمن اشتراك نشط
    // -------------------------------------------------------------
    Route::prefix('location-change-requests')->group(function () {
        Route::get('/options', [App\Http\Controllers\Api\Parent\LocationChangeController::class, 'options']);
        Route::post('/preview', [App\Http\Controllers\Api\Parent\LocationChangeController::class, 'preview']);
        Route::get('/', [App\Http\Controllers\Api\Parent\LocationChangeController::class, 'index']);
        Route::post('/', [App\Http\Controllers\Api\Parent\LocationChangeController::class, 'store']);
    });

    // -------------------------------------------------------------
    // 🕓 الرد على طلبات التأكيد اليدوي لرحلات سابقة لم يوثّقها التطبيق
    // -------------------------------------------------------------
    // ⚠️ كانت هذه المجموعة استُبدلت بالخطأ بمجموعة "الدعم الفني" أدناه بدل أن
    // تُضاف بجانبها، فأصبح كل طلب تأكيد يدوي من ولي الأمر يرجع 404.
    Route::prefix('trip-manual-confirmations')->group(function () {
        Route::get('/', [App\Http\Controllers\Api\Parent\TripManualConfirmationController::class, 'index']);
        Route::post('/{id}/respond', [App\Http\Controllers\Api\Parent\TripManualConfirmationController::class, 'respond']);
    });

    // -------------------------------------------------------------
    // 🎫 مسارات الدعم الفني وتذاكر المشاكل لولي الأمر
    // -------------------------------------------------------------
    Route::prefix('support-tickets')->group(function () {
        Route::get('/financial-history', [\App\Http\Controllers\Api\Parent\SupportTicketController::class, 'financialHistory']);
        Route::get('/driver-trips', [\App\Http\Controllers\Api\Parent\SupportTicketController::class, 'driverTrips']);
        Route::get('/', [\App\Http\Controllers\Api\Parent\SupportTicketController::class, 'index']);
        Route::post('/', [\App\Http\Controllers\Api\Parent\SupportTicketController::class, 'store']);
        Route::get('/{id}', [\App\Http\Controllers\Api\Parent\SupportTicketController::class, 'show']);
        Route::post('/{id}/reply', [\App\Http\Controllers\Api\Parent\SupportTicketController::class, 'reply']);
    });

});